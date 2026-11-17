<?php

namespace App\Services\Purchasing;

use App\Enums\DocumentType;
use App\Exceptions\DomainException;
use App\Models\CashRegisterSession;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Models\SupplierPaymentAllocation;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Cash\CashSessionService;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Accounts payable: supplier bills and the payments applied to them.
 *
 *  - A bill is usually made from a goods receipt (its amount is the receipt
 *    total) or entered directly (e.g. services). Due date defaults to the
 *    supplier's payment terms.
 *  - A payment is applied to one or more open bills of the same supplier;
 *    the amounts applied must add up to the payment. Bills move open →
 *    partially_paid → paid.
 *  - Corrections: void a payment (its amounts go back on the bills) or void an
 *    unpaid bill. Nothing is deleted.
 *  - A payment taken from a cash drawer is recorded as a payout of the
 *    payer's open shift.
 */
class PayablesService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly CashSessionService $cash,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{supplier_id?: int, goods_receipt_id?: int|null, supplier_invoice_no?: string|null, bill_date: string,
     *               due_date?: string|null, total?: string|null}  $data
     */
    public function createBill(array $data, User $actor): SupplierBill
    {
        return DB::transaction(function () use ($data) {
            $receipt = null;
            if (! empty($data['goods_receipt_id'])) {
                $receipt = GoodsReceipt::query()->lockForUpdate()->findOrFail($data['goods_receipt_id']);
                if ($receipt->status !== 'posted') {
                    throw ValidationException::withMessages(['goods_receipt_id' => 'This receipt was reversed.']);
                }
                if (SupplierBill::query()->where('goods_receipt_id', $receipt->getKey())->where('status', '!=', 'void')->exists()) {
                    throw ValidationException::withMessages(['goods_receipt_id' => "A bill already exists for receipt {$receipt->number}."]);
                }
            }
            $supplier = Supplier::query()->findOrFail($receipt?->supplier_id ?? $data['supplier_id']);
            $total = $receipt ? Money::of($receipt->total) : Money::of($data['total'] ?? '0');
            if (! $total->isPositive()) {
                throw ValidationException::withMessages(['total' => 'Enter the bill amount.']);
            }
            $billDate = CarbonImmutable::parse($data['bill_date']);
            $due = ! empty($data['due_date']) ? CarbonImmutable::parse($data['due_date']) : $billDate->addDays($supplier->payment_terms_days);
            if ($due->lessThan($billDate)) {
                throw ValidationException::withMessages(['due_date' => 'The due date cannot be before the bill date.']);
            }

            $bill = new SupplierBill;
            $bill->forceFill([
                'supplier_id' => $supplier->getKey(),
                'goods_receipt_id' => $receipt?->getKey(),
                'number' => $this->numbers->next(DocumentType::SupplierBill),
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? $receipt?->supplier_invoice_no,
                'bill_date' => $billDate->toDateString(),
                'due_date' => $due->toDateString(),
                'total' => (string) $total,
                'amount_paid' => '0.00',
                'status' => 'open',
            ])->save();
            $this->audit->log('supplier_bill.created', $bill, new: ['number' => $bill->number, 'total' => (string) $total, 'supplier' => $supplier->name]);

            return $bill;
        });
    }

    public function voidBill(SupplierBill $bill, string $reason, User $actor): SupplierBill
    {
        return DB::transaction(function () use ($bill, $reason) {
            $bill = SupplierBill::query()->lockForUpdate()->findOrFail($bill->getKey());
            if ($bill->status === 'void') {
                throw new DomainException('This bill is already void.', 'BILL_VOID', 409);
            }
            if (Money::of($bill->amount_paid)->isPositive()) {
                throw new DomainException('Void the payments applied to this bill first.', 'BILL_HAS_PAYMENTS', 409);
            }
            $bill->forceFill(['status' => 'void'])->save();
            $this->audit->log('supplier_bill.voided', $bill, ['status' => 'open'], ['status' => 'void'], ['reason' => $reason]);

            return $bill;
        });
    }

    /**
     * @param  array{supplier_id: int, payment_method_id?: int|null, paid_at: string, amount: string, reference_no?: string|null,
     *               notes?: string|null, paid_from_drawer?: bool, allocations: list<array{supplier_bill_id: int, amount: string}>}  $data
     */
    public function pay(array $data, User $actor): SupplierPayment
    {
        return DB::transaction(function () use ($data, $actor) {
            $supplier = Supplier::query()->findOrFail($data['supplier_id']);
            $amount = Money::of($data['amount']);
            $applied = Money::zero();
            $bills = SupplierBill::query()->lockForUpdate()->whereIn('id', array_column($data['allocations'], 'supplier_bill_id'))->get()->keyBy('id');

            foreach (array_values($data['allocations']) as $i => $a) {
                $bill = $bills[$a['supplier_bill_id']] ?? null;
                $part = Money::of($a['amount']);
                if (! $bill || $bill->supplier_id !== $supplier->getKey() || $bill->status === 'void') {
                    throw ValidationException::withMessages(["allocations.{$i}.supplier_bill_id" => 'Choose an open bill of this supplier.']);
                }
                $open = Money::of($bill->total)->minus($bill->amount_paid);
                if (! $part->isPositive() || $part->greaterThan($open)) {
                    throw ValidationException::withMessages(["allocations.{$i}.amount" => "Bill {$bill->number} has {$open->format()} left to pay."]);
                }
                $applied = $applied->plus($part);
            }
            if (! $applied->equals($amount)) {
                throw ValidationException::withMessages(['amount' => "The amounts applied to bills ({$applied->format()}) must add up to the payment ({$amount->format()})."]);
            }

            $payment = new SupplierPayment;
            $payment->forceFill([
                'supplier_id' => $supplier->getKey(),
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'number' => $this->numbers->next(DocumentType::SupplierPayment),
                'paid_at' => $data['paid_at'],
                'amount' => (string) $amount,
                'reference_no' => $data['reference_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => 'posted',
                'created_by' => $actor->getKey(),
            ])->save();

            foreach ($data['allocations'] as $a) {
                $row = new SupplierPaymentAllocation;
                $row->forceFill(['supplier_payment_id' => $payment->getKey(), 'supplier_bill_id' => $a['supplier_bill_id'], 'amount' => (string) Money::of($a['amount'])])->save();
                $this->refresh($bills[$a['supplier_bill_id']], Money::of($a['amount']));
            }

            if (! empty($data['paid_from_drawer'])) {
                $session = CashRegisterSession::query()->where('user_id', $actor->getKey())->where('status', 'open')->first()
                    ?? throw ValidationException::withMessages(['paid_from_drawer' => 'Start a shift to pay from the cash drawer.']);
                $this->cash->recordMovement($session, 'payout', (string) $amount, Str::limit("Supplier payment {$payment->number} – {$supplier->name}", 250), (string) Str::uuid(), $actor);
            }

            $this->audit->log('supplier_payment.posted', $payment, new: ['number' => $payment->number, 'amount' => (string) $amount, 'supplier' => $supplier->name]);

            return $payment;
        });
    }

    public function voidPayment(SupplierPayment $payment, string $reason, User $actor): SupplierPayment
    {
        return DB::transaction(function () use ($payment, $reason) {
            $payment = SupplierPayment::query()->lockForUpdate()->findOrFail($payment->getKey());
            if ($payment->status === 'void') {
                throw new DomainException('This payment is already void.', 'PAYMENT_VOID', 409);
            }
            foreach ($payment->allocations()->get() as $a) {
                $bill = SupplierBill::query()->lockForUpdate()->findOrFail($a->supplier_bill_id);
                $this->refresh($bill, Money::of($a->amount)->negated());
            }
            $payment->forceFill(['status' => 'void', 'notes' => trim(($payment->notes ? $payment->notes."\n" : '')."Voided: {$reason}")])->save();
            $this->audit->log('supplier_payment.voided', $payment, ['status' => 'posted'], ['status' => 'void'], ['reason' => $reason]);

            return $payment;
        });
    }

    private function refresh(SupplierBill $bill, Money $change): void
    {
        $paid = Money::of($bill->amount_paid)->plus($change);
        $status = $paid->isZero() ? 'open' : ($paid->compareTo($bill->total) >= 0 ? 'paid' : 'partially_paid');
        $bill->forceFill(['amount_paid' => (string) $paid, 'status' => $status])->save();
    }
}

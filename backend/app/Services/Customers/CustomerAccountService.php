<?php

namespace App\Services\Customers;

use App\Enums\DocumentType;
use App\Exceptions\DomainException;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\PlanGate;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer accounts ("utang"): what a customer owes.
 *
 * customer_ledger_entries is the append-only source of truth; customers.balance
 * is a cache updated in the same transaction under a row lock. Credit sales
 * (Phase 5) will post here through charge().
 */
class CustomerAccountService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PlanGate $plan,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * Bring in what the customer already owed before using AvantPOS.
     */
    public function openingBalance(Customer $customer, string $amount, ?string $notes, User $actor): CustomerLedgerEntry
    {
        $this->assertFeature();

        return DB::transaction(function () use ($customer, $amount, $notes, $actor) {
            $customer = $this->lock($customer);
            if (CustomerLedgerEntry::query()->where('customer_id', $customer->getKey())->exists()) {
                throw new DomainException('This account already has entries. Use an adjustment to correct the balance.', 'ACCOUNT_HAS_HISTORY', 409);
            }
            $amount = Money::of($amount);
            if (! $amount->isPositive()) {
                throw ValidationException::withMessages(['amount' => 'Enter the amount owed.']);
            }

            return $this->post($customer, 'opening_balance', $amount, $actor, ['notes' => $notes]);
        });
    }

    /**
     * Money received from the customer against their balance.
     *
     * @param  array{amount: string, payment_method_id: int, reference_no?: string|null, notes?: string|null}  $data
     */
    public function recordPayment(Customer $customer, array $data, User $actor, ?int $branchId): CustomerLedgerEntry
    {
        $this->assertFeature();
        $method = PaymentMethod::query()->where('is_active', true)->find($data['payment_method_id']);
        if (! $method) {
            throw ValidationException::withMessages(['payment_method_id' => 'Choose an active payment method.']);
        }
        if ($method->requires_reference && blank($data['reference_no'] ?? null)) {
            throw ValidationException::withMessages(['reference_no' => "Enter the {$method->name} reference number."]);
        }

        return DB::transaction(function () use ($customer, $data, $actor, $branchId, $method) {
            $customer = $this->lock($customer);
            $amount = Money::of($data['amount']);
            $owed = Money::of($customer->balance);

            if (! $amount->isPositive()) {
                throw ValidationException::withMessages(['amount' => 'Enter the amount received.']);
            }
            if ($amount->greaterThan($owed)) {
                throw ValidationException::withMessages(['amount' => $owed->isPositive()
                    ? "The customer only owes {$owed->format()}."
                    : 'This customer has no balance to pay.']);
            }

            return $this->post($customer, 'payment', $amount->negated(), $actor, [
                'number' => $this->numbers->next(DocumentType::CustomerPayment),
                'payment_method_id' => $method->getKey(),
                'reference_no' => $data['reference_no'] ?? null,
                'notes' => $data['notes'] ?? null,
                'branch_id' => $branchId,
            ]);
        });
    }

    /**
     * Charge a credit sale to the customer's account (called inside the
     * checkout transaction). Enforces "allow credit" and the credit limit.
     */
    public function charge(Customer $customer, Money $amount, string $referenceType, int $referenceId, string $notes, User $actor): CustomerLedgerEntry
    {
        $this->assertFeature();
        $customer = $this->lock($customer);

        if (! $customer->is_active || ! $customer->allow_credit) {
            throw ValidationException::withMessages(['payments' => "{$customer->name} is not allowed to buy on credit."]);
        }
        $available = Money::of($customer->credit_limit)->minus($customer->balance);
        if ($amount->greaterThan($available)) {
            throw new DomainException(
                "This exceeds {$customer->name}'s credit limit. Available: ".$available->max('0')->format().'.',
                'CREDIT_LIMIT_EXCEEDED', 422, ['available' => (string) $available->max('0')],
            );
        }

        return $this->post($customer, 'credit_sale', $amount, $actor, [
            'reference_type' => $referenceType, 'reference_id' => $referenceId, 'notes' => $notes,
        ]);
    }

    /**
     * Take back a credit sale (void or refund): lowers what the customer owes.
     * Called inside the void / refund transaction.
     */
    public function credit(Customer $customer, Money $amount, string $referenceType, int $referenceId, string $notes, User $actor): CustomerLedgerEntry
    {
        $customer = $this->lock($customer);

        return $this->post($customer, 'refund', $amount->negated(), $actor, [
            'reference_type' => $referenceType, 'reference_id' => $referenceId, 'notes' => $notes,
        ]);
    }

    /**
     * Correct a balance (e.g. a mistake in the opening balance). Signed: + increases what is owed.
     */
    public function adjust(Customer $customer, string $amount, string $notes, User $actor): CustomerLedgerEntry
    {
        $this->assertFeature();

        return DB::transaction(function () use ($customer, $amount, $notes, $actor) {
            $customer = $this->lock($customer);
            $amount = Money::of($amount);
            if ($amount->isZero()) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount other than zero.']);
            }
            if (Money::of($customer->balance)->plus($amount)->isNegative()) {
                throw ValidationException::withMessages(['amount' => 'The balance cannot go below zero.']);
            }

            return $this->post($customer, 'adjustment', $amount, $actor, ['notes' => $notes]);
        });
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function post(Customer $customer, string $type, Money $amount, User $actor, array $extra = []): CustomerLedgerEntry
    {
        $before = Money::of($customer->balance);
        $after = $before->plus($amount);

        $entry = new CustomerLedgerEntry;
        $entry->forceFill([
            'customer_id' => $customer->getKey(),
            'type' => $type,
            'amount' => (string) $amount,
            'balance_after' => (string) $after,
            'user_id' => $actor->getKey(),
        ] + $extra)->save();

        $customer->forceFill(['balance' => (string) $after])->save();

        $this->audit->log("customer_account.{$type}", $customer, ['balance' => (string) $before], ['balance' => (string) $after], array_filter([
            'amount' => (string) $amount,
            'number' => $extra['number'] ?? null,
            'notes' => $extra['notes'] ?? null,
        ]));

        return $entry;
    }

    private function lock(Customer $customer): Customer
    {
        return Customer::query()->lockForUpdate()->findOrFail($customer->getKey());
    }

    private function assertFeature(): void
    {
        $this->plan->assertFeature('credit_sales', 'Customer credit');
    }
}

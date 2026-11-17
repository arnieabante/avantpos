<?php

namespace App\Services\Sales;

use App\Enums\DocumentType;
use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Models\CashRegisterSession;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleDiscount;
use App\Models\SaleItem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SupervisorOverrideService;
use App\Services\Billing\PlanGate;
use App\Services\Customers\CustomerAccountService;
use App\Services\Customers\LoyaltyService;
use App\Services\Inventory\Concerns\PostsLines;
use App\Services\Inventory\StockLedger;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Completes a POS sale.
 *
 *  1. Idempotent on the client UUID: a retried checkout returns the sale
 *     already recorded instead of charging twice.
 *  2. Requires the cashier's open shift; payments land in it.
 *  3. Prices with SaleCalculator (client amounts are never used).
 *  4. Every permission the cart needs must be held by the cashier or
 *     approved by a supervisor PIN (single-use override tokens).
 *  5. Payments: non-cash may not exceed the amount due; cash covers the rest
 *     and any excess is change.
 *  6. One DB transaction: gapless invoice number for the terminal, sale and
 *     lines, discounts (SC/PWD beneficiary encrypted), stock out FIFO/FEFO with
 *     exact COGS, payments, credit charged to the customer, points redeemed / earned.
 */
class CheckoutService
{
    use PostsLines;

    public function __construct(
        private readonly SaleCalculator $calculator,
        private readonly StockLedger $ledger,
        private readonly DocumentNumberService $numbers,
        private readonly SupervisorOverrideService $overrides,
        private readonly CustomerAccountService $accounts,
        private readonly LoyaltyService $loyalty,
        private readonly PlanGate $plan,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Price a cart without recording anything (the POS shows these figures).
     *
     * @param  array<string, mixed>  $cart
     * @return array<string, mixed>
     */
    public function quote(Branch $branch, array $cart, User $actor): array
    {
        $quote = $this->calculator->calculate($branch->getKey(), $cart);
        $quote['approvals_missing'] = array_values(array_filter($quote['approvals'], fn ($p) => ! $actor->can($p)));

        return $quote;
    }

    /**
     * @param  array<string, mixed>  $data  validated SaleRequest
     * @return array{sale: Sale, replayed: bool}
     */
    public function checkout(Branch $branch, array $data, User $actor): array
    {
        if ($existing = Sale::query()->where('client_uuid', $data['client_uuid'])->first()) {
            if ($existing->cashier_id !== $actor->getKey()) {
                throw new DomainException('This sale ID was already used by another cashier.', 'IDEMPOTENCY_CONFLICT', 409);
            }

            return ['sale' => $existing, 'replayed' => true];
        }

        $session = CashRegisterSession::query()->with('terminal')
            ->where('user_id', $actor->getKey())->where('status', 'open')->first();
        if (! $session) {
            throw new DomainException('Start your shift before selling.', 'NO_OPEN_SHIFT', 409);
        }
        if ($session->branch_id !== $branch->getKey()) {
            throw new DomainException('Your shift is open in another branch. Switch to it to sell.', 'SHIFT_IN_OTHER_BRANCH', 409);
        }

        $quote = $this->calculator->calculate($branch->getKey(), $data);
        $required = $quote['approvals'];
        $chargesAccount = PaymentMethod::query()->whereIn('id', array_column($data['payments'] ?? [], 'payment_method_id'))->where('type', 'credit')->exists();
        if ($chargesAccount) {
            $required[] = 'pos.credit_sale';
        }
        $authorizers = $this->approve($required, $data['overrides'] ?? [], $actor);
        $tenders = $this->tenders($data['payments'] ?? [], Money::of($quote['totals']['total']), $quote['customer']);

        try {
            $sale = DB::transaction(fn () => $this->record($branch, $session, $data, $quote, $tenders, $authorizers, $actor));
        } catch (QueryException $e) {
            // A concurrent retry of the same checkout won the race: return that sale.
            if (str_contains($e->getMessage(), 'client_uuid') && ($winner = Sale::query()->where('client_uuid', $data['client_uuid'])->first())) {
                return ['sale' => $winner, 'replayed' => true];
            }
            throw $e;
        }

        return ['sale' => $sale, 'replayed' => false];
    }

    /**
     * @param  array<string, mixed>  $quote
     * @param  array{rows: list<array<string, mixed>>, paid: Money, change: Money, credit: Money}  $tenders
     * @param  array<string, int>  $authorizers  permission => supervisor id
     */
    /**
     * @param  array{invoice_no?: string, sold_at?: CarbonInterface, sync_batch_id?: int, sync_status?: string,
     *               sync_issues?: list<array<string, mixed>>, pricing_version?: string|null}|null  $offline
     *               set when recording a sale made offline (see OfflineSyncService)
     */
    public function record(Branch $branch, CashRegisterSession $session, array $data, array $quote, array $tenders, array $authorizers, User $actor, ?array $offline = null): Sale
    {
        // Lock the shift so it cannot be closed while this sale is being written.
        $session = CashRegisterSession::query()->lockForUpdate()->findOrFail($session->getKey());
        if ($offline === null && ! $session->isOpen()) {
            throw new DomainException('Your shift was closed. Start a new shift to continue.', 'NO_OPEN_SHIFT', 409);
        }

        $t = $quote['totals'];
        $customer = $quote['customer'];
        $now = $offline['sold_at'] ?? now();

        $sale = new Sale;
        $sale->forceFill([
            'client_uuid' => $data['client_uuid'],
            'branch_id' => $branch->getKey(),
            'terminal_id' => $session->terminal_id,
            'cash_register_session_id' => $session->getKey(),
            'cashier_id' => $actor->getKey(),
            'customer_id' => $customer?->getKey(),
            'invoice_no' => $offline['invoice_no'] ?? $this->numbers->next(DocumentType::SalesInvoice, $session->terminal_id),
            'status' => 'completed',
            'gross_amount' => $t['gross'],
            'line_discount_total' => $t['line_discount'],
            'transaction_discount_total' => $t['transaction_discount'],
            'sc_pwd_discount_total' => $t['sc_pwd_discount'],
            'loyalty_discount' => $t['loyalty_discount'],
            'points_redeemed' => $quote['points_redeemed'],
            'vatable_sales' => $t['vatable_sales'],
            'vat_amount' => $t['vat_amount'],
            'vat_exempt_sales' => $t['vat_exempt_sales'],
            'zero_rated_sales' => $t['zero_rated_sales'],
            'total_amount' => $t['total'],
            'amount_paid' => (string) $tenders['paid'],
            'change_due' => (string) $tenders['change'],
            'notes' => $data['notes'] ?? null,
            'origin' => $offline ? 'offline' : 'online',
            'sold_at' => $now,
            'synced_at' => now(),
            'sync_batch_id' => $offline['sync_batch_id'] ?? null,
            'sync_status' => $offline['sync_status'] ?? 'ok',
            'sync_issues' => $offline['sync_issues'] ?? null,
            'pricing_version' => $offline['pricing_version'] ?? null,
        ])->save();

        /* ------------------------------------------------ lines + stock */
        $itemIds = [];
        $cogs = Money::zero();
        $lines = collect($quote['lines'])->map(fn ($l) => (object) ($l + ['id' => $l['index'], 'product_variant_id' => $l['variant']->getKey()]));

        // Stock goes out in lock order (no deadlocks between concurrent sales)...
        $lineCogs = [];
        foreach ($this->inLockOrder($lines) as $l) {
            $lineCogs[$l->index] = Money::zero();
            if ($l->product->type === 'standard' && $l->product->track_inventory) {
                $movement = $this->atLine("items.{$l->index}.qty", fn () => $this->ledger->issue($branch->getKey(), $l->variant, $l->base_qty, 'sale', [
                    'reference_type' => 'sale',
                    'reference_id' => $sale->getKey(),
                    'reason' => "Sale {$sale->invoice_no}",
                    // An offline sale already happened: the goods left, so stock may go negative (flagged for review).
                    'allow_negative' => $offline !== null || (bool) $l->product->allow_negative_stock,
                    'occurred_at' => $now,
                ]));
                $lineCogs[$l->index] = Money::of($movement->total_cost)->abs();
            }
        }

        // ...while the invoice lines keep the order they were rung up.
        foreach ($lines as $l) {
            $item = new SaleItem;
            $item->forceFill([
                'sale_id' => $sale->getKey(),
                'product_variant_id' => $l->variant->getKey(),
                'product_unit_id' => $l->unit?->getKey(),
                'product_name' => $l->product->name,
                'variant_name' => $l->variant->name,
                'sku' => $l->variant->sku,
                'unit_label' => $l->unit_label,
                'conversion_factor' => (string) $l->factor->toScale(4),
                'qty' => (string) $l->qty->toScale(3),
                'base_qty' => (string) $l->base_qty,
                'unit_price' => (string) $l->unit_price,
                'line_discount' => (string) $l->line_discount,
                'allocated_transaction_discount' => (string) $l->allocated_transaction_discount->plus($l->allocated_loyalty),
                'sc_pwd_discount' => (string) $l->sc_pwd_discount,
                'tax_class' => $l->tax_class,
                'vat_amount' => (string) $l->vat_amount,
                'line_total' => (string) $l->line_total,
                'cogs' => (string) $lineCogs[$l->index],
            ])->save();
            $itemIds[$l->index] = $item->getKey();
            $cogs = $cogs->plus($lineCogs[$l->index]);
        }

        /* ------------------------------------------------ discounts */
        $scPwd = $quote['sc_pwd'];
        foreach ($quote['discounts'] as $d) {
            $statutory = in_array($d['type'], ['senior', 'pwd'], true);
            $permission = match (true) {
                $statutory => 'pos.sc_pwd',
                $d['scope'] === 'line' => 'pos.discount_line',
                in_array($d['type'], ['manual', 'preset'], true) => 'pos.discount_transaction',
                default => null,
            };

            $row = new SaleDiscount;
            $row->forceFill([
                'sale_id' => $sale->getKey(),
                'sale_item_id' => $d['line'] !== null ? $itemIds[$d['line']] : null,
                'discount_preset_id' => $d['preset_id'],
                'type' => $d['type'],
                'scope' => $d['scope'],
                'value_type' => $d['value_type'],
                'value' => $d['value'],
                'amount' => (string) $d['amount'],
                'beneficiary_name' => $statutory ? $scPwd['name'] : null,
                'beneficiary_id_no' => $statutory ? $scPwd['id_no'] : null,
                'beneficiary_tin' => $statutory ? ($scPwd['tin'] ?? null) : null,
                'authorized_by' => $authorizers['pos.discount_override_limit'] ?? ($permission ? ($authorizers[$permission] ?? null) : null),
            ])->save();
        }

        /* ------------------------------------------------ payments */
        foreach ($tenders['rows'] as $p) {
            $payment = new Payment;
            $payment->forceFill([
                'client_uuid' => (string) Str::uuid(),
                'sale_id' => $sale->getKey(),
                'payment_method_id' => $p['method']->getKey(),
                'cash_register_session_id' => $session->getKey(),
                'direction' => 'in',
                'amount' => (string) $p['amount'],
                'tendered' => $p['tendered'] !== null ? (string) $p['tendered'] : null,
                'change' => (string) $p['change'],
                'reference_no' => $p['reference_no'],
                'status' => 'confirmed',
                'confirmed_by' => $actor->getKey(),
                'confirmed_at' => $now,
                'paid_at' => $now,
            ])->save();
        }

        if ($tenders['credit']->isPositive()) {
            $this->accounts->charge($customer, $tenders['credit'], 'sale', $sale->getKey(), "Sale {$sale->invoice_no}", $actor);
        }

        /* ------------------------------------------------ loyalty */
        $earned = BigDecimal::zero();
        if ($customer) {
            if (BigDecimal::of($quote['points_redeemed'])->isPositive()) {
                $this->loyalty->redeem($customer, BigDecimal::of($quote['points_redeemed']), $sale->getKey(), $actor);
            }
            $earned = $this->loyalty->earn($customer, $t['total'], $sale->getKey(), $actor);
        }

        $sale->forceFill(['cogs_total' => (string) $cogs, 'points_earned' => (string) $earned->toScale(2)])->save();

        // Overrides and price changes are audited; ordinary sales are their own record.
        if ($authorizers !== [] || collect($quote['lines'])->contains('price_overridden', true)) {
            $this->audit->log('sale.approved_overrides', $sale, [], [], [
                'invoice_no' => $sale->invoice_no,
                'approvals' => $authorizers,
                'price_overrides' => collect($quote['lines'])->where('price_overridden', true)
                    ->map(fn ($l) => ['sku' => $l['variant']->sku, 'list' => (string) $l['list_price'], 'sold' => (string) $l['unit_price']])->values()->all(),
            ], authorizedBy: $authorizers !== [] ? User::query()->find(reset($authorizers)) : null);
        }

        return $sale;
    }

    /**
     * Each permission the cart needs: held by the cashier, or approved by a
     * supervisor PIN (override token for that permission).
     *
     * @param  list<string>  $required
     * @param  array<string, string>  $tokens  permission => override token
     * @return array<string, int> permission => supervisor id (for approved ones)
     */
    private function approve(array $required, array $tokens, User $actor): array
    {
        $authorizers = [];
        $missing = [];
        foreach ($required as $permission) {
            if ($actor->can($permission)) {
                continue;
            }
            if (! isset($tokens[$permission])) {
                $missing[] = $permission;

                continue;
            }
            $authorizers[$permission] = $this->overrides->consume($actor, $tokens[$permission], $permission)->getKey();
        }

        if ($missing !== []) {
            throw new DomainException('A supervisor needs to approve this sale.', 'APPROVAL_REQUIRED', 403, ['permissions' => $missing]);
        }

        return $authorizers;
    }

    /**
     * @param  list<array{payment_method_id: int, amount: string, reference_no?: string|null}>  $payments
     * @param  array<string, int>  $authorizers
     * @return array{rows: list<array<string, mixed>>, paid: Money, change: Money, credit: Money}
     */
    public function tenders(array $payments, Money $due, $customer): array
    {
        $methods = PaymentMethod::query()->whereIn('id', array_column($payments, 'payment_method_id'))->get()->keyBy('id');
        $cash = [];
        $other = [];

        foreach (array_values($payments) as $i => $p) {
            $method = $methods[$p['payment_method_id']] ?? null;
            if (! $method || ! $method->is_active) {
                throw ValidationException::withMessages(["payments.{$i}.payment_method_id" => 'This payment method is not available.']);
            }
            $amount = Money::of($p['amount']);
            if (! $amount->isPositive()) {
                throw ValidationException::withMessages(["payments.{$i}.amount" => 'Enter an amount.']);
            }
            if ($method->requires_reference && blank($p['reference_no'] ?? null)) {
                throw ValidationException::withMessages(["payments.{$i}.reference_no" => "Enter the {$method->name} reference number."]);
            }
            $entry = ['method' => $method, 'amount' => $amount, 'reference_no' => $p['reference_no'] ?? null, 'index' => $i];
            if ($method->type === 'cash') {
                $cash[] = $entry;
            } else {
                $other[] = $entry;
            }
        }

        $nonCash = Money::sum(array_column($other, 'amount'));
        if ($nonCash->greaterThan($due)) {
            throw ValidationException::withMessages(['payments' => "Card, e-wallet and account payments cannot exceed the amount due ({$due->format()}). Give change only from cash."]);
        }
        $cashDue = $due->minus($nonCash);
        $tendered = Money::sum(array_column($cash, 'amount'));
        if ($tendered->lessThan($cashDue)) {
            throw ValidationException::withMessages(['payments' => 'The payment is short by '.$cashDue->minus($tendered)->format().'.']);
        }

        $credit = Money::zero();
        $rows = [];
        foreach ($other as $o) {
            if ($o['method']->type === 'credit') {
                if (! $customer) {
                    throw ValidationException::withMessages(['customer_id' => 'Choose the customer to charge to.']);
                }
                if (! $this->plan->hasFeature('credit_sales')) {
                    throw new DomainException('Credit sales are not included in your current plan.', 'FEATURE_NOT_AVAILABLE', 402);
                }
                $credit = $credit->plus($o['amount']);
            }
            $rows[] = array_merge($o, ['tendered' => null, 'change' => Money::zero()]);
        }

        // Cash: apply what is due, the rest is change (kept on the first cash line).
        $change = $tendered->minus($cashDue);
        foreach ($cash as $k => $c) {
            $applied = $k === 0 ? $c['amount']->minus($change) : $c['amount'];
            if ($applied->isNegative()) {
                throw ValidationException::withMessages(['payments' => 'Combine the cash into one payment line.']);
            }
            if ($applied->isZero() && $k > 0) {
                continue;
            }
            // array_merge, not "+": the applied amount must replace the tendered one.
            $rows[] = array_merge($c, ['amount' => $applied, 'tendered' => $c['amount'], 'change' => $k === 0 ? $change : Money::zero()]);
        }

        return ['rows' => $rows, 'paid' => $due->plus($change), 'change' => $change, 'credit' => $credit];
    }
}

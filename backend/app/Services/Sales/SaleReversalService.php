<?php

namespace App\Services\Sales;

use App\Enums\DocumentType;
use App\Exceptions\DomainException;
use App\Models\CashRegisterSession;
use App\Models\CustomerLedgerEntry;
use App\Models\LoyaltyTransaction;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SupervisorOverrideService;
use App\Services\Cash\CashSessionService;
use App\Services\Customers\CustomerAccountService;
use App\Services\Customers\LoyaltyService;
use App\Services\Inventory\StockLedger;
use App\Services\Numbering\DocumentNumberService;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Undoing sales. Nothing is deleted: a void changes the sale's status and
 * reverses its effects; a refund is its own numbered document.
 *
 *  - VOID: the whole sale, only while its shift is still open (before the
 *    drawer is counted and the day's Z reading). Stock comes back at the
 *    exact cost it went out, payments are marked reversed (so the shift's
 *    expected cash drops), account charges and points are reversed.
 *  - REFUND: some or all items, any time later, from the refunding
 *    cashier's open shift. Each line's amount and VAT are the same share of
 *    what was actually charged on that line (discounts and Senior/PWD
 *    included); the last unit refunded takes the exact remainder, so a line
 *    never refunds more than it was sold for. Returned items go back to
 *    sellable stock at their original cost, or are written off (damaged).
 *    Money goes back to the customer's account first (for credit sales),
 *    the rest is paid out (cash by default).
 */
class SaleReversalService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly DocumentNumberService $numbers,
        private readonly SupervisorOverrideService $overrides,
        private readonly CustomerAccountService $accounts,
        private readonly LoyaltyService $loyalty,
        private readonly CashSessionService $sessions,
        private readonly AuditLogger $audit,
    ) {}

    /* ================================================================ void */

    public function void(Sale $sale, string $reason, ?string $overrideToken, User $actor): Sale
    {
        $authorizer = $this->approve('sales.void', $overrideToken, $actor, 'A supervisor needs to approve this void.');

        return DB::transaction(function () use ($sale, $reason, $authorizer, $actor) {
            $sale = Sale::query()->lockForUpdate()->findOrFail($sale->getKey());
            if ($sale->status !== 'completed') {
                throw new DomainException(
                    $sale->status === 'voided' ? 'This sale is already voided.' : 'A sale with refunds cannot be voided. Refund the remaining items instead.',
                    'SALE_NOT_VOIDABLE', 409,
                );
            }
            $session = $sale->cash_register_session_id
                ? CashRegisterSession::query()->lockForUpdate()->find($sale->cash_register_session_id) : null;
            if (! $session || ! $session->isOpen()) {
                throw new DomainException('This sale\'s shift is already closed, so it can no longer be voided. Process a refund instead.', 'VOID_SHIFT_CLOSED', 409);
            }

            $now = now();

            // Stock: every unit that went out comes back at the cost it went out at.
            $movements = StockMovement::query()->where('reference_type', 'sale')->where('reference_id', $sale->getKey())
                ->where('type', 'sale')->orderBy('product_variant_id')->orderBy('id')->get();
            $variants = ProductVariant::withTrashed()->whereIn('id', $movements->pluck('product_variant_id'))->get()->keyBy('id');
            foreach ($movements as $m) {
                $qty = BigDecimal::of($m->qty)->abs();
                if ($qty->isZero()) {
                    continue;
                }
                $unitCost = BigDecimal::of($m->total_cost)->abs()->dividedBy($qty, 4, RoundingMode::HalfUp);
                $this->ledger->receive($sale->branch_id, $variants[$m->product_variant_id], $qty, $unitCost, 'sale_void', [
                    'reference_type' => 'sale', 'reference_id' => $sale->getKey(), 'reason' => "Void of sale {$sale->invoice_no}", 'occurred_at' => $now,
                ]);
            }

            // Payments no longer count toward the shift.
            Payment::query()->where('sale_id', $sale->getKey())->where('status', 'confirmed')->get()
                ->each(fn (Payment $p) => $p->forceFill(['status' => 'reversed'])->save());

            $customer = $sale->customer;
            if ($customer) {
                $charged = Money::sum(CustomerLedgerEntry::query()->where('reference_type', 'sale')->where('reference_id', $sale->getKey())
                    ->where('type', 'credit_sale')->pluck('amount')->all());
                if ($charged->isPositive()) {
                    $this->accounts->credit($customer, $charged, 'sale', $sale->getKey(), "Void of sale {$sale->invoice_no}", $actor);
                }
                $this->loyalty->reverse($customer, BigDecimal::of($sale->points_redeemed ?? '0'), $sale->getKey(), $actor, "Void of sale {$sale->invoice_no}: points used returned");
                $this->loyalty->reverse($customer, BigDecimal::of($sale->points_earned ?? '0')->negated(), $sale->getKey(), $actor, "Void of sale {$sale->invoice_no}: points earned removed");
            }

            $sale->forceFill([
                'status' => 'voided',
                'voided_at' => $now,
                'voided_by' => $actor->getKey(),
                'void_authorized_by' => $authorizer,
                'void_reason' => $reason,
            ])->save();

            $this->audit->log('sale.voided', $sale, ['status' => 'completed'], ['status' => 'voided'], [
                'invoice_no' => $sale->invoice_no, 'total' => (string) $sale->total_amount, 'reason' => $reason,
            ], authorizedBy: $authorizer ? User::query()->find($authorizer) : null);

            return $sale;
        });
    }

    /* ================================================================ refund */

    /**
     * @param  array{client_uuid: string, reason: string, items: list<array{sale_item_id: int, qty: string, restock: string}>,
     *               payment_method_id?: int|null, override_token?: string|null}  $data
     * @return array{refund: Refund, replayed: bool}
     */
    public function refund(Sale $sale, array $data, User $actor): array
    {
        if ($existing = Refund::query()->where('client_uuid', $data['client_uuid'])->first()) {
            if ($existing->sale_id !== $sale->getKey()) {
                throw new DomainException('This refund ID was already used for another sale.', 'IDEMPOTENCY_CONFLICT', 409);
            }

            return ['refund' => $existing, 'replayed' => true];
        }

        $session = CashRegisterSession::query()->where('user_id', $actor->getKey())->where('status', 'open')->first();
        if (! $session || $session->branch_id !== $sale->branch_id) {
            throw new DomainException('Start a shift in the branch where the item was sold to process a refund.', 'NO_OPEN_SHIFT', 409);
        }
        if (! $sale->isRefundable()) {
            throw new DomainException($sale->status === 'voided' ? 'This sale was voided.' : 'Everything on this sale was already refunded.', 'SALE_NOT_REFUNDABLE', 409);
        }

        $method = isset($data['payment_method_id'])
            ? PaymentMethod::query()->where('is_active', true)->where('type', '!=', 'credit')->find($data['payment_method_id'])
            : PaymentMethod::query()->where('is_active', true)->where('type', 'cash')->orderBy('sort_order')->first();
        if (! $method) {
            throw ValidationException::withMessages(['payment_method_id' => 'Choose how the money is given back.']);
        }

        $authorizer = $this->approve('sales.refund', $data['override_token'] ?? null, $actor, 'A supervisor needs to approve this refund.');

        $refund = DB::transaction(fn () => $this->recordRefund($sale, $session, $data, $method, $authorizer, $actor));

        return ['refund' => $refund, 'replayed' => false];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recordRefund(Sale $sale, CashRegisterSession $session, array $data, PaymentMethod $method, ?int $authorizer, User $actor): Refund
    {
        $sale = Sale::query()->lockForUpdate()->findOrFail($sale->getKey());
        if (! $sale->isRefundable()) {
            throw new DomainException('Everything on this sale was already refunded.', 'SALE_NOT_REFUNDABLE', 409);
        }
        $session = CashRegisterSession::query()->lockForUpdate()->findOrFail($session->getKey());
        if (! $session->isOpen()) {
            throw new DomainException('Your shift was closed. Start a new shift to continue.', 'NO_OPEN_SHIFT', 409);
        }

        $items = SaleItem::query()->where('sale_id', $sale->getKey())->lockForUpdate()->get()->keyBy('id');
        $refundedSoFar = RefundItem::query()->whereIn('sale_item_id', $items->keys())
            ->groupBy('sale_item_id')->selectRaw('sale_item_id, SUM(amount) AS amount, SUM(vat_amount) AS vat')->get()->keyBy('sale_item_id');

        /* ------------------------------------------------ lines */
        $lines = [];
        $seen = [];
        foreach (array_values($data['items']) as $i => $row) {
            $field = "items.{$i}";
            $item = $items[$row['sale_item_id']] ?? null;
            if (! $item) {
                throw ValidationException::withMessages(["{$field}.sale_item_id" => 'This item is not on the sale.']);
            }
            if (isset($seen[$item->id])) {
                throw ValidationException::withMessages(["{$field}.sale_item_id" => 'Each item can be listed once.']);
            }
            $seen[$item->id] = true;

            $sold = BigDecimal::of($item->qty);
            $left = $sold->minus($item->qty_refunded);
            $qty = BigDecimal::of($row['qty'])->toScale(3, RoundingMode::Unnecessary);
            if (! $qty->isPositive()) {
                throw ValidationException::withMessages(["{$field}.qty" => 'Enter a quantity.']);
            }
            // Sold in whole units (pieces, boxes): refunded in whole units too.
            if ($sold->getFractionalPart()->isZero() && ! $qty->getFractionalPart()->isZero()) {
                throw ValidationException::withMessages(["{$field}.qty" => 'Refund whole units of this item.']);
            }
            if ($qty->isGreaterThan($left)) {
                throw ValidationException::withMessages(["{$field}.qty" => "Only {$left->strippedOfTrailingZeros()} left to refund on this line."]);
            }

            $done = $refundedSoFar[$item->id] ?? null;
            if ($qty->isEqualTo($left)) {
                // The rest of the line: exactly what is left, so rounding never over-refunds.
                $amount = Money::of($item->line_total)->minus($done->amount ?? '0');
                $vat = Money::of($item->vat_amount)->minus($done->vat ?? '0');
            } else {
                $amount = Money::of($item->line_total)->multipliedBy((string) $qty->dividedBy($sold, 10, RoundingMode::HalfUp));
                $vat = Money::of($item->vat_amount)->multipliedBy((string) $qty->dividedBy($sold, 10, RoundingMode::HalfUp));
            }

            $baseQty = $qty->multipliedBy($item->conversion_factor)->toScale(3, RoundingMode::HalfUp);
            $unitCost = BigDecimal::of($item->base_qty)->isPositive()
                ? BigDecimal::of($item->cogs)->dividedBy($item->base_qty, 4, RoundingMode::HalfUp) : BigDecimal::zero();

            $lines[] = compact('item', 'qty', 'baseQty', 'amount', 'vat', 'unitCost') + ['restock' => $row['restock'], 'index' => $i];
        }

        $total = Money::sum(array_column($lines, 'amount'));
        $vatTotal = Money::sum(array_column($lines, 'vat'));
        $now = now();

        $refund = new Refund;
        $refund->forceFill([
            'client_uuid' => $data['client_uuid'],
            'sale_id' => $sale->getKey(),
            'branch_id' => $sale->branch_id,
            'terminal_id' => $session->terminal_id,
            'cash_register_session_id' => $session->getKey(),
            'number' => $this->numbers->next(DocumentType::Refund, $session->terminal_id),
            'reason' => $data['reason'],
            'subtotal' => (string) $total->minus($vatTotal),
            'vat_amount' => (string) $vatTotal,
            'total' => (string) $total,
            'cogs_reversed' => '0.00',
            'status' => 'completed',
            'processed_by' => $actor->getKey(),
            'authorized_by' => $authorizer,
            'refunded_at' => $now,
        ]);

        /* ------------------------------------------------ stock back (lock order) */
        $cogsBack = Money::zero();
        $variants = ProductVariant::withTrashed()->with('product')->whereIn('id', collect($lines)->pluck('item.product_variant_id'))->get()->keyBy('id');
        // The refund row needs an id for the stock references, so save first (immutable afterwards: COGS is set via a query).
        $refund->save();

        foreach (collect($lines)->sortBy(fn ($l) => $l['item']->product_variant_id) as $l) {
            if ($l['restock'] !== 'sellable' || ! $l['baseQty']->isPositive()) {
                continue;
            }
            $variant = $variants[$l['item']->product_variant_id];
            $tracked = $variant->product && $variant->product->type === 'standard' && $variant->product->track_inventory;
            if (! $tracked) {
                continue;
            }
            $movement = $this->ledger->receive($sale->branch_id, $variant, $l['baseQty'], $l['unitCost'], 'return', [
                'reference_type' => 'refund', 'reference_id' => $refund->getKey(),
                'reason' => "Refund {$refund->number} of sale {$sale->invoice_no}", 'occurred_at' => $now,
            ]);
            $cogsBack = $cogsBack->plus(Money::of($movement->total_cost));
        }

        foreach ($lines as $l) {
            $row = new RefundItem;
            $row->forceFill([
                'refund_id' => $refund->getKey(),
                'sale_item_id' => $l['item']->getKey(),
                'qty' => (string) $l['qty'],
                'base_qty' => (string) $l['baseQty'],
                'amount' => (string) $l['amount'],
                'vat_amount' => (string) $l['vat'],
                'restock_to' => $l['restock'],
            ])->save();
            $l['item']->forceFill(['qty_refunded' => (string) BigDecimal::of($l['item']->qty_refunded)->plus($l['qty'])->toScale(3)])->save();
        }
        DB::table('refunds')->where('id', $refund->getKey())->update(['cogs_reversed' => (string) $cogsBack]);

        /* ------------------------------------------------ money back */
        $customer = $sale->customer;
        $toAccount = Money::zero();
        if ($customer) {
            $charged = Money::sum(CustomerLedgerEntry::query()->where('reference_type', 'sale')->where('reference_id', $sale->getKey())
                ->where('type', 'credit_sale')->pluck('amount')->all());
            $credited = Money::sum(CustomerLedgerEntry::query()->where('type', 'refund')->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('reference_type', 'refund')->whereIn('reference_id', Refund::query()->where('sale_id', $sale->getKey())->select('id')))
                ->orWhere(fn ($w) => $w->where('reference_type', 'sale')->where('reference_id', $sale->getKey())))->pluck('amount')->all())->abs();
            $owed = Money::of($customer->fresh()->balance)->max('0');
            $toAccount = $total->min($charged->minus($credited)->max('0'))->min($owed);
            if ($toAccount->isPositive()) {
                $this->accounts->credit($customer, $toAccount, 'refund', $refund->getKey(), "Refund {$refund->number} of sale {$sale->invoice_no}", $actor);
            }
        }

        $payout = $total->minus($toAccount);
        if ($payout->isPositive()) {
            if ($method->type === 'cash') {
                $inDrawer = Money::of($this->sessions->summary($session)['expected_cash']);
                if ($payout->greaterThan($inDrawer)) {
                    throw ValidationException::withMessages(['payment_method_id' => "The drawer only has {$inDrawer->format()} in cash."]);
                }
            }
            $payment = new Payment;
            $payment->forceFill([
                'client_uuid' => (string) Str::uuid(),
                'sale_id' => $sale->getKey(),
                'refund_id' => $refund->getKey(),
                'payment_method_id' => $method->getKey(),
                'cash_register_session_id' => $session->getKey(),
                'direction' => 'out',
                'amount' => (string) $payout,
                'change' => '0.00',
                'status' => 'confirmed',
                'confirmed_by' => $actor->getKey(),
                'confirmed_at' => $now,
                'paid_at' => $now,
            ])->save();
        }

        /* ------------------------------------------------ status + points */
        $items->each->refresh();
        $fully = $items->every(fn (SaleItem $i) => BigDecimal::of($i->qty_refunded)->isGreaterThanOrEqualTo($i->qty));
        $sale->forceFill(['status' => $fully ? 'refunded' : 'partially_refunded'])->save();

        if ($customer) {
            $this->reversePoints($sale, $customer, $fully, $actor, $refund->number);
        }

        $this->audit->log('sale.refunded', $sale, [], ['status' => $sale->status], [
            'refund_no' => $refund->number, 'invoice_no' => $sale->invoice_no, 'total' => (string) $total,
            'to_account' => (string) $toAccount, 'paid_out' => (string) $payout, 'reason' => $data['reason'],
        ], authorizedBy: $authorizer ? User::query()->find($authorizer) : null);

        return $refund->fresh();
    }

    /**
     * Points earned shrink to what the remaining (kept) amount would have
     * earned; points used come back only when the whole sale is refunded.
     */
    private function reversePoints(Sale $sale, $customer, bool $fully, User $actor, string $refundNo): void
    {
        $earned = BigDecimal::of($sale->points_earned ?? '0');
        $already = BigDecimal::of(LoyaltyTransaction::query()->where('reference_type', 'sale')->where('reference_id', $sale->getKey())
            ->where('reason', 'refund')->where('points', '<', 0)->sum('points') ?: '0')->abs();

        if ($earned->isPositive()) {
            $kept = Money::of($sale->total_amount)->minus(Money::sum(Refund::query()->where('sale_id', $sale->getKey())->pluck('total')->all()));
            $spendPer = BigDecimal::of($this->loyalty->settings()['spend_per_point']);
            $target = $spendPer->isPositive() ? BigDecimal::of((string) $kept->max('0'))->dividedBy($spendPer, 0, RoundingMode::Down) : $earned;
            $remove = $earned->minus($target)->minus($already);
            if ($remove->isPositive()) {
                $this->loyalty->reverse($customer, $remove->negated(), $sale->getKey(), $actor, "Refund {$refundNo}: points earned removed");
            }
        }

        $redeemed = BigDecimal::of($sale->points_redeemed ?? '0');
        if ($fully && $redeemed->isPositive()) {
            $this->loyalty->reverse($customer, $redeemed, $sale->getKey(), $actor, "Refund {$refundNo}: points used returned");
        }
    }

    /**
     * The actor's own permission, or a supervisor's single-use override token.
     * Returns the approving supervisor's id (null when the actor had the permission).
     */
    private function approve(string $permission, ?string $token, User $actor, string $message): ?int
    {
        if ($actor->can($permission)) {
            return null;
        }
        if (! $token) {
            throw new DomainException($message, 'APPROVAL_REQUIRED', 403, ['permissions' => [$permission]]);
        }

        return $this->overrides->consume($actor, $token, $permission)->getKey();
    }
}

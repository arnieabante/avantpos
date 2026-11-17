<?php

namespace App\Services\Inventory;

use App\Exceptions\DomainException;
use App\Models\InventoryBalance;
use App\Models\InventoryLayer;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\StockMovementLayer;
use App\Models\User;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The ONLY writer of stock: movements (append-only ledger), FIFO/FEFO cost
 * layers and the cached balances.
 *
 * Every call must run inside the caller's DB transaction. The branch +
 * variant balance row is locked first (SELECT ... FOR UPDATE), so two
 * requests touching the same item are serialised and stock can never be
 * double-spent. Callers that touch several items should pass them sorted
 * by variant id to keep the lock order stable (no deadlocks).
 *
 * Invariants per branch + variant:
 *   qty_on_hand = SUM(layers.qty_remaining)          when qty_on_hand >= 0
 *   SUM(layers.qty_remaining) = 0                    when qty_on_hand < 0
 *   total_cost  = SUM(layers.qty_remaining * unit_cost), rounded to centavos
 *
 * Negative stock (only when the product allows it, i.e. POS sales in
 * Phase 5) is costed at the latest known unit cost; the next receipt first
 * settles the deficit before it adds a layer with remaining quantity.
 */
class StockLedger
{
    /** Movement type -> source type recorded on the cost layer it creates. */
    private const LAYER_SOURCES = [
        'opening' => 'opening',
        'purchase_receipt' => 'purchase_receipt',
        'return' => 'return',
        'sale_void' => 'return',
        'adjustment_in' => 'adjustment_in',
        'transfer_in' => 'transfer_in',
        'count_variance' => 'count_variance',
    ];

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * Add stock: creates a cost layer and an inbound movement.
     *
     * @param  array{reference_type?: string, reference_id?: int, reason?: string|null, batch_no?: string|null,
     *               expiry_date?: string|null, occurred_at?: Carbon|null, user_id?: int|null}  $options
     */
    public function receive(int $branchId, ProductVariant $variant, BigDecimal|string $qty, BigDecimal|string $unitCost, string $type, array $options = []): StockMovement
    {
        $this->assertInTransaction();
        $qty = $this->positiveQty($variant, $qty);
        $unitCost = BigDecimal::of($unitCost)->toScale(4, RoundingMode::HalfUp);

        if (! isset(self::LAYER_SOURCES[$type])) {
            throw new LogicException("[{$type}] is not an inbound movement type.");
        }
        if ($unitCost->isNegative()) {
            throw new DomainException('Unit cost cannot be negative.', 'INVALID_UNIT_COST');
        }

        $balance = $this->lockBalance($branchId, $variant->getKey());
        $before = BigDecimal::of($balance->qty_on_hand);
        $occurredAt = $options['occurred_at'] ?? now();

        // A negative balance (oversold) is settled first: that part of the
        // delivery replaces stock that was already sold.
        $deficit = $before->isNegative() ? $before->negated() : BigDecimal::zero();
        $remaining = $qty->minus($deficit);
        $remaining = $remaining->isNegative() ? BigDecimal::zero() : $remaining;

        $layer = new InventoryLayer;
        $layer->forceFill([
            'branch_id' => $branchId,
            'product_variant_id' => $variant->getKey(),
            'source_type' => self::LAYER_SOURCES[$type],
            'source_id' => $options['reference_id'] ?? null,
            'batch_no' => $options['batch_no'] ?? null,
            'expiry_date' => $options['expiry_date'] ?? null,
            'unit_cost' => (string) $unitCost,
            'qty_received' => (string) $qty->toScale(3),
            'qty_remaining' => (string) $remaining->toScale(3),
            'received_at' => $occurredAt,
        ])->save();

        $after = $before->plus($qty);
        $movement = $this->record($balance, $variant, $type, $qty, $unitCost, $qty->multipliedBy($unitCost), $after, $occurredAt, $options);

        $this->refreshBalance($balance, $after, $occurredAt);
        $movement->setRelation('createdLayer', $layer);

        return $movement;
    }

    /**
     * Remove stock: consumes open layers FIFO (FEFO for expiry-tracked
     * products) and records exactly which layers were used.
     * `batch_no` limits consumption to one batch; `layer_id` to one exact
     * layer (used to reverse a goods receipt).
     *
     * @param  array{reference_type?: string, reference_id?: int, reason?: string|null, batch_no?: string|null,
     *               layer_id?: int|null, allow_negative?: bool, occurred_at?: Carbon|null, user_id?: int|null}  $options
     * @return StockMovement with ->consumed: list<array{layer: InventoryLayer, qty: BigDecimal}>
     */
    public function issue(int $branchId, ProductVariant $variant, BigDecimal|string $qty, string $type, array $options = []): StockMovement
    {
        $this->assertInTransaction();
        $qty = $this->positiveQty($variant, $qty);

        $balance = $this->lockBalance($branchId, $variant->getKey());
        $before = BigDecimal::of($balance->qty_on_hand);
        $occurredAt = $options['occurred_at'] ?? now();
        $batchNo = $options['batch_no'] ?? null;
        $layerId = $options['layer_id'] ?? null;

        $layers = InventoryLayer::query()
            ->where('branch_id', $branchId)
            ->where('product_variant_id', $variant->getKey())
            ->where('qty_remaining', '>', 0)
            ->when($batchNo !== null, fn ($q) => $q->where('batch_no', $batchNo))
            ->when($layerId !== null, fn ($q) => $q->whereKey($layerId))
            ->when(
                (bool) $variant->product->track_expiry,
                // FEFO: earliest expiry first; layers without an expiry date last.
                fn ($q) => $q->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date'),
            )
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $need = $qty;
        $cost = BigDecimal::zero();
        $consumed = [];

        foreach ($layers as $layer) {
            if (! $need->isPositive()) {
                break;
            }
            $available = BigDecimal::of($layer->qty_remaining);
            $take = $available->isLessThan($need) ? $available : $need;

            $layer->forceFill(['qty_remaining' => (string) $available->minus($take)->toScale(3)])->save();
            $consumed[] = ['layer' => $layer, 'qty' => $take];
            $cost = $cost->plus($take->multipliedBy($layer->unit_cost));
            $need = $need->minus($take);
        }

        if ($need->isPositive()) {
            $allowNegative = ($options['allow_negative'] ?? false) && $batchNo === null && $layerId === null;
            if (! $allowNegative) {
                $available = $qty->minus($need);
                throw new DomainException(
                    sprintf('Not enough stock of %s%s: %s available, %s needed.',
                        $this->label($variant), $batchNo !== null ? " (batch {$batchNo})" : '',
                        $this->trim($available), $this->trim($qty)),
                    'INSUFFICIENT_STOCK',
                    422,
                    ['product_variant_id' => $variant->getKey(), 'available' => (string) $available->toScale(3), 'requested' => (string) $qty->toScale(3)],
                );
            }
            $cost = $cost->plus($need->multipliedBy($this->currentUnitCost($branchId, $variant)));
        }

        $unitCost = $cost->dividedBy($qty, 4, RoundingMode::HalfUp);
        $after = $before->minus($qty);
        $movement = $this->record($balance, $variant, $type, $qty->negated(), $unitCost, $cost->negated(), $after, $occurredAt, $options);

        foreach ($consumed as $use) {
            $link = new StockMovementLayer;
            $link->forceFill([
                'stock_movement_id' => $movement->getKey(),
                'inventory_layer_id' => $use['layer']->getKey(),
                'qty' => (string) $use['qty']->toScale(3),
                'unit_cost' => $use['layer']->unit_cost,
            ])->save();
        }

        $this->refreshBalance($balance, $after, $occurredAt);
        $movement->setRelation('consumedLayers', collect($consumed));

        return $movement;
    }

    /**
     * Best current unit cost: the newest layer at this branch, then the
     * newest layer anywhere, then the product's reference cost, then 0.
     */
    public function currentUnitCost(int $branchId, ProductVariant $variant): string
    {
        $latest = fn ($q) => $q->where('product_variant_id', $variant->getKey())->latest('received_at')->latest('id')->value('unit_cost');

        return (string) ($latest(InventoryLayer::query()->where('branch_id', $branchId))
            ?? $latest(InventoryLayer::query())
            ?? $variant->cost_price
            ?? '0.0000');
    }

    public function onHand(int $branchId, int $variantId): BigDecimal
    {
        return BigDecimal::of(InventoryBalance::query()
            ->where('branch_id', $branchId)->where('product_variant_id', $variantId)->value('qty_on_hand') ?? '0');
    }

    /**
     * Stock is only kept for physical products that track inventory.
     */
    public function assertTracked(ProductVariant $variant): void
    {
        $product = $variant->product;
        if ($product->type !== 'standard' || ! $product->track_inventory) {
            throw new DomainException("{$this->label($variant)} does not track stock.", 'NOT_STOCK_TRACKED', 422,
                ['product_variant_id' => $variant->getKey()]);
        }
    }

    private function positiveQty(ProductVariant $variant, BigDecimal|string $qty): BigDecimal
    {
        $variant->loadMissing('product.baseUnit');
        $this->assertTracked($variant);

        $qty = BigDecimal::of($qty);
        if (! $qty->isPositive()) {
            throw new LogicException('Movement quantity must be positive; the direction comes from receive()/issue().');
        }
        if ($qty->strippedOfTrailingZeros()->getScale() > 3) {
            throw new DomainException('Quantities have at most 3 decimal places.', 'INVALID_QUANTITY');
        }
        if (! ($variant->product->baseUnit?->allows_decimal ?? false) && ! $qty->getFractionalPart()->isZero()) {
            throw new DomainException(
                "{$this->label($variant)} is counted in whole {$variant->product->baseUnit?->name}s.",
                'WHOLE_QUANTITY_REQUIRED', 422, ['product_variant_id' => $variant->getKey()],
            );
        }

        return $qty;
    }

    private function lockBalance(int $branchId, int $variantId): InventoryBalance
    {
        $query = fn () => InventoryBalance::query()->where('branch_id', $branchId)->where('product_variant_id', $variantId);

        if ($balance = $query()->lockForUpdate()->first()) {
            return $balance;
        }

        // First movement of this item in this branch. insertOrIgnore makes a
        // concurrent first movement safe (unique key); then lock the row.
        InventoryBalance::query()->insertOrIgnore([
            'tenant_id' => $this->tenant->requireId(),
            'branch_id' => $branchId,
            'product_variant_id' => $variantId,
            'qty_on_hand' => '0',
            'total_cost' => '0',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $query()->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function record(
        InventoryBalance $balance, ProductVariant $variant, string $type, BigDecimal $qty, BigDecimal $unitCost,
        BigDecimal $totalCost, BigDecimal $after, Carbon $occurredAt, array $options,
    ): StockMovement {
        if (! in_array($type, StockMovement::TYPES, true)) {
            throw new LogicException("Unknown stock movement type [{$type}].");
        }

        $user = auth()->user();

        $movement = new StockMovement;
        $movement->forceFill([
            'branch_id' => $balance->branch_id,
            'product_variant_id' => $variant->getKey(),
            'type' => $type,
            'qty' => (string) $qty->toScale(3),
            'unit_cost' => (string) $unitCost->toScale(4, RoundingMode::HalfUp),
            'total_cost' => (string) $totalCost->toScale(2, RoundingMode::HalfUp),
            'balance_after' => (string) $after->toScale(3),
            'reference_type' => $options['reference_type'] ?? null,
            'reference_id' => $options['reference_id'] ?? null,
            'user_id' => $options['user_id'] ?? ($user instanceof User ? $user->getKey() : null),
            'reason' => $options['reason'] ?? null,
            'occurred_at' => $occurredAt,
        ])->save();

        return $movement;
    }

    private function refreshBalance(InventoryBalance $balance, BigDecimal $after, Carbon $occurredAt): void
    {
        $value = InventoryLayer::query()
            ->where('branch_id', $balance->branch_id)
            ->where('product_variant_id', $balance->product_variant_id)
            ->where('qty_remaining', '>', 0)
            ->selectRaw('COALESCE(SUM(qty_remaining * unit_cost), 0) AS value')
            ->value('value');

        $balance->forceFill([
            'qty_on_hand' => (string) $after->toScale(3),
            'total_cost' => (string) BigDecimal::of($value ?? '0')->toScale(2, RoundingMode::HalfUp),
            'last_movement_at' => $occurredAt,
        ])->save();
    }

    private function assertInTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('StockLedger must be called inside a database transaction.');
        }
    }

    private function label(ProductVariant $variant): string
    {
        $name = $variant->product->name;

        return $variant->name ? "{$name} – {$variant->name}" : $name;
    }

    private function trim(BigDecimal $qty): string
    {
        return rtrim(rtrim((string) $qty->toScale(3), '0'), '.') ?: '0';
    }
}

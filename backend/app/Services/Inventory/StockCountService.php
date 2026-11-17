<?php

namespace App\Services\Inventory;

use App\Enums\DocumentType;
use App\Exceptions\DomainException;
use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Inventory\Concerns\PostsLines;
use App\Services\Numbering\DocumentNumberService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Physical stock counts.
 *
 * Each item's system quantity is captured at the moment its count is
 * entered, so variance = counted - on hand *at that moment*. Sales made
 * while the rest of the store is still being counted are therefore not
 * mistaken for missing stock. Finalizing posts the variances as
 * count_variance movements; uncounted items are left untouched.
 */
class StockCountService
{
    use PostsLines;

    public const MAX_ITEMS = 5000;

    public function __construct(
        private readonly StockLedger $ledger,
        private readonly DocumentNumberService $numbers,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{notes?: string|null, category_id?: int|null}  $data
     */
    public function create(Branch $branch, array $data, User $actor): StockCount
    {
        return DB::transaction(function () use ($branch, $data, $actor) {
            $variantIds = $this->variantsInScope($branch, $data['category_id'] ?? null);

            if ($variantIds === []) {
                throw ValidationException::withMessages(['category_id' => 'There are no stock-tracked products to count here.']);
            }
            if (count($variantIds) > self::MAX_ITEMS) {
                throw ValidationException::withMessages(['category_id' => 'Too many items for one count ('.count($variantIds).'). Count one category at a time.']);
            }

            $count = new StockCount;
            $count->forceFill([
                'branch_id' => $branch->getKey(),
                'number' => $this->numbers->next(DocumentType::StockCount),
                'status' => 'counting',
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->getKey(),
            ])->save();

            $onHand = InventoryBalance::query()->where('branch_id', $branch->getKey())
                ->whereIn('product_variant_id', $variantIds)->pluck('qty_on_hand', 'product_variant_id');
            $now = now();

            foreach (array_chunk($variantIds, 500) as $chunk) {
                StockCountItem::query()->insert(array_map(fn (int $id) => [
                    'tenant_id' => $count->tenant_id,
                    'stock_count_id' => $count->getKey(),
                    'product_variant_id' => $id,
                    'system_qty' => $onHand[$id] ?? '0',
                    'counted_qty' => null,
                    'variance_qty' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], $chunk));
            }

            $this->audit->log('stock_count.started', $count, [], ['number' => $count->number, 'items' => count($variantIds)]);

            return $count;
        });
    }

    /**
     * Save counted quantities. null clears an entry.
     *
     * @param  array<int, string|null>  $entries  count item id => counted quantity
     */
    public function record(StockCount $count, array $entries): StockCount
    {
        return DB::transaction(function () use ($count, $entries) {
            $count = $this->lock($count);
            $items = StockCountItem::query()->where('stock_count_id', $count->getKey())->whereKey(array_keys($entries))->get()->keyBy('id');

            if ($items->count() !== count($entries)) {
                throw ValidationException::withMessages(['entries' => 'Some items are not part of this count.']);
            }

            $variants = $this->loadVariants($items->pluck('product_variant_id'));
            $onHand = InventoryBalance::query()->where('branch_id', $count->branch_id)
                ->whereIn('product_variant_id', $items->pluck('product_variant_id'))->pluck('qty_on_hand', 'product_variant_id');

            foreach ($entries as $itemId => $counted) {
                $item = $items[$itemId];

                if ($counted === null || $counted === '') {
                    $item->forceFill(['counted_qty' => null, 'variance_qty' => null])->save();

                    continue;
                }

                $counted = BigDecimal::of($counted);
                $unit = $variants[$item->product_variant_id]->product->baseUnit;
                if (! ($unit?->allows_decimal ?? false) && ! $counted->getFractionalPart()->isZero()) {
                    throw ValidationException::withMessages(["entries.{$itemId}" => "Count in whole {$unit?->name}s."]);
                }

                // Unchanged entry: keep the snapshot taken when it was first counted.
                if ($item->counted_qty !== null && BigDecimal::of($item->counted_qty)->isEqualTo($counted)) {
                    continue;
                }

                $system = BigDecimal::of($onHand[$item->product_variant_id] ?? '0');
                $item->forceFill([
                    'system_qty' => (string) $system->toScale(3),
                    'counted_qty' => (string) $counted->toScale(3, RoundingMode::HalfUp),
                    'variance_qty' => (string) $counted->minus($system)->toScale(3, RoundingMode::HalfUp),
                ])->save();
            }

            $count->touch();

            return $count;
        });
    }

    public function finalize(StockCount $count, User $actor): StockCount
    {
        return DB::transaction(function () use ($count, $actor) {
            $count = $this->lock($count);
            $items = StockCountItem::query()->where('stock_count_id', $count->getKey())->whereNotNull('counted_qty')->get();

            if ($items->isEmpty()) {
                throw new DomainException('Enter at least one counted quantity before finalizing.', 'COUNT_EMPTY');
            }

            $variants = $this->loadVariants($items->pluck('product_variant_id'));
            $options = ['reference_type' => 'stock_count', 'reference_id' => $count->getKey(), 'reason' => "Stock count {$count->number}"];
            $value = BigDecimal::zero();
            $adjusted = 0;

            foreach ($this->inLockOrder($items) as $item) {
                $variance = BigDecimal::of($item->variance_qty);
                if ($variance->isZero()) {
                    continue;
                }
                $variant = $variants[$item->product_variant_id];
                $field = "entries.{$item->id}";

                $movement = $variance->isPositive()
                    ? $this->atLine($field, fn () => $this->ledger->receive($count->branch_id, $variant, $variance,
                        $this->ledger->currentUnitCost($count->branch_id, $variant), 'count_variance', $options))
                    : $this->atLine($field, fn () => $this->ledger->issue($count->branch_id, $variant, $variance->abs(), 'count_variance',
                        $options + ['allow_negative' => (bool) $variant->product->allow_negative_stock]));

                $value = $value->plus($movement->total_cost);
                $adjusted++;
            }

            $count->forceFill(['status' => 'finalized', 'finalized_by' => $actor->getKey(), 'finalized_at' => now()])->save();
            $this->audit->log('stock_count.finalized', $count, [], [
                'number' => $count->number,
                'counted' => $items->count(),
                'adjusted' => $adjusted,
                'value_change' => (string) $value->toScale(2, RoundingMode::HalfUp),
            ]);

            return $count;
        });
    }

    public function cancel(StockCount $count): StockCount
    {
        return DB::transaction(function () use ($count) {
            $count = $this->lock($count);
            $count->forceFill(['status' => 'cancelled'])->save();
            $this->audit->log('stock_count.cancelled', $count, [], ['number' => $count->number]);

            return $count;
        });
    }

    /**
     * Stock-tracked variants to count: active products, plus inactive ones
     * that still have stock in this branch.
     *
     * @return list<int>
     */
    private function variantsInScope(Branch $branch, ?int $categoryId): array
    {
        $categoryIds = $categoryId === null ? null
            : ProductCategory::query()->where('parent_id', $categoryId)->pluck('id')->push($categoryId)->all();

        return ProductVariant::query()
            ->whereHas('product', fn ($q) => $q->where('type', 'standard')->where('track_inventory', true)
                ->when($categoryIds !== null, fn ($c) => $c->whereIn('category_id', $categoryIds)))
            ->where(fn ($q) => $q
                ->where(fn ($active) => $active->where('is_active', true)->whereHas('product', fn ($p) => $p->where('is_active', true)))
                ->orWhereIn('id', InventoryBalance::query()->select('product_variant_id')
                    ->where('branch_id', $branch->getKey())->where('qty_on_hand', '!=', 0)))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function lock(StockCount $count): StockCount
    {
        $locked = StockCount::query()->lockForUpdate()->findOrFail($count->getKey());

        if ($locked->status !== 'counting') {
            throw new DomainException("Stock count {$locked->number} is already {$locked->status}.", 'DOCUMENT_NOT_EDITABLE', 409);
        }

        return $locked;
    }
}

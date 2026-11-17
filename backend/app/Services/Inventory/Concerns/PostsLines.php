<?php

namespace App\Services\Inventory\Concerns;

use App\Exceptions\DomainException;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Helpers shared by inventory documents (adjustments, transfers, counts).
 */
trait PostsLines
{
    /**
     * Run one document line against the ledger; a stock error is reported on
     * that line's field so the form can show it next to the item.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function atLine(string $field, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (DomainException $e) {
            if (in_array($e->errorCode, ['INSUFFICIENT_STOCK', 'WHOLE_QUANTITY_REQUIRED', 'NOT_STOCK_TRACKED', 'INVALID_QUANTITY'], true)) {
                throw ValidationException::withMessages([$field => $e->getMessage()]);
            }
            throw $e;
        }
    }

    /**
     * Variants of the given ids (tenant-scoped), with product and unit loaded.
     *
     * @param  iterable<int>  $ids
     * @return Collection<int, ProductVariant>
     */
    protected function loadVariants(iterable $ids): Collection
    {
        return ProductVariant::query()
            ->with('product.baseUnit')
            ->whereIn('id', collect($ids)->unique()->values())
            ->get()
            ->keyBy('id');
    }

    /**
     * Items in a stable lock order (variant id, then line id) while keeping
     * each item's position for error messages.
     *
     * @template TItem of object
     *
     * @param  Collection<int, TItem>  $items
     * @return Collection<int, TItem>
     */
    protected function inLockOrder(Collection $items): Collection
    {
        return $items->sortBy(fn ($item) => sprintf('%012d-%012d', $item->product_variant_id, $item->id ?? 0));
    }
}

<?php

namespace App\Services\Catalog;

use App\Models\BranchProductPrice;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Support\Money\Money;

/**
 * The price that applies to a variant (optionally in one of its selling units)
 * at a branch. Order of precedence:
 *
 *   1. the selling unit's own price (e.g. "Pack of 10 = ₱95"),
 *   2. otherwise the base price × conversion factor,
 * where the base price is the branch override if the branch has one, else the
 * variant price. All arithmetic is decimal (Money), never float.
 */
class PriceResolver
{
    /** @var array<int, array<int, BranchProductPrice|null>> branch id => variant id => override (null = none) */
    private array $preloaded = [];

    /**
     * Load the branch overrides of many variants in one query (lists, POS
     * catalogue) instead of one query per variant in resolve().
     *
     * @param  iterable<int>  $variantIds
     */
    public function preload(int $branchId, iterable $variantIds): void
    {
        $ids = collect($variantIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }
        $found = BranchProductPrice::query()->where('branch_id', $branchId)->whereIn('product_variant_id', $ids)->get()->keyBy('product_variant_id');
        foreach ($ids as $id) {
            $this->preloaded[$branchId][$id] = $found->get($id);
        }
    }

    /**
     * @return array{selling_price: string, wholesale_price: string|null, source: string}
     */
    public function resolve(ProductVariant $variant, ?int $branchId = null, ?ProductUnit $unit = null): array
    {
        $override = match (true) {
            $branchId === null => null,
            array_key_exists($variant->id, $this->preloaded[$branchId] ?? []) => $this->preloaded[$branchId][$variant->id],
            default => BranchProductPrice::query()->where('branch_id', $branchId)->where('product_variant_id', $variant->id)->first(),
        };

        $baseSelling = $override?->selling_price ?? $variant->selling_price;
        $baseWholesale = $override ? $override->wholesale_price : $variant->wholesale_price;
        $source = $override ? 'branch' : 'product';

        if ($unit === null) {
            return ['selling_price' => $baseSelling, 'wholesale_price' => $baseWholesale, 'source' => $source];
        }

        $factor = (string) $unit->conversion_factor;

        return [
            'selling_price' => $unit->selling_price ?? Money::of($baseSelling)->multipliedBy($factor)->toString(),
            'wholesale_price' => $unit->wholesale_price
                ?? ($baseWholesale !== null ? Money::of($baseWholesale)->multipliedBy($factor)->toString() : null),
            'source' => $unit->selling_price !== null ? 'unit' : $source,
        ];
    }
}

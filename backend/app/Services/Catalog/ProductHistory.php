<?php

namespace App\Services\Catalog;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Answers "has this variant / selling unit ever been used?". Used records are
 * part of financial and stock history: they may be deactivated, never removed.
 */
class ProductHistory
{
    /** Tables whose rows reference product_variants.id. */
    private const VARIANT_TABLES = [
        'stock_movements', 'inventory_layers', 'stock_adjustment_items', 'stock_transfer_items', 'stock_count_items',
        'purchase_order_items', 'goods_receipt_items', 'sale_items',
    ];

    /** Tables whose rows reference product_units.id. */
    private const UNIT_TABLES = ['purchase_order_items', 'goods_receipt_items', 'sale_items'];

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  list<int>  $variantIds
     * @return list<int> the ids among $variantIds that have history
     */
    public function variantsWithHistory(array $variantIds): array
    {
        return $this->usedIds($variantIds, self::VARIANT_TABLES, 'product_variant_id');
    }

    /**
     * @param  list<int>  $productUnitIds
     * @return list<int>
     */
    public function unitsWithHistory(array $productUnitIds): array
    {
        return $this->usedIds($productUnitIds, self::UNIT_TABLES, 'product_unit_id');
    }

    /**
     * @param  list<int>  $ids
     * @param  list<string>  $tables
     * @return list<int>
     */
    private function usedIds(array $ids, array $tables, string $column): array
    {
        if ($ids === []) {
            return [];
        }

        $used = [];
        foreach ($tables as $table) {
            $found = DB::table($table)
                ->where('tenant_id', $this->tenant->requireId())
                ->whereIn($column, $ids)
                ->distinct()
                ->pluck($column)
                ->all();
            $used = [...$used, ...$found];
        }

        return array_values(array_unique(array_map('intval', $used)));
    }
}

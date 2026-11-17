<?php

namespace App\Http\Resources\Purchasing;

use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Models\Supplier;

final class PurchasePresenter
{
    /**
     * The purchase unit of a line: base unit when $unit is null.
     *
     * @return array{product_unit_id: int|null, name: string, abbreviation: string|null, factor: string}
     */
    public static function unit(ProductVariant $variant, ?ProductUnit $unit, string $factor): array
    {
        $base = $variant->product->baseUnit;

        return $unit
            ? ['product_unit_id' => $unit->id, 'name' => $unit->unit?->name ?? 'Unit', 'abbreviation' => $unit->unit?->abbreviation, 'factor' => $factor]
            : ['product_unit_id' => null, 'name' => $base?->name ?? 'Unit', 'abbreviation' => $base?->abbreviation, 'factor' => $factor];
    }

    /**
     * @return array{id: int, name: string, vat_registered: bool}|null
     */
    public static function supplier(?Supplier $supplier): ?array
    {
        return $supplier ? ['id' => $supplier->id, 'name' => $supplier->name, 'vat_registered' => $supplier->vat_registered] : null;
    }
}

<?php

namespace App\Http\Resources;

use App\Models\BranchProductPrice;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Services\Media\MediaStorage;
use Illuminate\Http\Request;

/**
 * Full product document (product form). Cost prices are only included for
 * users with products.view_cost.
 *
 * @property Product $resource
 */
class ProductResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $canSeeCost = (bool) $request->user()?->can('products.view_cost');

        return [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'type' => $p->type,
            'tax_class' => $p->tax_class,
            'sc_pwd_eligible' => $p->sc_pwd_eligible,
            'track_inventory' => $p->track_inventory,
            'track_expiry' => $p->track_expiry,
            'allow_negative_stock' => $p->allow_negative_stock,
            'is_active' => $p->is_active,
            'meta' => (object) ($p->meta ?? []),
            'category' => $p->category ? ['id' => $p->category->id, 'name' => $p->category->name] : null,
            'brand' => $p->brand ? ['id' => $p->brand->id, 'name' => $p->brand->name] : null,
            'base_unit' => [
                'id' => $p->baseUnit->id,
                'name' => $p->baseUnit->name,
                'abbreviation' => $p->baseUnit->abbreviation,
                'allows_decimal' => $p->baseUnit->allows_decimal,
            ],
            'image_url' => app(MediaStorage::class)->url($p->image_path),
            'variants' => $p->variants->map(fn (ProductVariant $v) => self::variant($v, $canSeeCost))->values(),
            'created_at' => $p->created_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function variant(ProductVariant $v, bool $canSeeCost): array
    {
        $baseBarcodes = $v->barcodes->whereNull('product_unit_id');

        return array_filter([
            'id' => $v->id,
            'name' => $v->name,
            'sku' => $v->sku,
            'attributes' => $v->getAttribute('attributes'),
            'cost_price' => $canSeeCost ? $v->cost_price : null,
            'selling_price' => $v->selling_price,
            'wholesale_price' => $v->wholesale_price,
            'reorder_level' => $v->reorder_level,
            'is_default' => $v->is_default,
            'is_active' => $v->is_active,
            'barcodes' => $baseBarcodes->pluck('barcode')->values(),
            'units' => $v->units->map(fn (ProductUnit $u) => [
                'id' => $u->id,
                'unit_id' => $u->unit_id,
                'unit' => ['name' => $u->unit->name, 'abbreviation' => $u->unit->abbreviation],
                'conversion_factor' => $u->conversion_factor,
                'selling_price' => $u->selling_price,
                'wholesale_price' => $u->wholesale_price,
                'is_sellable' => $u->is_sellable,
                'is_purchasable' => $u->is_purchasable,
                'barcodes' => $v->barcodes->where('product_unit_id', $u->id)->map(fn (ProductBarcode $b) => $b->barcode)->values(),
            ])->values(),
            'branch_prices' => $v->relationLoaded('branchPrices')
                ? $v->branchPrices->map(fn (BranchProductPrice $bp) => [
                    'branch_id' => $bp->branch_id,
                    'selling_price' => $bp->selling_price,
                    'wholesale_price' => $bp->wholesale_price,
                ])->values()
                : null,
        ], fn ($value, $key) => $key !== 'cost_price' || $canSeeCost, ARRAY_FILTER_USE_BOTH);
    }
}

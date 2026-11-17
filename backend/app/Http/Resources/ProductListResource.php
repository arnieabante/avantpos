<?php

namespace App\Http\Resources;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Media\MediaStorage;
use App\Support\Money\Money;
use Illuminate\Http\Request;

/**
 * One row of the product list: summary only (price range, variant count).
 *
 * @property Product $resource
 */
class ProductListResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $variants = $p->variants;
        /** @var ProductVariant|null $default */
        $default = $variants->firstWhere('is_default', true) ?? $variants->first();

        $prices = $variants->pluck('selling_price')->filter(fn ($v) => $v !== null)->values();
        $min = $prices->reduce(fn (?string $carry, string $price) => $carry === null || Money::of($price)->lessThan($carry) ? $price : $carry);
        $max = $prices->reduce(fn (?string $carry, string $price) => $carry === null || Money::of($price)->greaterThan($carry) ? $price : $carry);

        return [
            'id' => $p->id,
            'name' => $p->name,
            'type' => $p->type,
            'is_active' => $p->is_active,
            'track_inventory' => $p->track_inventory,
            'image_url' => app(MediaStorage::class)->url($p->image_path),
            'category' => $p->category ? ['id' => $p->category->id, 'name' => $p->category->name] : null,
            'brand' => $p->brand ? ['id' => $p->brand->id, 'name' => $p->brand->name] : null,
            'base_unit' => $p->baseUnit?->abbreviation,
            'sku' => $default?->sku,
            'variants_count' => $variants->count(),
            'price_min' => $min,
            'price_max' => $max,
        ];
    }
}

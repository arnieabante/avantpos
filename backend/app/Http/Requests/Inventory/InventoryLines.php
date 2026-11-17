<?php

namespace App\Http\Requests\Inventory;

use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * Line checks shared by inventory document requests.
 */
trait InventoryLines
{
    /**
     * @return Collection<int, ProductVariant>
     */
    protected function lineVariants(): Collection
    {
        $ids = collect($this->input('items', []))->pluck('product_variant_id')->filter(fn ($id) => is_numeric($id))->unique();

        return ProductVariant::query()->with('product.baseUnit')->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * Every line must be a stock-tracked item counted in its unit (whole or decimal).
     *
     * @param  Collection<int, ProductVariant>  $variants
     */
    protected function checkTrackedLines(Validator $validator, Collection $variants, string $qtyField = 'qty'): void
    {
        foreach ((array) $this->input('items', []) as $i => $line) {
            $variant = $variants[$line['product_variant_id'] ?? 0] ?? null;
            if (! $variant) {
                continue;
            }
            $product = $variant->product;
            if ($product->type !== 'standard' || ! $product->track_inventory) {
                $validator->errors()->add("items.{$i}.product_variant_id", "{$product->name} does not track stock.");

                continue;
            }
            $qty = (string) ($line[$qtyField] ?? '');
            if (is_numeric($qty) && ! ($product->baseUnit?->allows_decimal ?? false) && floor((float) $qty) != (float) $qty) {
                $validator->errors()->add("items.{$i}.{$qtyField}", "Enter whole {$product->baseUnit?->name}s.");
            }
        }
    }
}

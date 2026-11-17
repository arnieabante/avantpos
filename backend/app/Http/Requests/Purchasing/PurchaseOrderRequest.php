<?php

namespace App\Http\Requests\Purchasing;

use App\Http\Requests\Inventory\InventoryLines;
use App\Rules\TenantExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PurchaseOrderRequest extends FormRequest
{
    use InventoryLines;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', TenantExists::on('suppliers')],
            'order_date' => ['nullable', 'date_format:Y-m-d'],
            'expected_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'approve' => ['sometimes', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.product_variant_id' => ['required', 'integer', TenantExists::on('product_variants')],
            'items.*.product_unit_id' => ['nullable', 'integer', TenantExists::on('product_units')],
            'items.*.qty' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:9999999'],
            'items.*.unit_cost' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one item.',
            'items.*.qty.gt' => 'Enter a quantity greater than zero.',
            'items.*.unit_cost.required' => 'Enter the cost per unit.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $variants = $this->lineVariants();
            $this->checkTrackedLines($validator, $variants);

            // Same item + unit twice on one order is almost always a mistake.
            $seen = [];
            foreach ((array) $this->input('items') as $i => $line) {
                $key = $line['product_variant_id'].'-'.($line['product_unit_id'] ?? 'base');
                if (isset($seen[$key])) {
                    $validator->errors()->add("items.{$i}.product_variant_id", 'This item is already on the order in the same unit.');
                }
                $seen[$key] = true;
            }
        }];
    }

    /**
     * Quantities are in the purchase unit: a box of a "piece" item may be bought in whole boxes only.
     */
    protected function checkTrackedLines(Validator $validator, $variants, string $qtyField = 'qty'): void
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
            $wholeOnly = ! empty($line['product_unit_id']) || ! ($product->baseUnit?->allows_decimal ?? false);
            if (is_numeric($qty) && $wholeOnly && floor((float) $qty) != (float) $qty) {
                $validator->errors()->add("items.{$i}.{$qtyField}", 'Enter whole units.');
            }
        }
    }
}

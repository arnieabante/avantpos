<?php

namespace App\Http\Requests\Purchasing;

use App\Models\ProductVariant;
use App\Models\PurchaseOrderItem;
use App\Rules\TenantExists;
use App\Services\Billing\PlanGate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Against a PO: items reference purchase_order_item_id (item, unit and
 * factor come from the PO). Direct receipt: supplier_id + item and unit.
 */
class GoodsReceiptRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $direct = ! $this->filled('purchase_order_id');

        return [
            'purchase_order_id' => ['nullable', 'integer', TenantExists::on('purchase_orders')],
            'supplier_id' => [$direct ? 'required' : 'nullable', 'integer', TenantExists::on('suppliers')],
            'supplier_invoice_no' => ['nullable', 'string', 'max:60'],
            'supplier_invoice_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:300'],
            'items.*.purchase_order_item_id' => [$direct ? 'prohibited' : 'required', 'integer', 'distinct'],
            'items.*.product_variant_id' => [$direct ? 'required' : 'nullable', 'integer', TenantExists::on('product_variants')],
            'items.*.product_unit_id' => ['nullable', 'integer', TenantExists::on('product_units')],
            'items.*.qty' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:9999999'],
            'items.*.unit_cost' => [$direct ? 'required' : 'nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999'],
            'items.*.batch_no' => ['nullable', 'string', 'max:60'],
            'items.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
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
            'supplier_invoice_date.before_or_equal' => 'The invoice date cannot be in the future.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $lines = (array) $this->input('items');
            $poItems = $this->filled('purchase_order_id')
                ? PurchaseOrderItem::query()->whereIn('id', array_column($lines, 'purchase_order_item_id'))->get()->keyBy('id')
                : collect();
            $variantIds = $poItems->isNotEmpty() ? $poItems->pluck('product_variant_id') : collect($lines)->pluck('product_variant_id');
            $variants = ProductVariant::query()->with('product.baseUnit')->whereIn('id', $variantIds)->get()->keyBy('id');
            $batches = app(PlanGate::class)->hasFeature('batch_expiry');

            foreach ($lines as $i => $line) {
                $poItem = $poItems[$line['purchase_order_item_id'] ?? 0] ?? null;
                $variant = $variants[$poItem?->product_variant_id ?? ($line['product_variant_id'] ?? 0)] ?? null;
                if (! $variant) {
                    continue; // the service reports items that are not on the PO
                }
                $product = $variant->product;

                if ($product->type !== 'standard' || ! $product->track_inventory) {
                    $validator->errors()->add("items.{$i}.product_variant_id", "{$product->name} does not track stock.");

                    continue;
                }

                $inPacks = $poItem ? $poItem->product_unit_id !== null : ! empty($line['product_unit_id']);
                $qty = (string) $line['qty'];
                if (($inPacks || ! ($product->baseUnit?->allows_decimal ?? false)) && floor((float) $qty) != (float) $qty) {
                    $validator->errors()->add("items.{$i}.qty", 'Enter whole units.');
                }

                $tracksExpiry = $batches && $product->track_expiry;
                if (! $tracksExpiry && (filled($line['batch_no'] ?? null) || filled($line['expiry_date'] ?? null))) {
                    $validator->errors()->add("items.{$i}.batch_no", 'This item does not track batches or expiry dates.');
                }
                if ($tracksExpiry && blank($line['expiry_date'] ?? null)) {
                    $validator->errors()->add("items.{$i}.expiry_date", 'Enter the expiry date printed on the stock.');
                }
            }
        }];
    }
}

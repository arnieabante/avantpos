<?php

namespace App\Http\Requests\Inventory;

use App\Models\StockAdjustment;
use App\Rules\TenantExists;
use App\Services\Billing\PlanGate;
use App\Services\Inventory\StockAdjustmentService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StockAdjustmentRequest extends FormRequest
{
    use InventoryLines;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $canCost = (bool) $this->user()?->can('inventory.valuation');

        return [
            'reason_code' => ['required', Rule::in(StockAdjustment::REASONS)],
            'notes' => ['nullable', 'string', 'max:1000'],
            'post' => ['sometimes', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_variant_id' => ['required', 'integer', TenantExists::on('product_variants')],
            'items.*.qty' => ['required', 'numeric', 'decimal:0,3', 'between:-9999999,9999999', 'not_in:0'],
            // Only users who may see inventory costs may set one; otherwise the current cost is used.
            'items.*.unit_cost' => [$canCost ? 'nullable' : 'prohibited', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999999'],
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
            'items.*.qty.not_in' => 'Enter a quantity other than zero.',
            'items.*.unit_cost.prohibited' => 'You are not allowed to set stock costs.',
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

            $reason = $this->string('reason_code')->toString();
            $batches = app(PlanGate::class)->hasFeature('batch_expiry');

            foreach ((array) $this->input('items') as $i => $line) {
                $variant = $variants[$line['product_variant_id']];
                $incoming = StockAdjustmentService::signedQty($reason, (string) $line['qty'])->isPositive();
                $tracksExpiry = $batches && $variant->product->track_expiry;

                if (! $tracksExpiry && (filled($line['batch_no'] ?? null) || filled($line['expiry_date'] ?? null))) {
                    $validator->errors()->add("items.{$i}.batch_no", 'This item does not track batches or expiry dates.');
                }
                if ($tracksExpiry && $incoming && blank($line['expiry_date'] ?? null)) {
                    $validator->errors()->add("items.{$i}.expiry_date", 'Enter the expiry date of the stock being added.');
                }
            }
        }];
    }
}

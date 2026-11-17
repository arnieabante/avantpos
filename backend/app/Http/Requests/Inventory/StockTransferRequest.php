<?php

namespace App\Http\Requests\Inventory;

use App\Rules\TenantExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StockTransferRequest extends FormRequest
{
    use InventoryLines;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to_branch_id' => ['required', 'integer', TenantExists::on('branches')],
            'notes' => ['nullable', 'string', 'max:1000'],
            'dispatch' => ['sometimes', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            // One line per item: received quantities are matched back by item.
            'items.*.product_variant_id' => ['required', 'integer', 'distinct', TenantExists::on('product_variants')],
            'items.*.qty' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:9999999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one item.',
            'items.*.product_variant_id.distinct' => 'This item is already listed.',
            'items.*.qty.gt' => 'Enter a quantity greater than zero.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isEmpty()) {
                $this->checkTrackedLines($validator, $this->lineVariants());
            }
        }];
    }
}

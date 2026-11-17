<?php

namespace App\Http\Requests\Sales;

use App\Rules\TenantExists;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A POS cart. Used to price (quote) and to check out; checkout also needs
 * the client UUID and payments. Only items, quantities and requested
 * discounts are taken from the client — never amounts.
 */
class SaleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $checkout = $this->routeIs('api.v1.pos.sales.store');
        $discount = fn (string $prefix) => [
            "{$prefix}" => ['nullable', 'array'],
            // A manual discount needs type + value, a preset only its id; SaleCalculator checks the combination.
            "{$prefix}.type" => ['nullable', Rule::in(['percent', 'fixed'])],
            "{$prefix}.value" => ['nullable', 'numeric', 'decimal:0,4', 'min:0', 'max:99999999'],
            "{$prefix}.preset_id" => ['nullable', 'integer', TenantExists::on('discount_presets')],
        ];

        return [
            'client_uuid' => [$checkout ? 'required' : 'nullable', 'uuid'],
            'customer_id' => ['nullable', 'integer', TenantExists::on('customers')],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_variant_id' => ['required', 'integer', TenantExists::on('product_variants')],
            'items.*.product_unit_id' => ['nullable', 'integer', TenantExists::on('product_units')],
            'items.*.qty' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'max:999999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999'],
            'items.*.sc_pwd' => ['sometimes', 'boolean'],
            ...$discount('items.*.discount'),
            ...$discount('discount'),
            'sc_pwd' => ['nullable', 'array'],
            'sc_pwd.type' => ['required_with:sc_pwd', Rule::in(['senior', 'pwd'])],
            'sc_pwd.name' => ['required_with:sc_pwd', 'string', 'max:150'],
            'sc_pwd.id_no' => ['required_with:sc_pwd', 'string', 'max:40'],
            'sc_pwd.tin' => ['nullable', 'string', 'max:20'],
            'redeem_points' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'payments' => [$checkout ? 'present' : 'nullable', 'array', 'max:10'],
            'payments.*.payment_method_id' => ['required', 'integer', TenantExists::on('payment_methods')],
            'payments.*.amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:99999999'],
            'payments.*.reference_no' => ['nullable', 'string', 'max:100'],
            'overrides' => ['nullable', 'array'],
            'overrides.*' => ['string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'The cart is empty.',
            'sc_pwd.name.required_with' => 'Enter the name on the Senior Citizen / PWD ID.',
            'sc_pwd.id_no.required_with' => 'Enter the Senior Citizen / PWD ID number.',
        ];
    }
}

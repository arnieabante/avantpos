<?php

namespace App\Http\Requests\Settings;

use App\Models\PaymentMethod;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentMethodRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var PaymentMethod|null $method */
        $method = $this->route('payment_method');

        return [
            // The type decides POS behaviour (change, reference no.), so it is fixed after creation.
            'type' => [$method ? 'prohibited' : 'required', 'string', Rule::in(PaymentMethod::TYPES)],
            'name' => [
                'required', 'string', 'min:2', 'max:60',
                Rule::unique('payment_methods', 'name')
                    ->where('tenant_id', app(TenantContext::class)->requireId())
                    ->ignore($method?->id),
            ],
            'requires_reference' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['type.prohibited' => 'The type of an existing payment method cannot be changed.'];
    }
}

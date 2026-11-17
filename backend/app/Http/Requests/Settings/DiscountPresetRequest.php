<?php

namespace App\Http\Requests\Settings;

use App\Models\DiscountPreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DiscountPresetRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'type' => ['required', 'string', Rule::in(DiscountPreset::TYPES)],
            'value' => [
                'required', 'numeric', 'gt:0',
                $this->input('type') === 'fixed' ? 'decimal:0,2' : 'decimal:0,4',
                $this->input('type') === 'fixed' ? 'max:9999999.99' : 'max:100',
            ],
            'scope' => ['required', 'string', Rule::in(DiscountPreset::SCOPES)],
            'requires_permission' => ['sometimes', 'boolean'],
            'max_amount' => ['nullable', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999.99'],
            'is_active' => ['sometimes', 'boolean'],
            // Statutory presets are provisioned by AvantPOS only.
            'statutory' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['value.max' => $this->input('type') === 'fixed' ? 'The amount is too large.' : 'A percentage cannot exceed 100.'];
    }
}

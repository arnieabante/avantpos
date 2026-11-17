<?php

namespace App\Http\Requests\Settings;

use App\Models\TaxRate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TaxRateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'type' => ['required', 'string', Rule::in(TaxRate::TYPES)],
            // Percent with up to 4 decimals, e.g. 12 or 12.0000
            'rate' => ['required', 'numeric', 'decimal:0,4', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

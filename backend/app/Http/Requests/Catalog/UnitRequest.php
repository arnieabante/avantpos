<?php

namespace App\Http\Requests\Catalog;

use App\Models\Unit;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UnitRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Unit|null $unit */
        $unit = $this->route('unit');

        return [
            'name' => [
                'required', 'string', 'min:1', 'max:40',
                Rule::unique('units', 'name')->where('tenant_id', app(TenantContext::class)->requireId())->ignore($unit?->id),
            ],
            'abbreviation' => ['required', 'string', 'max:10'],
            'allows_decimal' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => 'This unit already exists.'];
    }
}

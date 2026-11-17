<?php

namespace App\Http\Requests\Catalog;

use App\Models\Brand;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BrandRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Brand|null $brand */
        $brand = $this->route('brand');

        return [
            'name' => [
                'required', 'string', 'min:1', 'max:100',
                // Deleted brands with the same name are restored by the service instead.
                Rule::unique('brands', 'name')
                    ->where('tenant_id', app(TenantContext::class)->requireId())
                    ->whereNull('deleted_at')
                    ->ignore($brand?->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.unique' => 'This brand already exists.'];
    }
}

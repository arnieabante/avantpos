<?php

namespace App\Http\Requests\Catalog;

use App\Models\ProductCategory;
use App\Rules\TenantExists;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CategoryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var ProductCategory|null $category */
        $category = $this->route('category');

        return [
            'name' => [
                'required', 'string', 'min:1', 'max:100',
                // Unique among live categories with the same parent.
                Rule::unique('product_categories', 'name')
                    ->where('tenant_id', app(TenantContext::class)->requireId())
                    ->where(fn ($q) => $this->input('parent_id') ? $q->where('parent_id', $this->input('parent_id')) : $q->whereNull('parent_id'))
                    ->whereNull('deleted_at')
                    ->ignore($category?->id),
            ],
            // Two levels only: a parent must itself be a top-level category.
            'parent_id' => ['nullable', 'integer', TenantExists::on('product_categories')->whereNull('parent_id')],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var ProductCategory|null $category */
            $category = $this->route('category');
            $parentId = $this->input('parent_id');

            if ($category && $parentId) {
                if ((int) $parentId === $category->id) {
                    $validator->errors()->add('parent_id', 'A category cannot be its own parent.');
                } elseif ($category->children()->exists()) {
                    $validator->errors()->add('parent_id', 'This category has subcategories, so it must stay a top-level category.');
                }
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A category with this name already exists here.',
            'parent_id.exists' => 'Choose a top-level category as the parent.',
            'color.regex' => 'Use a colour like #696CFF.',
        ];
    }
}

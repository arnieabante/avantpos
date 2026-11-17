<?php

namespace App\Http\Resources;

use App\Models\ProductCategory;
use Illuminate\Http\Request;

/**
 * @property ProductCategory $resource
 */
class CategoryResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return [
            'id' => $c->id,
            'parent_id' => $c->parent_id,
            'name' => $c->name,
            'color' => $c->color,
            'sort_order' => $c->sort_order,
            'is_active' => $c->is_active,
            'products_count' => $this->whenCounted('products'),
            'children_count' => $this->whenCounted('children'),
        ];
    }
}

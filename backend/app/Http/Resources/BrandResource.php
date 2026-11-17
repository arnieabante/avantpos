<?php

namespace App\Http\Resources;

use App\Models\Brand;
use Illuminate\Http\Request;

/**
 * @property Brand $resource
 */
class BrandResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'products_count' => $this->whenCounted('products'),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\DiscountPreset;
use Illuminate\Http\Request;

/**
 * @property DiscountPreset $resource
 */
class DiscountPresetResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'type' => $this->resource->type,
            'value' => $this->resource->value,
            'scope' => $this->resource->scope,
            'statutory' => $this->resource->statutory,
            'requires_permission' => $this->resource->requires_permission,
            'max_amount' => $this->resource->max_amount,
            'is_active' => $this->resource->is_active,
        ];
    }
}

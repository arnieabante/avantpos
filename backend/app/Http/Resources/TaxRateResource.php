<?php

namespace App\Http\Resources;

use App\Models\TaxRate;
use Illuminate\Http\Request;

/**
 * @property TaxRate $resource
 */
class TaxRateResource extends ApiResource
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
            'rate' => $this->resource->rate,
            'is_default' => $this->resource->is_default,
            'is_active' => $this->resource->is_active,
        ];
    }
}

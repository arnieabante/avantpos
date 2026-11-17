<?php

namespace App\Http\Resources;

use App\Models\Unit;
use Illuminate\Http\Request;

/**
 * @property Unit $resource
 */
class UnitResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'abbreviation' => $this->resource->abbreviation,
            'allows_decimal' => $this->resource->allows_decimal,
        ];
    }
}

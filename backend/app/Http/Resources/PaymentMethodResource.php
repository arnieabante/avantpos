<?php

namespace App\Http\Resources;

use App\Models\PaymentMethod;
use Illuminate\Http\Request;

/**
 * @property PaymentMethod $resource
 */
class PaymentMethodResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'type' => $this->resource->type,
            'name' => $this->resource->name,
            'requires_reference' => $this->resource->requires_reference,
            'sort_order' => $this->resource->sort_order,
            'is_active' => $this->resource->is_active,
            // Online gateway integration (Phase 6); null = manual confirmation.
            'gateway_code' => $this->resource->gateway_code,
        ];
    }
}

<?php

namespace App\Http\Resources\Purchasing;

use App\Http\Resources\ApiResource;
use App\Models\Supplier;
use Illuminate\Http\Request;

/**
 * @property Supplier $resource
 */
class SupplierResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            'id' => $s->id,
            'code' => $s->code,
            'name' => $s->name,
            'contact_person' => $s->contact_person,
            'phone' => $s->phone,
            'email' => $s->email,
            'tin' => $s->tin,
            'vat_registered' => $s->vat_registered,
            'address' => $s->address,
            'payment_terms_days' => $s->payment_terms_days,
            'notes' => $s->notes,
            'is_active' => $s->is_active,
            'open_orders_count' => $s->open_orders_count ?? null,
            'created_at' => $s->created_at?->toIso8601String(),
        ];
    }
}

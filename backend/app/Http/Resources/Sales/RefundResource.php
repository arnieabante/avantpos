<?php

namespace App\Http\Resources\Sales;

use App\Http\Resources\ApiResource;
use App\Http\Resources\Inventory\InventoryPresenter;
use App\Models\Refund;
use Illuminate\Http\Request;

/**
 * @property Refund $resource
 */
class RefundResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'id' => $r->id,
            'number' => $r->number,
            'reason' => $r->reason,
            'subtotal' => $r->subtotal,
            'vat_amount' => $r->vat_amount,
            'total' => $r->total,
            'refunded_at' => $r->refunded_at?->toIso8601String(),
            'terminal' => $r->relationLoaded('terminal') && $r->terminal ? ['id' => $r->terminal->id, 'code' => $r->terminal->code, 'name' => $r->terminal->name] : null,
            'processed_by' => InventoryPresenter::user($r->relationLoaded('processor') ? $r->processor : null),
            'authorized_by' => InventoryPresenter::user($r->relationLoaded('authorizer') ? $r->authorizer : null),
            'items' => $r->relationLoaded('items') ? $r->items->map(fn ($i) => [
                'sale_item_id' => $i->sale_item_id,
                'qty' => $i->qty,
                'amount' => $i->amount,
                'vat_amount' => $i->vat_amount,
                'restock_to' => $i->restock_to,
            ])->all() : [],
            'payments' => $r->relationLoaded('payments') ? $r->payments->map(fn ($p) => [
                'method' => $p->method?->name,
                'type' => $p->method?->type,
                'amount' => $p->amount,
            ])->all() : [],
        ];
    }
}

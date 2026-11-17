<?php

namespace App\Http\Resources\Purchasing;

use App\Http\Resources\ApiResource;
use App\Http\Resources\Inventory\InventoryPresenter;
use App\Models\GoodsReceipt;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * @property GoodsReceipt $resource
 */
class GoodsReceiptResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $r = $this->resource;
        $user = $request->user();
        $user = $user instanceof User ? $user : null;

        $data = [
            'id' => $r->id,
            'number' => $r->number,
            'status' => $r->status,
            'supplier' => PurchasePresenter::supplier($r->supplier),
            'branch' => InventoryPresenter::branch($r->branch),
            'purchase_order' => $r->purchaseOrder ? ['id' => $r->purchaseOrder->id, 'number' => $r->purchaseOrder->number] : null,
            'supplier_invoice_no' => $r->supplier_invoice_no,
            'supplier_invoice_date' => $r->supplier_invoice_date?->toDateString(),
            'received_at' => $r->received_at?->toIso8601String(),
            'received_by' => InventoryPresenter::user($r->receiver),
            'subtotal' => $r->subtotal,
            'tax_total' => $r->tax_total,
            'total' => $r->total,
            'notes' => $r->notes,
            'reversed_by' => InventoryPresenter::user($r->reverser),
            'reversed_at' => $r->reversed_at?->toIso8601String(),
            'reversal_reason' => $r->reversal_reason,
            'items_count' => (int) ($r->items_count ?? ($r->relationLoaded('items') ? $r->items->count() : 0)),
            'can' => ['reverse' => $r->status === 'posted' && (bool) $user?->can('po.receive')],
        ];

        if ($r->relationLoaded('items')) {
            $data['items'] = $r->items->map(fn ($item) => [
                'id' => $item->id,
                'variant' => InventoryPresenter::variant($item->variant),
                'unit' => PurchasePresenter::unit($item->variant, $item->productUnit, $item->conversion_factor),
                'qty' => $item->qty,
                'base_qty' => $item->base_qty,
                'unit_cost' => $item->unit_cost,
                'line_total' => $item->line_total,
                'tax_amount' => $item->tax_amount,
                'batch_no' => $item->batch_no,
                'expiry_date' => $item->expiry_date?->toDateString(),
            ])->all();
        }

        return $data;
    }
}

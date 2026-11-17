<?php

namespace App\Http\Resources\Purchasing;

use App\Http\Resources\ApiResource;
use App\Http\Resources\Inventory\InventoryPresenter;
use App\Models\PurchaseOrder;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;

/**
 * @property PurchaseOrder $resource
 */
class PurchaseOrderResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $po = $this->resource;
        $user = $request->user();
        $user = $user instanceof User ? $user : null;
        $noneReceived = $po->relationLoaded('items')
            ? $po->items->every(fn ($i) => BigDecimal::of($i->qty_received)->isZero())
            : true;

        $data = [
            'id' => $po->id,
            'number' => $po->number,
            'status' => $po->status,
            'order_date' => $po->order_date?->toDateString(),
            'expected_date' => $po->expected_date?->toDateString(),
            'supplier' => PurchasePresenter::supplier($po->supplier),
            'branch' => InventoryPresenter::branch($po->branch),
            'subtotal' => $po->subtotal,
            'tax_total' => $po->tax_total,
            'total' => $po->total,
            'notes' => $po->notes,
            'created_by' => InventoryPresenter::user($po->creator),
            'approved_by' => InventoryPresenter::user($po->approver),
            'approved_at' => $po->approved_at?->toIso8601String(),
            'created_at' => $po->created_at?->toIso8601String(),
            'items_count' => (int) ($po->items_count ?? ($po->relationLoaded('items') ? $po->items->count() : 0)),
            'can' => [
                'edit' => $po->status === 'draft' && (bool) $user?->can('po.create'),
                'approve' => $po->status === 'draft' && (bool) $user?->can('po.approve'),
                'receive' => in_array($po->status, PurchaseOrder::RECEIVABLE, true) && (bool) $user?->can('po.receive'),
                'cancel' => in_array($po->status, ['draft', 'approved'], true) && $noneReceived && (bool) $user?->can('po.create'),
                'close' => $po->status === 'partially_received' && (bool) $user?->can('po.create'),
            ],
        ];

        if ($po->relationLoaded('items')) {
            $data['items'] = $po->items->map(fn ($item) => [
                'id' => $item->id,
                'variant' => InventoryPresenter::variant($item->variant),
                'unit' => PurchasePresenter::unit($item->variant, $item->productUnit, $item->conversion_factor),
                'qty_ordered' => $item->qty_ordered,
                'qty_received' => $item->qty_received,
                'qty_remaining' => (string) BigDecimal::max('0', BigDecimal::of($item->qty_ordered)->minus($item->qty_received))->toScale(3),
                'unit_cost' => $item->unit_cost,
                'line_total' => $item->line_total,
                'tax_amount' => $item->tax_amount,
            ])->all();
        }

        if ($po->relationLoaded('receipts')) {
            $data['receipts'] = $po->receipts->map(fn ($r) => [
                'id' => $r->id, 'number' => $r->number, 'status' => $r->status, 'total' => $r->total,
                'received_at' => $r->received_at?->toIso8601String(),
            ])->all();
        }

        return $data;
    }
}

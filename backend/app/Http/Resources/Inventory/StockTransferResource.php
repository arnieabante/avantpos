<?php

namespace App\Http\Resources\Inventory;

use App\Http\Resources\ApiResource;
use App\Models\StockTransfer;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;

/**
 * @property StockTransfer $resource
 */
class StockTransferResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $t = $this->resource;
        $canCost = InventoryPresenter::canSeeCost($request);
        $user = $request->user();
        $user = $user instanceof User ? $user : null;

        $data = [
            'id' => $t->id,
            'number' => $t->number,
            'status' => $t->status,
            'notes' => $t->notes,
            'from_branch' => InventoryPresenter::branch($t->fromBranch),
            'to_branch' => InventoryPresenter::branch($t->toBranch),
            'created_by' => InventoryPresenter::user($t->creator),
            'dispatched_by' => InventoryPresenter::user($t->dispatcher),
            'received_by' => InventoryPresenter::user($t->receiver),
            'dispatched_at' => $t->dispatched_at?->toIso8601String(),
            'received_at' => $t->received_at?->toIso8601String(),
            'created_at' => $t->created_at?->toIso8601String(),
            'items_count' => (int) ($t->items_count ?? ($t->relationLoaded('items') ? $t->items->count() : 0)),
            // What the current user may do here (the API enforces the same rules).
            'can' => [
                'edit' => $t->status === 'draft' && (bool) $user?->can('inventory.transfer') && $user->canAccessBranch($t->from_branch_id),
                'receive' => $t->status === 'in_transit' && (bool) $user?->can('inventory.receive_transfer') && $user->canAccessBranch($t->to_branch_id),
                'cancel' => in_array($t->status, ['draft', 'in_transit'], true) && (bool) $user?->can('inventory.transfer') && $user->canAccessBranch($t->from_branch_id),
            ],
        ];

        if ($t->relationLoaded('items')) {
            $data['items'] = $t->items->map(fn ($item) => [
                'id' => $item->id,
                'variant' => InventoryPresenter::variant($item->variant),
                'qty_sent' => $item->qty_sent,
                'qty_received' => $item->qty_received,
                'qty_short' => $t->status === 'received'
                    ? (string) BigDecimal::of($item->qty_sent)->minus($item->qty_received)->toScale(3)
                    : null,
            ] + ($canCost ? ['value' => $t->status === 'draft' ? null : $item->total_cost] : []))->all();

            if ($canCost && $t->status !== 'draft') {
                $data['value'] = (string) $t->items->reduce(fn (BigDecimal $sum, $i) => $sum->plus($i->total_cost), BigDecimal::zero())->toScale(2);
            }
        }

        return $data;
    }
}

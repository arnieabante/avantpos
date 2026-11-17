<?php

namespace App\Http\Resources\Inventory;

use App\Http\Resources\ApiResource;
use App\Models\StockCount;
use Illuminate\Http\Request;

/**
 * @property StockCount $resource
 */
class StockCountResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $c = $this->resource;

        return [
            'id' => $c->id,
            'number' => $c->number,
            'status' => $c->status,
            'notes' => $c->notes,
            'branch' => InventoryPresenter::branch($c->branch),
            'created_by' => InventoryPresenter::user($c->creator),
            'finalized_by' => InventoryPresenter::user($c->finalizer),
            'finalized_at' => $c->finalized_at?->toIso8601String(),
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
            'summary' => [
                'items' => (int) ($c->items_count ?? 0),
                'counted' => (int) ($c->counted_count ?? 0),
                'variances' => (int) ($c->variance_count ?? 0),
            ],
        ];
    }
}

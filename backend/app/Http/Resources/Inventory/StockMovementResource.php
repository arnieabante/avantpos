<?php

namespace App\Http\Resources\Inventory;

use App\Http\Resources\ApiResource;
use App\Models\StockMovement;
use Illuminate\Http\Request;

/**
 * @property StockMovement $resource
 */
class StockMovementResource extends ApiResource
{
    /**
     * Document numbers keyed "type:id", set by the controller for the current page.
     *
     * @var array<string, string>
     */
    public static array $referenceNumbers = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $m = $this->resource;

        return [
            'id' => $m->id,
            'type' => $m->type,
            'qty' => $m->qty,
            'balance_after' => $m->balance_after,
            'variant' => InventoryPresenter::variant($m->variant),
            'branch' => InventoryPresenter::branch($m->branch),
            'reference' => $m->reference_type ? [
                'type' => $m->reference_type,
                'id' => $m->reference_id,
                'number' => self::$referenceNumbers["{$m->reference_type}:{$m->reference_id}"] ?? null,
            ] : null,
            'reason' => $m->reason,
            'user' => InventoryPresenter::user($m->user),
            'occurred_at' => $m->occurred_at?->toIso8601String(),
        ] + (InventoryPresenter::canSeeCost($request) ? ['unit_cost' => $m->unit_cost, 'total_cost' => $m->total_cost] : []);
    }
}

<?php

namespace App\Http\Resources\Inventory;

use App\Http\Resources\ApiResource;
use App\Models\StockCountItem;
use Illuminate\Http\Request;

/**
 * @property StockCountItem $resource
 */
class StockCountItemResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'id' => $i->id,
            'variant' => InventoryPresenter::variant($i->variant),
            'system_qty' => $i->system_qty,
            'counted_qty' => $i->counted_qty,
            'variance_qty' => $i->variance_qty,
        ];
    }
}

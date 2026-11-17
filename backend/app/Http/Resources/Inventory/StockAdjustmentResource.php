<?php

namespace App\Http\Resources\Inventory;

use App\Http\Resources\ApiResource;
use App\Models\StockAdjustment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;

/**
 * @property StockAdjustment $resource
 */
class StockAdjustmentResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $a = $this->resource;
        $canCost = InventoryPresenter::canSeeCost($request);

        $data = [
            'id' => $a->id,
            'number' => $a->number,
            'status' => $a->status,
            'reason_code' => $a->reason_code,
            'notes' => $a->notes,
            'branch' => InventoryPresenter::branch($a->branch),
            'created_by' => InventoryPresenter::user($a->creator),
            'posted_by' => InventoryPresenter::user($a->poster),
            'posted_at' => $a->posted_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
            'items_count' => (int) ($a->items_count ?? ($a->relationLoaded('items') ? $a->items->count() : 0)),
        ];

        if ($a->relationLoaded('items')) {
            $total = BigDecimal::zero();
            $data['items'] = $a->items->map(function ($item) use ($canCost, &$total) {
                $value = $item->unit_cost !== null
                    ? BigDecimal::of($item->qty)->multipliedBy($item->unit_cost)->toScale(2, RoundingMode::HalfUp)
                    : null;
                $total = $value ? $total->plus($value) : $total;

                return [
                    'id' => $item->id,
                    'variant' => InventoryPresenter::variant($item->variant),
                    'qty' => $item->qty,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date?->toDateString(),
                ] + ($canCost ? ['unit_cost' => $item->unit_cost, 'value' => $value ? (string) $value : null] : []);
            })->all();

            if ($canCost && $a->status === 'posted') {
                $data['value_change'] = (string) $total;
            }
        }

        return $data;
    }
}

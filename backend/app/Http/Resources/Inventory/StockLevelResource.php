<?php

namespace App\Http\Resources\Inventory;

use App\Http\Resources\ApiResource;
use App\Models\ProductVariant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A variant with its stock in the selected branch (stock_qty, stock_value
 * and stock_last_movement_at are selected by the query).
 *
 * @property ProductVariant $resource
 */
class StockLevelResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $v = $this->resource;
        $onHand = BigDecimal::of($v->getAttribute('stock_qty') ?? '0');
        $value = BigDecimal::of($v->getAttribute('stock_value') ?? '0');
        $reorder = BigDecimal::of($v->reorder_level ?? '0');
        $last = $v->getAttribute('stock_last_movement_at');

        return [
            'variant' => InventoryPresenter::variant($v),
            'qty_on_hand' => (string) $onHand->toScale(3),
            'reorder_level' => $v->reorder_level,
            'stock_status' => match (true) {
                ! $onHand->isPositive() => 'out',
                $reorder->isPositive() && $onHand->isLessThanOrEqualTo($reorder) => 'low',
                default => 'in_stock',
            },
            'last_movement_at' => $last ? Carbon::parse($last)->toIso8601String() : null,
        ] + (InventoryPresenter::canSeeCost($request) ? [
            'value' => (string) $value->toScale(2),
            'average_cost' => $onHand->isPositive() ? (string) $value->dividedBy($onHand, 4, RoundingMode::HalfUp) : null,
        ] : []);
    }
}

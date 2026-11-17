<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Api\V1\Inventory\InventoryController;
use App\Http\Resources\Inventory\InventoryPresenter;
use App\Http\Resources\Purchasing\PurchasePresenter;
use App\Models\ProductUnit;
use App\Models\ProductVariant;
use App\Support\Api\ApiResponse;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Item picker for purchase orders and receipts: stock-tracked items with
 * the units they can be bought in, their last cost and stock on hand.
 * `low_stock=1` lists items at or below their reorder level instead, with
 * a suggested quantity (up to twice the reorder level).
 */
class PurchasingItemController extends InventoryController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required_without:low_stock', 'nullable', 'string', 'max:100'],
            'low_stock' => ['nullable', 'boolean'],
        ]);

        $branchId = $this->branch()->getKey();
        $lowStock = $request->boolean('low_stock');
        $term = $request->string('q')->trim()->toString();

        $variants = ProductVariant::query()
            ->with(['product.baseUnit', 'units' => fn ($q) => $q->where('is_purchasable', true)->with('unit')])
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('inventory_balances as b', fn ($j) => $j->on('b.product_variant_id', '=', 'product_variants.id')->where('b.branch_id', $branchId))
            ->whereNull('products.deleted_at')
            ->where('products.type', 'standard')
            ->where('products.track_inventory', true)
            ->where('products.is_active', true)
            ->where('product_variants.is_active', true)
            ->select('product_variants.*')
            ->selectRaw('COALESCE(b.qty_on_hand, 0) AS stock_qty')
            ->when($lowStock, fn ($q) => $q->where('product_variants.reorder_level', '>', 0)
                ->whereRaw('COALESCE(b.qty_on_hand, 0) <= product_variants.reorder_level'))
            ->when(! $lowStock, function ($q) use ($term) {
                $like = $this->like($term);
                $q->where(fn ($w) => $w->where('products.name', 'like', $like)
                    ->orWhere('product_variants.name', 'like', $like)
                    ->orWhere('product_variants.sku', 'like', $like)
                    ->orWhereIn('product_variants.id', DB::table('product_barcodes')->select('product_variant_id')
                        ->where('tenant_id', app(TenantContext::class)->requireId())->where('barcode', $term))
                    // A pack/box barcode selects the item in that unit.
                    ->orWhereIn('product_variants.id', DB::table('product_barcodes')->join('product_units', 'product_units.id', '=', 'product_barcodes.product_unit_id')
                        ->select('product_units.product_variant_id')
                        ->where('product_barcodes.tenant_id', app(TenantContext::class)->requireId())->where('product_barcodes.barcode', $term)));
            })
            ->orderBy('products.name')->orderBy('product_variants.name')
            ->limit($lowStock ? 200 : 20)
            ->get();

        $scannedUnit = $lowStock ? null : DB::table('product_barcodes')
            ->where('tenant_id', app(TenantContext::class)->requireId())->where('barcode', $term)->value('product_unit_id');

        return ApiResponse::data($variants->map(function (ProductVariant $v) use ($lowStock, $scannedUnit) {
            $onHand = BigDecimal::of($v->getAttribute('stock_qty'));
            $reorder = BigDecimal::of($v->reorder_level ?? '0');
            $units = collect([PurchasePresenter::unit($v, null, '1.0000')])
                ->merge($v->units->map(fn (ProductUnit $u) => PurchasePresenter::unit($v, $u, $u->conversion_factor)));

            return [
                'variant' => InventoryPresenter::variant($v),
                'units' => $units->values()->all(),
                'default_unit_id' => $v->units->contains('id', $scannedUnit) ? $scannedUnit : null,
                'last_cost' => $v->cost_price,
                'qty_on_hand' => (string) $onHand->toScale(3),
                'reorder_level' => $v->reorder_level,
                'suggested_qty' => $lowStock
                    ? (string) BigDecimal::max($reorder, $reorder->multipliedBy(2)->minus($onHand))->toScale(0, RoundingMode::Up)
                    : null,
            ];
        })->all());
    }
}

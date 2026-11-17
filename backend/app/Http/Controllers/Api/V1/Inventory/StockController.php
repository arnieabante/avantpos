<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Resources\Inventory\InventoryPresenter;
use App\Http\Resources\Inventory\StockLevelResource;
use App\Http\Resources\Inventory\StockMovementResource;
use App\Models\Branch;
use App\Models\GoodsReceipt;
use App\Models\InventoryBalance;
use App\Models\InventoryLayer;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockCount;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Rules\TenantExists;
use App\Services\Inventory\StockLedger;
use App\Support\Api\ApiResponse;
use App\Tenancy\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Read side of inventory: stock levels, item detail, ledger, alerts, valuation.
 */
class StockController extends InventoryController
{
    /** Days ahead that count as "expiring soon". */
    public const EXPIRY_WINDOW_DAYS = 30;

    /**
     * Stock levels of the selected branch, one row per stock-tracked variant.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', TenantExists::on('product_categories')],
            'status' => ['nullable', Rule::in(['low', 'out', 'in_stock', 'negative'])],
            'include_inactive' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branchId = $this->branch()->getKey();
        $qty = 'COALESCE(b.qty_on_hand, 0)';

        $levels = $this->trackedVariants($branchId)
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q
                ->where(fn ($w) => $w->where(fn ($a) => $a->where('product_variants.is_active', true)->where('products.is_active', true))
                    ->orWhereRaw("{$qty} <> 0")))
            ->when($request->filled('search'), fn ($q) => $this->applySearch($q, $request->string('search')->trim()->toString()))
            ->when($request->filled('category_id'), function ($q) use ($request) {
                $id = $request->integer('category_id');
                $q->whereIn('products.category_id', ProductCategory::query()->where('parent_id', $id)->pluck('id')->push($id));
            })
            ->when($request->input('status') === 'out', fn ($q) => $q->whereRaw("{$qty} <= 0"))
            ->when($request->input('status') === 'negative', fn ($q) => $q->whereRaw("{$qty} < 0"))
            ->when($request->input('status') === 'in_stock', fn ($q) => $q->whereRaw("{$qty} > 0"))
            ->when($request->input('status') === 'low', fn ($q) => $q->where('product_variants.reorder_level', '>', 0)
                ->whereRaw("{$qty} > 0")->whereRaw("{$qty} <= product_variants.reorder_level"))
            ->orderBy('products.name')->orderBy('product_variants.name')
            ->paginate($request->integer('per_page', 25));

        return StockLevelResource::collection($levels);
    }

    /**
     * One item: stock in every branch the user can see, and the open
     * batches / cost layers in the selected branch.
     */
    public function show(Request $request, ProductVariant $productVariant): JsonResponse
    {
        $productVariant->load('product.baseUnit');
        app(StockLedger::class)->assertTracked($productVariant);

        $branch = $this->branch();
        $canCost = InventoryPresenter::canSeeCost($request);
        $branches = $this->visibleBranches($request);
        $balances = InventoryBalance::query()->where('product_variant_id', $productVariant->getKey())
            ->whereIn('branch_id', $branches->pluck('id'))->get()->keyBy('branch_id');

        $layers = InventoryLayer::query()
            ->where('branch_id', $branch->getKey())
            ->where('product_variant_id', $productVariant->getKey())
            ->where('qty_remaining', '>', 0)
            ->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('received_at')->orderBy('id')
            ->get();

        return ApiResponse::data([
            'variant' => InventoryPresenter::variant($productVariant),
            'reorder_level' => $productVariant->reorder_level,
            'branches' => $branches->map(fn (Branch $b) => [
                'branch' => InventoryPresenter::branch($b),
                'qty_on_hand' => $balances[$b->id]->qty_on_hand ?? '0.000',
                'last_movement_at' => $balances->get($b->id)?->last_movement_at?->toIso8601String(),
            ] + ($canCost ? ['value' => $balances[$b->id]->total_cost ?? '0.00'] : []))->values()->all(),
            'layers' => $layers->map(fn (InventoryLayer $l) => [
                'id' => $l->id,
                'source_type' => $l->source_type,
                'batch_no' => $l->batch_no,
                'expiry_date' => $l->expiry_date?->toDateString(),
                'qty_received' => $l->qty_received,
                'qty_remaining' => $l->qty_remaining,
                'received_at' => $l->received_at?->toIso8601String(),
            ] + ($canCost ? ['unit_cost' => $l->unit_cost] : []))->all(),
        ]);
    }

    /**
     * The stock ledger of the selected branch.
     */
    public function movements(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'product_variant_id' => ['nullable', 'integer', TenantExists::on('product_variants')],
            'type' => ['nullable', Rule::in(StockMovement::TYPES)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $movements = StockMovement::query()
            ->with(['variant.product.baseUnit', 'branch', 'user'])
            ->where('branch_id', $this->branch()->getKey())
            ->when($request->filled('product_variant_id'), fn ($q) => $q->where('product_variant_id', $request->integer('product_variant_id')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('from'), fn ($q) => $q->where('occurred_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('occurred_at', '<=', $request->date('to')->endOfDay()))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $this->like($request->string('search')->trim()->toString());
                $q->whereHas('variant', fn ($v) => $v->where('sku', 'like', $term)->orWhere('name', 'like', $term)
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', $term)));
            })
            ->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate($request->integer('per_page', 50));

        StockMovementResource::$referenceNumbers = $this->referenceNumbers(collect($movements->items()));

        return StockMovementResource::collection($movements);
    }

    /**
     * Counts for the alert cards: low stock, out of stock, expiring, expired.
     */
    public function alerts(): JsonResponse
    {
        $branchId = $this->branch()->getKey();
        $qty = 'COALESCE(b.qty_on_hand, 0)';
        $active = fn () => $this->trackedVariants($branchId)->where('product_variants.is_active', true)->where('products.is_active', true);

        $expiring = InventoryLayer::query()->where('branch_id', $branchId)->where('qty_remaining', '>', 0)->whereNotNull('expiry_date');

        return ApiResponse::data([
            'low_stock' => $active()->where('product_variants.reorder_level', '>', 0)->whereRaw("{$qty} > 0")
                ->whereRaw("{$qty} <= product_variants.reorder_level")->count(),
            'out_of_stock' => $active()->whereRaw("{$qty} <= 0")->count(),
            'expiring' => (clone $expiring)->whereBetween('expiry_date', [today(), today()->addDays(self::EXPIRY_WINDOW_DAYS)])->count(),
            'expired' => (clone $expiring)->where('expiry_date', '<', today())->count(),
            'expiry_window_days' => self::EXPIRY_WINDOW_DAYS,
        ]);
    }

    /**
     * Batches that expire within the window (or already expired) and still have stock.
     */
    public function expiring(Request $request): JsonResponse
    {
        $canCost = InventoryPresenter::canSeeCost($request);
        $layers = InventoryLayer::query()
            ->with('variant.product.baseUnit')
            ->where('branch_id', $this->branch()->getKey())
            ->where('qty_remaining', '>', 0)
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<=', today()->addDays(self::EXPIRY_WINDOW_DAYS))
            ->orderBy('expiry_date')
            ->limit(500)
            ->get();

        return ApiResponse::data($layers->map(fn (InventoryLayer $l) => [
            'id' => $l->id,
            'variant' => InventoryPresenter::variant($l->variant),
            'batch_no' => $l->batch_no,
            'expiry_date' => $l->expiry_date?->toDateString(),
            'qty_remaining' => $l->qty_remaining,
            'expired' => $l->expiry_date->isBefore(today()),
        ] + ($canCost ? ['value' => (string) BigDecimal::of($l->qty_remaining)->multipliedBy($l->unit_cost)->toScale(2, RoundingMode::HalfUp)] : []))->all());
    }

    /**
     * Inventory value per branch (all branches the user can see) and per
     * category in the selected branch. Requires inventory.valuation.
     */
    public function valuation(Request $request): JsonResponse
    {
        $branches = $this->visibleBranches($request);

        $perBranch = InventoryBalance::query()
            ->whereIn('branch_id', $branches->pluck('id'))
            ->groupBy('branch_id')
            ->selectRaw('branch_id, SUM(total_cost) AS value, SUM(CASE WHEN qty_on_hand > 0 THEN 1 ELSE 0 END) AS items_in_stock')
            ->get()->keyBy('branch_id');

        $perCategory = InventoryBalance::query()
            ->join('product_variants', 'product_variants.id', '=', 'inventory_balances.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('product_categories as c', 'c.id', '=', 'products.category_id')
            ->leftJoin('product_categories as parent', 'parent.id', '=', 'c.parent_id')
            ->where('inventory_balances.branch_id', $this->branch()->getKey())
            ->where('inventory_balances.total_cost', '!=', 0)
            ->groupByRaw('COALESCE(parent.id, c.id)')
            ->selectRaw('COALESCE(parent.id, c.id) AS category_id, MAX(COALESCE(parent.name, c.name)) AS category_name, SUM(inventory_balances.total_cost) AS value, SUM(inventory_balances.qty_on_hand) AS qty')
            ->orderByDesc('value')
            ->get();

        $rows = $branches->map(fn (Branch $b) => [
            'branch' => InventoryPresenter::branch($b),
            'value' => (string) BigDecimal::of($perBranch[$b->id]->value ?? '0')->toScale(2),
            'items_in_stock' => (int) ($perBranch[$b->id]->items_in_stock ?? 0),
        ])->values();

        return ApiResponse::data([
            'branches' => $rows->all(),
            'total' => (string) $rows->reduce(fn (BigDecimal $sum, $r) => $sum->plus($r['value']), BigDecimal::zero())->toScale(2),
            'categories' => $perCategory->map(fn ($c) => [
                'category' => $c->category_id ? ['id' => (int) $c->category_id, 'name' => $c->category_name] : null,
                'value' => (string) BigDecimal::of($c->value)->toScale(2),
            ])->all(),
            'method' => 'FIFO',
        ]);
    }

    /**
     * Item picker for inventory documents: stock-tracked variants matching a
     * name, SKU or exact barcode, with the quantity on hand in the branch.
     */
    public function picker(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']]);
        $term = $request->string('q')->trim()->toString();
        $canCost = InventoryPresenter::canSeeCost($request);
        $branchId = $this->branch()->getKey();

        $variants = $this->applySearch($this->trackedVariants($branchId), $term)
            ->where('product_variants.is_active', true)
            ->orderByRaw('product_variants.sku = ? DESC', [$term])
            ->orderBy('products.name')->orderBy('product_variants.name')
            ->limit(20)
            ->get();

        $ledger = app(StockLedger::class);

        return ApiResponse::data($variants->map(fn (ProductVariant $v) => [
            'variant' => InventoryPresenter::variant($v),
            'qty_on_hand' => (string) BigDecimal::of($v->getAttribute('stock_qty'))->toScale(3),
        ] + ($canCost ? ['current_cost' => $ledger->currentUnitCost($branchId, $v)] : []))->all());
    }

    /**
     * Branches stock can be sent to: every other active branch of the
     * business (the receiving branch need not be one of the sender's own).
     */
    public function destinations(): JsonResponse
    {
        $from = $this->branch()->getKey();

        return ApiResponse::data(Branch::query()->where('is_active', true)->whereKeyNot($from)
            ->orderByDesc('is_main')->orderBy('name')->get()
            ->map(fn (Branch $b) => InventoryPresenter::branch($b))->all());
    }

    /**
     * Name / variant name / SKU contains the term, or a barcode equals it.
     *
     * @param  Builder<ProductVariant>  $query
     * @return Builder<ProductVariant>
     */
    private function applySearch(Builder $query, string $term): Builder
    {
        $like = $this->like($term);

        return $query->where(fn ($w) => $w
            ->where('products.name', 'like', $like)
            ->orWhere('product_variants.name', 'like', $like)
            ->orWhere('product_variants.sku', 'like', $like)
            ->orWhereIn('product_variants.id', DB::table('product_barcodes')->select('product_variant_id')
                ->where('tenant_id', app(TenantContext::class)->requireId())
                ->where('barcode', $term)));
    }

    /**
     * Stock-tracked variants of non-deleted products, with this branch's balance.
     *
     * @return Builder<ProductVariant>
     */
    private function trackedVariants(int $branchId): Builder
    {
        return ProductVariant::query()
            ->with('product.baseUnit')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('inventory_balances as b', fn ($j) => $j->on('b.product_variant_id', '=', 'product_variants.id')->where('b.branch_id', $branchId))
            ->whereNull('products.deleted_at')
            ->where('products.type', 'standard')
            ->where('products.track_inventory', true)
            ->select('product_variants.*')
            ->selectRaw('COALESCE(b.qty_on_hand, 0) AS stock_qty, COALESCE(b.total_cost, 0) AS stock_value, b.last_movement_at AS stock_last_movement_at');
    }

    /**
     * Branches whose stock the user may see.
     *
     * @return Collection<int, Branch>
     */
    private function visibleBranches(Request $request): Collection
    {
        $user = $this->actor($request);

        return Branch::query()
            ->when(! $user->canAccessAllBranches(), fn ($q) => $q->whereIn('id', $user->branches()->pluck('branches.id')))
            ->orderByDesc('is_main')->orderBy('name')
            ->get();
    }

    /**
     * @param  Collection<int, StockMovement>  $movements
     * @return array<string, string>
     */
    private function referenceNumbers(Collection $movements): array
    {
        $models = ['stock_adjustment' => StockAdjustment::class, 'stock_transfer' => StockTransfer::class, 'stock_count' => StockCount::class, 'goods_receipt' => GoodsReceipt::class];
        $numbers = [];

        foreach ($movements->whereNotNull('reference_type')->groupBy('reference_type') as $type => $group) {
            if (! isset($models[$type])) {
                continue;
            }
            foreach ($models[$type]::query()->whereKey($group->pluck('reference_id')->unique())->pluck('number', 'id') as $id => $number) {
                $numbers["{$type}:{$id}"] = $number;
            }
        }

        return $numbers;
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Resources\Inventory\StockCountItemResource;
use App\Http\Resources\Inventory\StockCountResource;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Rules\TenantExists;
use App\Services\Inventory\StockCountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class StockCountController extends InventoryController
{
    public function __construct(private readonly StockCountService $counts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['counting', 'finalized', 'cancelled'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $counts = $this->withSummary(StockCount::query())
            ->where('branch_id', $this->branch()->getKey())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return StockCountResource::collection($counts);
    }

    public function show(Request $request, StockCount $stockCount): StockCountResource
    {
        $this->assertBranchAccess($request, $stockCount->branch_id);

        return new StockCountResource($this->withSummary(StockCount::query())->findOrFail($stockCount->getKey()));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer', TenantExists::on('product_categories')],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $count = $this->counts->create($this->branch(), $data, $this->actor($request));

        return (new StockCountResource($this->withSummary(StockCount::query())->findOrFail($count->getKey())))->response()->setStatusCode(201);
    }

    /**
     * The count sheet, searchable and filterable.
     */
    public function items(Request $request, StockCount $stockCount): AnonymousResourceCollection
    {
        $this->assertBranchAccess($request, $stockCount->branch_id);
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', Rule::in(['uncounted', 'counted', 'variance'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $items = StockCountItem::query()
            ->with('variant.product.baseUnit')
            ->where('stock_count_id', $stockCount->getKey())
            ->join('product_variants', 'product_variants.id', '=', 'stock_count_items.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->select('stock_count_items.*')
            ->when($request->filled('search'), function ($q) use ($request) {
                $raw = $request->string('search')->trim()->toString();
                $like = $this->like($raw);
                $q->where(fn ($w) => $w->where('products.name', 'like', $like)
                    ->orWhere('product_variants.name', 'like', $like)
                    ->orWhere('product_variants.sku', 'like', $like)
                    ->orWhereHas('variant.barcodes', fn ($b) => $b->where('barcode', $raw)));
            })
            ->when($request->input('filter') === 'uncounted', fn ($q) => $q->whereNull('stock_count_items.counted_qty'))
            ->when($request->input('filter') === 'counted', fn ($q) => $q->whereNotNull('stock_count_items.counted_qty'))
            ->when($request->input('filter') === 'variance', fn ($q) => $q->where('stock_count_items.variance_qty', '!=', 0))
            ->orderBy('products.name')->orderBy('product_variants.name')
            ->paginate($request->integer('per_page', 50));

        return StockCountItemResource::collection($items);
    }

    /**
     * Save counted quantities: { "entries": { "<item id>": "12", "<item id>": null } }.
     */
    public function record(Request $request, StockCount $stockCount): StockCountResource
    {
        $this->assertBranchAccess($request, $stockCount->branch_id);
        $data = $request->validate([
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*' => ['nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:9999999'],
        ], ['entries.*.min' => 'A count cannot be negative.']);

        $entries = collect($data['entries'])
            ->mapWithKeys(fn ($qty, $id) => [(int) $id => $qty === null ? null : (string) $qty])
            ->all();

        $this->counts->record($stockCount, $entries);

        return new StockCountResource($this->withSummary(StockCount::query())->findOrFail($stockCount->getKey()));
    }

    public function finalize(Request $request, StockCount $stockCount): StockCountResource
    {
        $this->assertBranchAccess($request, $stockCount->branch_id);
        $this->counts->finalize($stockCount, $this->actor($request));

        return new StockCountResource($this->withSummary(StockCount::query())->findOrFail($stockCount->getKey()));
    }

    public function cancel(Request $request, StockCount $stockCount): StockCountResource
    {
        $this->assertBranchAccess($request, $stockCount->branch_id);
        $this->counts->cancel($stockCount);

        return new StockCountResource($this->withSummary(StockCount::query())->findOrFail($stockCount->getKey()));
    }

    /**
     * @param  Builder<StockCount>  $query
     * @return Builder<StockCount>
     */
    private function withSummary(Builder $query): Builder
    {
        return $query->with(['branch', 'creator', 'finalizer'])->withCount([
            'items',
            'items as counted_count' => fn ($q) => $q->whereNotNull('counted_qty'),
            'items as variance_count' => fn ($q) => $q->where('variance_qty', '!=', 0),
        ]);
    }
}

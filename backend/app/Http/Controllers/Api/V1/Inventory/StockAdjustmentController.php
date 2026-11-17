<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Http\Requests\Inventory\StockAdjustmentRequest;
use App\Http\Resources\Inventory\StockAdjustmentResource;
use App\Models\StockAdjustment;
use App\Services\Inventory\StockAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class StockAdjustmentController extends InventoryController
{
    private const RELATIONS = ['branch', 'creator', 'poster', 'items.variant.product.baseUnit'];

    public function __construct(private readonly StockAdjustmentService $adjustments) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'posted', 'cancelled'])],
            'reason_code' => ['nullable', Rule::in(StockAdjustment::REASONS)],
            'search' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $adjustments = StockAdjustment::query()
            ->with(['branch', 'creator', 'poster'])
            ->withCount('items')
            ->where('branch_id', $this->branch()->getKey())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('reason_code'), fn ($q) => $q->where('reason_code', $request->input('reason_code')))
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', $this->like($request->string('search')->trim()->toString())))
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return StockAdjustmentResource::collection($adjustments);
    }

    public function show(Request $request, StockAdjustment $stockAdjustment): StockAdjustmentResource
    {
        $this->assertBranchAccess($request, $stockAdjustment->branch_id);

        return new StockAdjustmentResource($stockAdjustment->load(self::RELATIONS));
    }

    public function store(StockAdjustmentRequest $request): JsonResponse
    {
        $adjustment = $this->adjustments->create($this->branch(), $request->validated(), $this->actor($request), $request->boolean('post'));

        return (new StockAdjustmentResource($adjustment->fresh(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(StockAdjustmentRequest $request, StockAdjustment $stockAdjustment): StockAdjustmentResource
    {
        $this->assertBranchAccess($request, $stockAdjustment->branch_id);
        $adjustment = $this->adjustments->update($stockAdjustment, $request->validated(), $this->actor($request), $request->boolean('post'));

        return new StockAdjustmentResource($adjustment->fresh(self::RELATIONS));
    }

    public function post(Request $request, StockAdjustment $stockAdjustment): StockAdjustmentResource
    {
        $this->assertBranchAccess($request, $stockAdjustment->branch_id);
        $adjustment = $this->adjustments->post($stockAdjustment, $this->actor($request));

        return new StockAdjustmentResource($adjustment->fresh(self::RELATIONS));
    }

    public function cancel(Request $request, StockAdjustment $stockAdjustment): StockAdjustmentResource
    {
        $this->assertBranchAccess($request, $stockAdjustment->branch_id);

        return new StockAdjustmentResource($this->adjustments->cancel($stockAdjustment)->fresh(self::RELATIONS));
    }
}

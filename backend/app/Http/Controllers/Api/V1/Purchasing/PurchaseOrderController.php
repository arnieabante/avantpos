<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Api\V1\Inventory\InventoryController;
use App\Http\Requests\Purchasing\PurchaseOrderRequest;
use App\Http\Resources\Purchasing\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use App\Rules\TenantExists;
use App\Services\Purchasing\PurchaseOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends InventoryController
{
    private const RELATIONS = ['supplier', 'branch', 'creator', 'approver', 'items.variant.product.baseUnit', 'items.productUnit.unit', 'receipts'];

    public function __construct(private readonly PurchaseOrderService $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'approved', 'partially_received', 'received', 'cancelled', 'closed', 'open'])],
            'supplier_id' => ['nullable', 'integer', TenantExists::on('suppliers')],
            'search' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = PurchaseOrder::query()
            ->with(['supplier', 'branch', 'creator', 'approver'])
            ->withCount('items')
            ->where('branch_id', $this->branch()->getKey())
            ->when($request->input('status') === 'open', fn ($q) => $q->whereIn('status', PurchaseOrder::RECEIVABLE))
            ->when($request->filled('status') && $request->input('status') !== 'open', fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', $this->like($request->string('search')->trim()->toString())))
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return PurchaseOrderResource::collection($orders);
    }

    public function show(Request $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->assertBranchAccess($request, $purchaseOrder->branch_id);

        return new PurchaseOrderResource($purchaseOrder->load(self::RELATIONS));
    }

    public function store(PurchaseOrderRequest $request): JsonResponse
    {
        $po = $this->orders->create($this->branch(), $request->validated(), $this->actor($request), $request->boolean('approve'));

        return (new PurchaseOrderResource($po->fresh(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(PurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->assertBranchAccess($request, $purchaseOrder->branch_id);
        $po = $this->orders->update($purchaseOrder, $request->validated(), $this->actor($request), $request->boolean('approve'));

        return new PurchaseOrderResource($po->fresh(self::RELATIONS));
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->assertBranchAccess($request, $purchaseOrder->branch_id);

        return new PurchaseOrderResource($this->orders->approve($purchaseOrder, $this->actor($request))->fresh(self::RELATIONS));
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->assertBranchAccess($request, $purchaseOrder->branch_id);

        return new PurchaseOrderResource($this->orders->cancel($purchaseOrder)->fresh(self::RELATIONS));
    }

    public function close(Request $request, PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $this->assertBranchAccess($request, $purchaseOrder->branch_id);

        return new PurchaseOrderResource($this->orders->close($purchaseOrder)->fresh(self::RELATIONS));
    }
}

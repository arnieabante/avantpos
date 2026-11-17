<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Api\V1\Inventory\InventoryController;
use App\Http\Requests\Purchasing\GoodsReceiptRequest;
use App\Http\Resources\Purchasing\GoodsReceiptResource;
use App\Models\GoodsReceipt;
use App\Rules\TenantExists;
use App\Services\Purchasing\GoodsReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GoodsReceiptController extends InventoryController
{
    private const RELATIONS = ['supplier', 'branch', 'purchaseOrder', 'receiver', 'reverser', 'items.variant.product.baseUnit', 'items.productUnit.unit'];

    public function __construct(private readonly GoodsReceiptService $receipts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', 'in:posted,reversed'],
            'supplier_id' => ['nullable', 'integer', TenantExists::on('suppliers')],
            'search' => ['nullable', 'string', 'max:60'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $receipts = GoodsReceipt::query()
            ->with(['supplier', 'branch', 'purchaseOrder', 'receiver', 'reverser'])
            ->withCount('items')
            ->where('branch_id', $this->branch()->getKey())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('supplier_id'), fn ($q) => $q->where('supplier_id', $request->integer('supplier_id')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = $this->like($request->string('search')->trim()->toString());
                $q->where(fn ($w) => $w->where('number', 'like', $term)->orWhere('supplier_invoice_no', 'like', $term));
            })
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return GoodsReceiptResource::collection($receipts);
    }

    public function show(Request $request, GoodsReceipt $goodsReceipt): GoodsReceiptResource
    {
        $this->assertBranchAccess($request, $goodsReceipt->branch_id);

        return new GoodsReceiptResource($goodsReceipt->load(self::RELATIONS));
    }

    public function store(GoodsReceiptRequest $request): JsonResponse
    {
        $receipt = $this->receipts->receive($this->branch(), $request->validated(), $this->actor($request));

        return (new GoodsReceiptResource($receipt->fresh(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function reverse(Request $request, GoodsReceipt $goodsReceipt): GoodsReceiptResource
    {
        $this->assertBranchAccess($request, $goodsReceipt->branch_id);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:200']]);

        return new GoodsReceiptResource($this->receipts->reverse($goodsReceipt, $this->actor($request), $data['reason'])->fresh(self::RELATIONS));
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Inventory;

use App\Exceptions\DomainException;
use App\Http\Requests\Inventory\StockTransferRequest;
use App\Http\Resources\Inventory\StockTransferResource;
use App\Models\StockTransfer;
use App\Services\Inventory\StockTransferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Transfers are created and dispatched by the sending branch
 * (inventory.transfer) and received by the receiving branch
 * (inventory.receive_transfer).
 */
class StockTransferController extends InventoryController
{
    private const RELATIONS = ['fromBranch', 'toBranch', 'creator', 'dispatcher', 'receiver', 'items.variant.product.baseUnit'];

    public function __construct(private readonly StockTransferService $transfers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'direction' => ['nullable', Rule::in(['outgoing', 'incoming'])],
            'status' => ['nullable', Rule::in(['draft', 'in_transit', 'received', 'cancelled'])],
            'search' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $branchId = $this->branch()->getKey();
        $direction = $request->input('direction');

        $transfers = StockTransfer::query()
            ->with(['fromBranch', 'toBranch', 'creator', 'dispatcher', 'receiver'])
            ->withCount('items')
            ->where(fn ($q) => $q
                ->when($direction !== 'incoming', fn ($w) => $w->orWhere('from_branch_id', $branchId))
                // Drafts are private to the sending branch.
                ->when($direction !== 'outgoing', fn ($w) => $w->orWhere(fn ($in) => $in->where('to_branch_id', $branchId)->where('status', '!=', 'draft'))))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('search'), fn ($q) => $q->where('number', 'like', $this->like($request->string('search')->trim()->toString())))
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return StockTransferResource::collection($transfers);
    }

    public function show(Request $request, StockTransfer $stockTransfer): StockTransferResource
    {
        $this->assertBranchAccess($request, $stockTransfer->from_branch_id, ...($stockTransfer->status === 'draft' ? [] : [$stockTransfer->to_branch_id]));

        return new StockTransferResource($stockTransfer->load(self::RELATIONS));
    }

    public function store(StockTransferRequest $request): JsonResponse
    {
        $transfer = $this->transfers->create($this->branch(), $request->validated(), $this->actor($request), $request->boolean('dispatch'));

        return (new StockTransferResource($transfer->fresh(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function update(StockTransferRequest $request, StockTransfer $stockTransfer): StockTransferResource
    {
        $this->assertBranchAccess($request, $stockTransfer->from_branch_id);
        $transfer = $this->transfers->update($stockTransfer, $request->validated(), $this->actor($request), $request->boolean('dispatch'));

        return new StockTransferResource($transfer->fresh(self::RELATIONS));
    }

    public function send(Request $request, StockTransfer $stockTransfer): StockTransferResource
    {
        $this->assertBranchAccess($request, $stockTransfer->from_branch_id);

        return new StockTransferResource($this->transfers->dispatch($stockTransfer, $this->actor($request))->fresh(self::RELATIONS));
    }

    public function receive(Request $request, StockTransfer $stockTransfer): StockTransferResource
    {
        $this->assertBranchAccess($request, $stockTransfer->from_branch_id, $stockTransfer->to_branch_id);
        if (! $this->actor($request)->canAccessBranch($stockTransfer->to_branch_id)) {
            throw new DomainException('Only the receiving branch can receive this transfer.', 'FORBIDDEN', 403);
        }

        $data = $request->validate([
            'items' => ['sometimes', 'array'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.qty_received' => ['required', 'numeric', 'decimal:0,3', 'min:0'],
        ]);

        $received = collect($data['items'] ?? [])->mapWithKeys(fn ($line) => [(int) $line['id'] => (string) $line['qty_received']])->all();

        return new StockTransferResource($this->transfers->receive($stockTransfer, $received, $this->actor($request))->fresh(self::RELATIONS));
    }

    public function cancel(Request $request, StockTransfer $stockTransfer): StockTransferResource
    {
        $this->assertBranchAccess($request, $stockTransfer->from_branch_id);

        return new StockTransferResource($this->transfers->cancel($stockTransfer)->fresh(self::RELATIONS));
    }
}

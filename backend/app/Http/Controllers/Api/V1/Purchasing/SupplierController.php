<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchasing\SupplierRequest;
use App\Http\Resources\Purchasing\SupplierResource;
use App\Models\Supplier;
use App\Services\Purchasing\SupplierService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupplierController extends Controller
{
    public function __construct(private readonly SupplierService $suppliers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $suppliers = Supplier::query()
            ->withCount(['purchaseOrders as open_orders_count' => fn ($q) => $q->whereIn('status', ['approved', 'partially_received'])])
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $request->string('search')->trim()->toString()).'%';
                $q->where(fn ($w) => $w->where('name', 'like', $term)->orWhere('code', 'like', $term)
                    ->orWhere('contact_person', 'like', $term)->orWhere('phone', 'like', $term));
            })
            ->when($request->input('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->input('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return SupplierResource::collection($suppliers);
    }

    /**
     * Active suppliers for pickers (id, name, VAT status).
     */
    public function options(): JsonResponse
    {
        return ApiResponse::data(Supplier::query()->where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'vat_registered', 'payment_terms_days'])
            ->map(fn (Supplier $s) => ['id' => $s->id, 'name' => $s->name, 'vat_registered' => $s->vat_registered, 'payment_terms_days' => $s->payment_terms_days])
            ->all());
    }

    public function show(Supplier $supplier): SupplierResource
    {
        return new SupplierResource($supplier);
    }

    public function store(SupplierRequest $request): JsonResponse
    {
        return (new SupplierResource($this->suppliers->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(SupplierRequest $request, Supplier $supplier): SupplierResource
    {
        return new SupplierResource($this->suppliers->update($supplier, $request->validated()));
    }

    public function activate(Supplier $supplier): SupplierResource
    {
        return new SupplierResource($this->suppliers->setActive($supplier, true));
    }

    public function deactivate(Supplier $supplier): SupplierResource
    {
        return new SupplierResource($this->suppliers->setActive($supplier, false));
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->suppliers->delete($supplier);

        return ApiResponse::noContent();
    }
}

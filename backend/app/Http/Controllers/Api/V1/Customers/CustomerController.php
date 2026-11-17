<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\CustomerRequest;
use App\Http\Resources\Customers\CustomerResource;
use App\Http\Resources\Inventory\InventoryPresenter;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use App\Rules\TenantExists;
use App\Services\Customers\CustomerAccountService;
use App\Services\Customers\CustomerService;
use App\Services\Customers\LoyaltyService;
use App\Support\Api\ApiResponse;
use App\Support\Money\Money;
use App\Tenancy\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly CustomerAccountService $accounts,
        private readonly LoyaltyService $loyalty,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'customer_group_id' => ['nullable', 'integer', TenantExists::on('customer_groups')],
            'status' => ['nullable', 'in:active,inactive'],
            'with_balance' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $customers = $this->search(Customer::query()->with('group'), $request->string('search')->trim()->toString())
            ->when($request->filled('customer_group_id'), fn ($q) => $q->where('customer_group_id', $request->integer('customer_group_id')))
            ->when($request->input('status') === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->input('status') === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($request->boolean('with_balance'), fn ($q) => $q->where('balance', '>', 0))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return CustomerResource::collection($customers);
    }

    /**
     * Quick lookup for the POS: active customers by name, phone or code (max 10).
     */
    public function lookup(Request $request): AnonymousResourceCollection
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        return CustomerResource::collection(
            $this->search(Customer::query()->with('group')->where('is_active', true), $request->string('q')->trim()->toString())
                ->orderBy('name')->limit(10)->get()
        );
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer->load('group'));
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        return (new CustomerResource($this->customers->create($request->validated())->load('group')))->response()->setStatusCode(201);
    }

    public function update(CustomerRequest $request, Customer $customer): CustomerResource
    {
        return new CustomerResource($this->customers->update($customer, $request->validated())->load('group'));
    }

    public function activate(Customer $customer): CustomerResource
    {
        return new CustomerResource($this->customers->setActive($customer, true)->load('group'));
    }

    public function deactivate(Customer $customer): CustomerResource
    {
        return new CustomerResource($this->customers->setActive($customer, false)->load('group'));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->customers->delete($customer);

        return ApiResponse::noContent();
    }

    /* ------------------------------------------------------------ Account (utang) */

    /**
     * Statement: every charge and payment, newest first, with running balance.
     */
    public function ledger(Request $request, Customer $customer): JsonResponse
    {
        $entries = CustomerLedgerEntry::query()->with(['user', 'paymentMethod', 'branch'])
            ->where('customer_id', $customer->getKey())
            ->latest('id')
            ->paginate($request->integer('per_page', 25));

        return ApiResponse::data(collect($entries->items())->map(fn (CustomerLedgerEntry $e) => [
            'id' => $e->id,
            'type' => $e->type,
            'number' => $e->number,
            'amount' => $e->amount,
            'balance_after' => $e->balance_after,
            'payment_method' => $e->paymentMethod?->name,
            'reference_no' => $e->reference_no,
            'notes' => $e->notes,
            'branch' => InventoryPresenter::branch($e->branch),
            'user' => InventoryPresenter::user($e->user),
            'created_at' => $e->created_at?->toIso8601String(),
        ])->all(), meta: ['pagination' => [
            'current_page' => $entries->currentPage(), 'per_page' => $entries->perPage(),
            'total' => $entries->total(), 'last_page' => $entries->lastPage(),
        ]]);
    }

    public function openingBalance(Request $request, Customer $customer): CustomerResource
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $this->accounts->openingBalance($customer, (string) $data['amount'], $data['notes'] ?? null, $this->actor($request));

        return new CustomerResource($customer->refresh()->load('group'));
    }

    public function payment(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:9999999999'],
            'payment_method_id' => ['required', 'integer', TenantExists::on('payment_methods')],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $data['amount'] = (string) $data['amount'];
        $entry = $this->accounts->recordPayment($customer, $data, $this->actor($request), app(BranchContext::class)->id());

        return ApiResponse::data([
            'number' => $entry->number,
            'amount' => (string) Money::of($entry->amount)->abs(),
            'balance_after' => $entry->balance_after,
            'customer' => (new CustomerResource($customer->refresh()->load('group')))->toArray($request),
        ], 201);
    }

    public function adjustBalance(Request $request, Customer $customer): CustomerResource
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'decimal:0,2', 'not_in:0', 'between:-9999999999,9999999999'],
            'notes' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $this->accounts->adjust($customer, (string) $data['amount'], $data['notes'], $this->actor($request));

        return new CustomerResource($customer->refresh()->load('group'));
    }

    /* ------------------------------------------------------------ Loyalty */

    public function loyaltyHistory(Request $request, Customer $customer): JsonResponse
    {
        $entries = LoyaltyTransaction::query()->with('user')->where('customer_id', $customer->getKey())
            ->latest('id')->paginate($request->integer('per_page', 25));

        return ApiResponse::data(collect($entries->items())->map(fn (LoyaltyTransaction $t) => [
            'id' => $t->id,
            'points' => $t->points,
            'balance_after' => $t->balance_after,
            'reason' => $t->reason,
            'notes' => $t->notes,
            'user' => InventoryPresenter::user($t->user),
            'created_at' => $t->created_at?->toIso8601String(),
        ])->all(), meta: ['pagination' => [
            'current_page' => $entries->currentPage(), 'per_page' => $entries->perPage(),
            'total' => $entries->total(), 'last_page' => $entries->lastPage(),
        ]]);
    }

    public function adjustPoints(Request $request, Customer $customer): CustomerResource
    {
        $data = $request->validate([
            'points' => ['required', 'numeric', 'decimal:0,2', 'not_in:0', 'between:-9999999,9999999'],
            'notes' => ['required', 'string', 'min:3', 'max:255'],
        ]);
        $this->loyalty->adjust($customer, (string) $data['points'], $data['notes'], $this->actor($request));

        return new CustomerResource($customer->refresh()->load('group'));
    }

    /**
     * @param  Builder<Customer>  $query
     * @return Builder<Customer>
     */
    private function search($query, string $term)
    {
        if ($term === '') {
            return $query;
        }
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';
        $digits = preg_replace('/\D/', '', $term);
        // "0917 123 4567" and "+639171234567" both find the stored +63 number.
        $phone = strlen($digits) >= 4 ? '%'.ltrim(str_starts_with($digits, '0') ? substr($digits, 1) : $digits, '0').'%' : null;

        return $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like)
            ->when($phone, fn ($p) => $p->orWhere('phone', 'like', $phone)));
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}

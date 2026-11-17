<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Services\Audit\AuditLogger;
use App\Support\Api\ApiResponse;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerGroupController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return ApiResponse::data(CustomerGroup::query()->withCount('customers')->orderBy('name')->get()
            ->map(fn (CustomerGroup $g) => $this->present($g))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $group = CustomerGroup::query()->create($this->validated($request));
        $this->audit->log('customer_group.created', $group, [], $group->only(['name', 'price_tier', 'discount_percent']));

        return ApiResponse::data($this->present($group->loadCount('customers')), 201);
    }

    public function update(Request $request, CustomerGroup $customerGroup): JsonResponse
    {
        $customerGroup->fill($this->validated($request, $customerGroup))->save();
        $this->audit->logChanges('customer_group.updated', $customerGroup);

        return ApiResponse::data($this->present($customerGroup->loadCount('customers')));
    }

    public function destroy(CustomerGroup $customerGroup): JsonResponse
    {
        if (Customer::withTrashed()->where('customer_group_id', $customerGroup->getKey())->exists()) {
            throw new DomainException('Move the customers in this group to another group first.', 'GROUP_IN_USE', 409);
        }
        $this->audit->log('customer_group.deleted', $customerGroup, ['name' => $customerGroup->name]);
        $customerGroup->delete();

        return ApiResponse::noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?CustomerGroup $group = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('customer_groups', 'name')
                ->where('tenant_id', app(TenantContext::class)->requireId())->ignore($group?->id)],
            'price_tier' => ['required', Rule::in(CustomerGroup::PRICE_TIERS)],
            'discount_percent' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
        ], ['name.unique' => 'This group already exists.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(CustomerGroup $g): array
    {
        return [
            'id' => $g->id,
            'name' => $g->name,
            'price_tier' => $g->price_tier,
            'discount_percent' => $g->discount_percent,
            'customers_count' => (int) ($g->customers_count ?? 0),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\RoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\RoleService;
use App\Support\Api\ApiResponse;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tenant roles. Roles are spatie models (not tenant-scoped), so every
 * lookup goes through Role::forCurrentTenant() — never route model binding.
 */
class RoleController extends Controller
{
    public function __construct(private readonly RoleService $roles) {}

    public function index(): AnonymousResourceCollection
    {
        $roles = Role::forCurrentTenant()
            ->with('permissions')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return RoleResource::collection($this->withUserCounts($roles));
    }

    public function store(RoleRequest $request): JsonResponse
    {
        $role = $this->roles->create($request->user(), $request->validated());

        return (new RoleResource($role))->response()->setStatusCode(201);
    }

    public function show(int $role): RoleResource
    {
        $model = Role::forCurrentTenant()->with('permissions')->findOrFail($role);

        return new RoleResource($this->withUserCounts(collect([$model]))->first());
    }

    public function update(RoleRequest $request, int $role): RoleResource
    {
        $model = Role::forCurrentTenant()->with('permissions')->findOrFail($role);

        return new RoleResource($this->roles->update($request->user(), $model, $request->validated()));
    }

    public function destroy(Request $request, int $role): JsonResponse
    {
        $this->roles->delete(Role::forCurrentTenant()->with('permissions')->findOrFail($role));

        return ApiResponse::noContent();
    }

    /**
     * Sets users_count (users of this business, excluding deleted ones).
     * Spatie's users() relation cannot be counted with withCount().
     *
     * @param  Collection<int, Role>  $roles
     * @return Collection<int, Role>
     */
    private function withUserCounts(Collection $roles): Collection
    {
        $counts = DB::table(config('permission.table_names.model_has_roles').' as mhr')
            ->join('users', 'users.id', '=', 'mhr.model_id')
            ->where('mhr.model_type', (new User)->getMorphClass())
            ->where('mhr.tenant_id', app(TenantContext::class)->requireId())
            ->whereNull('users.deleted_at')
            ->whereIn('mhr.role_id', $roles->pluck('id'))
            ->groupBy('mhr.role_id')
            ->pluck(DB::raw('COUNT(*)'), 'mhr.role_id');

        return $roles->each(fn (Role $role) => $role->setAttribute('users_count', (int) ($counts[$role->id] ?? 0)));
    }
}

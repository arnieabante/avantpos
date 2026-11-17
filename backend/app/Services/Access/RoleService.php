<?php

namespace App\Services\Access;

use App\Exceptions\DomainException;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\PlanGate;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Tenant role management (custom roles and edits to non-locked system roles).
 */
class RoleService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly RoleAssignmentGuard $guard,
        private readonly PermissionCatalog $catalog,
        private readonly PlanGate $plan,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, description?: ?string, permissions: list<string>}  $data
     */
    public function create(User $actor, array $data): Role
    {
        $this->plan->assertFeature('custom_roles', 'Custom roles');
        $this->guard->assertCanGrantPermissions($actor, $data['permissions']);

        return DB::transaction(function () use ($data) {
            $role = new Role;
            $role->fill([
                'name' => $data['name'],
                'guard_name' => $this->catalog->guard(),
                'description' => $data['description'] ?? null,
                'is_system' => false,
                'is_locked' => false,
            ]);
            $role->tenant_id = $this->context->requireId();
            $role->save();
            $role->syncPermissions($data['permissions']);

            $this->audit->log('role.created', $role, new: [
                'name' => $role->name,
                'permissions' => $data['permissions'],
            ]);

            return $role->load('permissions');
        });
    }

    /**
     * @param  array{name?: string, description?: ?string, permissions?: list<string>}  $data
     */
    public function update(User $actor, Role $role, array $data): Role
    {
        $this->assertEditable($role);

        $before = $role->permissions->pluck('name')->sort()->values()->all();

        if (isset($data['permissions'])) {
            // Only newly ADDED permissions need the escalation check.
            $this->guard->assertCanGrantPermissions($actor, array_diff($data['permissions'], $before));
        }

        return DB::transaction(function () use ($role, $data, $before) {
            $oldAttributes = $role->only(['name', 'description']);
            $role->fill(array_intersect_key($data, array_flip(['name', 'description'])));
            $role->save();

            if (isset($data['permissions'])) {
                $role->syncPermissions($data['permissions']);
            }

            $after = $role->load('permissions')->permissions->pluck('name')->sort()->values()->all();

            $this->audit->log('role.updated', $role,
                old: $oldAttributes + ['permissions' => $before],
                new: $role->only(['name', 'description']) + ['permissions' => $after],
                meta: [
                    'added' => array_values(array_diff($after, $before)),
                    'removed' => array_values(array_diff($before, $after)),
                ],
            );

            return $role;
        });
    }

    public function delete(Role $role): void
    {
        $this->assertEditable($role);

        if ($role->is_system) {
            throw new DomainException('Default roles cannot be deleted.', 'ROLE_SYSTEM', 422);
        }

        if ($role->users()->exists()) {
            throw new DomainException('Reassign the users of this role before deleting it.', 'ROLE_IN_USE', 422);
        }

        DB::transaction(function () use ($role) {
            $this->audit->log('role.deleted', $role, old: [
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->all(),
            ]);
            $role->delete();
        });
    }

    private function assertEditable(Role $role): void
    {
        if ($role->is_locked) {
            throw new DomainException('This role is locked and cannot be changed.', 'ROLE_LOCKED', 422);
        }
    }
}

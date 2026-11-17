<?php

namespace App\Services\Access;

use App\Exceptions\DomainException;
use App\Models\Role;
use App\Models\User;

/**
 * Prevents privilege escalation: a user may only grant (via role edits or
 * role assignment) permissions they hold themselves. The Owner role can
 * never be assigned to anyone else.
 */
class RoleAssignmentGuard
{
    /**
     * @param  iterable<string>  $permissions
     */
    public function assertCanGrantPermissions(User $actor, iterable $permissions): void
    {
        $missing = collect($permissions)
            ->reject(fn (string $permission) => $actor->hasPermissionTo($permission))
            ->values();

        if ($missing->isNotEmpty()) {
            throw new DomainException(
                'You cannot grant permissions you do not have yourself.',
                'PERMISSION_ESCALATION',
                403,
                ['permissions' => $missing->all()],
            );
        }
    }

    public function assertCanAssignRole(User $actor, Role $role): void
    {
        if ($role->key === TenantRoleProvisioner::OWNER) {
            throw new DomainException('The Owner role cannot be assigned.', 'OWNER_ROLE_LOCKED', 403);
        }

        $this->assertCanGrantPermissions($actor, $role->permissions->pluck('name'));
    }
}

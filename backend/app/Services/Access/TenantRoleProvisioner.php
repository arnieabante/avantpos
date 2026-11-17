<?php

namespace App\Services\Access;

use App\Models\Role;
use App\Models\Tenant;
use App\Tenancy\TenantContext;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates (and re-syncs) the default system roles of a tenant from the
 * permission catalogue.
 */
class TenantRoleProvisioner
{
    public const OWNER = 'owner';

    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly TenantContext $context,
    ) {}

    /**
     * Create missing system roles. Locked roles (Owner) are always re-synced
     * to their catalogue permissions; editable roles keep tenant changes
     * unless $resetEditable is true.
     *
     * @return array<string, Role> keyed by role key
     */
    public function provision(Tenant $tenant, bool $resetEditable = false): array
    {
        return $this->context->runAs($tenant, function (Tenant $tenant) use ($resetEditable) {
            $roles = [];

            foreach ($this->catalog->defaultRoles() as $key => $definition) {
                $role = Role::query()->firstOrNew(['tenant_id' => $tenant->getKey(), 'key' => $key]);
                $isNew = ! $role->exists;

                $role->fill([
                    'name' => $role->name ?? $definition['name'],
                    'guard_name' => $this->catalog->guard(),
                    'description' => $role->description ?? $definition['description'],
                    'is_system' => true,
                    'is_locked' => (bool) $definition['locked'],
                ]);
                $role->tenant_id = $tenant->getKey();
                $role->save();

                if ($isNew || $role->is_locked || $resetEditable) {
                    $role->syncPermissions($this->catalog->permissionsForRole($key));
                }

                $roles[$key] = $role;
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();

            return $roles;
        });
    }

    public function ownerRole(Tenant $tenant): Role
    {
        return Role::query()->where('tenant_id', $tenant->getKey())->where('key', self::OWNER)->firstOrFail();
    }
}

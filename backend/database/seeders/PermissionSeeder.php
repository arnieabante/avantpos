<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Tenant;
use App\Services\Access\PermissionCatalog;
use App\Services\Access\TenantRoleProvisioner;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Syncs the global permissions table with config/avantpos/permissions.php
 * and re-syncs locked system roles (Owner) of existing tenants so new
 * permissions reach them. Idempotent — safe to run on every deploy.
 */
class PermissionSeeder extends Seeder
{
    public function run(PermissionCatalog $catalog, TenantRoleProvisioner $provisioner): void
    {
        $guard = $catalog->guard();

        foreach ($catalog->all() as $name => $meta) {
            Permission::query()->updateOrCreate(
                ['name' => $name, 'guard_name' => $guard],
                ['group' => $meta['group'], 'label' => $meta['label']],
            );
        }

        $obsolete = Permission::query()->where('guard_name', $guard)->whereNotIn('name', $catalog->names())->pluck('name');
        if ($obsolete->isNotEmpty()) {
            $this->command?->warn('Permissions no longer in the catalogue (not deleted, migrate them manually): '.$obsolete->implode(', '));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Tenant::query()->each(fn (Tenant $tenant) => $provisioner->provision($tenant));

        $this->command?->info('Permissions synced: '.count($catalog->names()));
    }
}

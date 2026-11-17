<?php

namespace App\Models;

use App\Tenancy\TenantContext;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Tenant role (spatie teams mode: tenant_id is the team key).
 *
 * Not using BelongsToTenant: spatie scopes role lookups by team itself and
 * its internals query roles outside our request context (cache warmup).
 * Controllers MUST query roles via Role::forCurrentTenant().
 */
class Role extends SpatieRole
{
    protected $fillable = ['name', 'guard_name', 'tenant_id', 'key', 'description', 'is_system', 'is_locked'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    /**
     * Roles visible to the current tenant.
     */
    public static function forCurrentTenant()
    {
        return static::query()->where('tenant_id', app(TenantContext::class)->requireId());
    }
}

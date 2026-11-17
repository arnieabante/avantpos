<?php

namespace App\Tenancy;

use App\Models\Tenant;
use App\Tenancy\Exceptions\MissingTenantContextException;
use Closure;
use Spatie\Permission\PermissionRegistrar;

/**
 * Holds the tenant of the current request / job.
 *
 * Registered as a *scoped* singleton, so it is reset between requests and
 * queued jobs. The tenant is set ONLY by trusted code:
 *   - ResolveTenant middleware (from the authenticated actor), or
 *   - runAs() in jobs, seeders, commands and tests.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(Tenant $tenant): void
    {
        $this->tenant = $tenant;

        // spatie/laravel-permission teams: team = tenant.
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->getKey());
    }

    public function forget(): void
    {
        $this->tenant = null;
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }

    public function tenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    /**
     * @throws MissingTenantContextException
     */
    public function requireId(): int
    {
        return $this->id() ?? throw new MissingTenantContextException;
    }

    /**
     * @throws MissingTenantContextException
     */
    public function requireTenant(): Tenant
    {
        return $this->tenant ?? throw new MissingTenantContextException;
    }

    /**
     * Run a callback as the given tenant and restore the previous context.
     *
     * @template T
     *
     * @param  Closure(Tenant): T  $callback
     * @return T
     */
    public function runAs(Tenant|int $tenant, Closure $callback): mixed
    {
        $tenant = $tenant instanceof Tenant ? $tenant : Tenant::query()->findOrFail($tenant);
        $previous = $this->tenant;

        $this->set($tenant);

        try {
            return $callback($tenant);
        } finally {
            $previous ? $this->set($previous) : $this->forget();
        }
    }
}

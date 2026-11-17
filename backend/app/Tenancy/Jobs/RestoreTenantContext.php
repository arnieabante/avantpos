<?php

namespace App\Tenancy\Jobs;

use App\Tenancy\Exceptions\MissingTenantContextException;
use App\Tenancy\TenantContext;
use Closure;

/**
 * Job middleware: runs the job inside the tenant context captured at
 * dispatch time (see TenantAware).
 */
class RestoreTenantContext
{
    public function handle(object $job, Closure $next): mixed
    {
        $tenantId = $job->tenantId ?? null;

        if ($tenantId === null) {
            throw new MissingTenantContextException('Tenant-aware job ['.$job::class.'] was dispatched without a tenant.');
        }

        return app(TenantContext::class)->runAs($tenantId, fn () => $next($job));
    }
}

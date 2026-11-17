<?php

namespace App\Tenancy\Jobs;

use App\Tenancy\TenantContext;

/**
 * Use in queued jobs that touch tenant data:
 *
 *   class RebuildSummaries implements ShouldQueue {
 *       use Queueable, TenantAware;
 *       public function __construct() { $this->captureTenant(); }
 *       public function middleware(): array { return [new RestoreTenantContext]; }
 *   }
 *
 * The tenant id is captured when the job is dispatched and restored by the
 * RestoreTenantContext job middleware before handle() runs.
 */
trait TenantAware
{
    public ?int $tenantId = null;

    protected function captureTenant(): void
    {
        $this->tenantId = app(TenantContext::class)->requireId();
    }
}

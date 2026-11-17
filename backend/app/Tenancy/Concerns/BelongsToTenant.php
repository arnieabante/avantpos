<?php

namespace App\Tenancy\Concerns;

use App\Models\Tenant;
use App\Tenancy\Exceptions\MissingTenantContextException;
use App\Tenancy\Scopes\TenantScope;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Marks a model as tenant-owned.
 *
 *  - Adds TenantScope (all queries filtered by the current tenant).
 *  - Fills tenant_id from the context on create (any client-supplied
 *    tenant_id is overwritten — never trusted).
 *  - Forbids moving a record to another tenant.
 *
 * @method static Builder<static> withoutTenantScope()
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            $context = app(TenantContext::class);

            if ($context->check()) {
                $model->setAttribute('tenant_id', $context->id());

                return;
            }

            if ($model->getAttribute('tenant_id') === null) {
                throw new MissingTenantContextException(
                    'Cannot create ['.$model::class.'] without a tenant context.'
                );
            }
        });

        static::updating(function ($model): void {
            if ($model->isDirty('tenant_id')) {
                throw new LogicException('tenant_id of ['.$model::class.'] cannot be changed.');
            }
        });
    }

    public function initializeBelongsToTenant(): void
    {
        // tenant_id is never mass assignable.
        if (! in_array('tenant_id', $this->guarded, true) && $this->guarded !== ['*']) {
            $this->guarded[] = 'tenant_id';
        }
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

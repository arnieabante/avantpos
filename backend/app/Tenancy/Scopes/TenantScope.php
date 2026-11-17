<?php

namespace App\Tenancy\Scopes;

use App\Tenancy\Exceptions\MissingTenantContextException;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a tenant-owned model to the current tenant.
 *
 * With no tenant context it THROWS instead of silently returning unscoped
 * data. Code that legitimately works across tenants (platform admin,
 * scheduled jobs) must opt out explicitly with ->withoutTenantScope(),
 * which keeps every bypass greppable and reviewable.
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            throw new MissingTenantContextException(
                'Query on tenant-scoped model ['.$model::class.'] without tenant context. '
                .'Use TenantContext::runAs() or ->withoutTenantScope() deliberately.'
            );
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }

    public function extend(Builder $builder): void
    {
        $builder->macro('withoutTenantScope', fn (Builder $builder) => $builder->withoutGlobalScope(self::class));
    }
}

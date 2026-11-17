<?php

namespace App\Support\Auth;

use App\Tenancy\Scopes\TenantScope;
use Illuminate\Auth\EloquentUserProvider;

/**
 * Authentication happens before the tenant is resolved (the tenant is
 * derived FROM the authenticated user), so user lookups by id / e-mail /
 * remember token must bypass the tenant scope. E-mail is globally unique,
 * so this lookup is unambiguous.
 */
class TenantlessEloquentUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope(TenantScope::class);
    }
}

<?php

namespace App\Models;

use App\Tenancy\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Token lookup happens before the tenant context exists, so the tokenable
 * (User or Terminal) is loaded without the tenant scope. ResolveTenant then
 * derives the tenant from that tokenable.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable')->withoutGlobalScope(TenantScope::class);
    }
}

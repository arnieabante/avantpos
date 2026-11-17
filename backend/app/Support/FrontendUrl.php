<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Builds links to the React SPA, using the tenant's subdomain when it has one
 * (https://arnelmotoshop.avantpos.ph) and the main app URL otherwise.
 */
class FrontendUrl
{
    public function base(): string
    {
        return rtrim((string) config('avantpos.app.frontend_url'), '/');
    }

    public function forTenant(?Tenant $tenant): string
    {
        $base = $this->base();

        if (! $tenant?->subdomain) {
            return $base;
        }

        $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
        $port = parse_url($base, PHP_URL_PORT);

        return $scheme.'://'.$tenant->host().($port ? ':'.$port : '');
    }

    public function forTenantId(?int $tenantId): string
    {
        return $this->forTenant($tenantId ? Tenant::query()->find($tenantId) : null);
    }
}

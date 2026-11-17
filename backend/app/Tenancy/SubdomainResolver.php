<?php

namespace App\Tenancy;

use Illuminate\Http\Request;

/**
 * Extracts the tenant subdomain HINT of a request.
 *
 * Browsers set the Origin header on cross-origin API calls
 * (https://arnelmotoshop.avantpos.ph -> api.avantpos.ph); page JavaScript
 * cannot forge it. Native apps may send X-Tenant-Host instead.
 *
 * The hint is never used to SELECT a tenant — only to reject a request whose
 * authenticated user belongs to a different tenant than the subdomain shown.
 */
class SubdomainResolver
{
    public function fromRequest(Request $request): ?string
    {
        $origin = $request->headers->get('Origin') ?: $request->headers->get('X-Tenant-Host');

        if (! $origin) {
            return null;
        }

        $host = parse_url(str_contains($origin, '://') ? $origin : 'https://'.$origin, PHP_URL_HOST);

        return $host ? $this->fromHost(strtolower($host)) : null;
    }

    public function fromHost(string $host): ?string
    {
        $base = strtolower((string) config('avantpos.tenancy.base_domain'));
        $suffix = '.'.$base;

        if ($base === '' || ! str_ends_with($host, $suffix)) {
            return null;
        }

        $label = substr($host, 0, -strlen($suffix));

        // Only a single label directly under the base domain is a tenant subdomain.
        if ($label === '' || str_contains($label, '.')) {
            return null;
        }

        return $this->isReserved($label) ? null : $label;
    }

    public function isReserved(string $subdomain): bool
    {
        return in_array(strtolower($subdomain), config('avantpos.tenancy.reserved_subdomains', []), true);
    }

    public function isValidFormat(string $subdomain): bool
    {
        return (bool) preg_match(config('avantpos.tenancy.subdomain_pattern'), $subdomain);
    }
}

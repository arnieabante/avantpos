<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Exceptions\DomainException;
use App\Exceptions\TenantInaccessibleException;
use App\Models\Tenant;
use App\Models\Terminal;
use App\Models\User;
use App\Support\FrontendUrl;
use App\Tenancy\SubdomainResolver;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the tenant context for the request.
 *
 * The tenant is derived ONLY from the authenticated actor (user or
 * registered terminal) — never from a header, subdomain or request body.
 * Must run after auth:sanctum.
 */
class ResolveTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SubdomainResolver $subdomains,
        private readonly FrontendUrl $frontend,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();

        if ($actor === null) {
            throw new AuthenticationException;
        }

        if (! $actor instanceof User && ! $actor instanceof Terminal) {
            throw new DomainException('This endpoint is only available to business accounts.', 'FORBIDDEN', 403);
        }

        $tenant = Tenant::query()->find($actor->tenant_id);

        if (! $tenant || $tenant->status === TenantStatus::Cancelled) {
            throw TenantInaccessibleException::cancelled();
        }

        if ($tenant->status === TenantStatus::Suspended) {
            throw TenantInaccessibleException::suspended();
        }

        $hint = $this->subdomains->fromRequest($request);
        if ($hint !== null && $hint !== $tenant->subdomain) {
            throw TenantInaccessibleException::mismatch($this->frontend->forTenant($tenant));
        }

        if ($actor instanceof User && ! $actor->isActive()) {
            throw new DomainException('Your user account is deactivated.', 'USER_INACTIVE', 403);
        }

        if ($actor instanceof Terminal && ! $actor->isActive()) {
            throw new DomainException('This terminal is not active.', 'TERMINAL_INACTIVE', 403);
        }

        $this->context->set($tenant);

        return $next($request);
    }
}

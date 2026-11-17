<?php

namespace App\Services\Auth;

use App\Enums\TenantStatus;
use App\Exceptions\DomainException;
use App\Exceptions\TenantInaccessibleException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\FrontendUrl;
use App\Tenancy\SubdomainResolver;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Verifies tenant-user credentials and business rules shared by the SPA
 * (session) login and the native-app (token) login.
 */
class LoginService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SubdomainResolver $subdomains,
        private readonly FrontendUrl $frontend,
        private readonly AuditLogger $audit,
    ) {}

    public function verify(Request $request, string $email, string $password): User
    {
        $user = User::withoutTenantScope()->where('email', $email)->first();

        // Always run a hash check so response time does not reveal whether the e-mail exists.
        static $dummyHash = null;
        $dummyHash ??= Hash::make('avantpos-dummy-password');
        $passwordOk = Hash::check($password, $user?->password ?? $dummyHash);

        if (! $user || ! $passwordOk) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        // Credentials are valid from here on: detailed reasons are safe to return.
        $tenant = Tenant::query()->find($user->tenant_id);

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

        if (! $user->isActive()) {
            throw new DomainException('Your user account is deactivated. Contact your business administrator.', 'USER_INACTIVE', 403);
        }

        $this->context->set($tenant);

        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }

    public function recordLogin(User $user, string $method): void
    {
        $this->audit->log('auth.login', $user, meta: ['method' => $method], actor: $user);
    }
}

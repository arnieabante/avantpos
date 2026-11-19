<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user or platform admin with a password chosen by someone else must set
 * their own before doing anything else. Only "who am I", changing the
 * password and signing out stay available. POS devices are not affected.
 */
class EnsurePasswordChanged
{
    private const ALLOWED = [
        'api.v1.auth.me', 'api.v1.auth.password.change', 'api.v1.auth.logout', 'api.v1.auth.verification.send',
        'api.admin.v1.auth.me', 'api.admin.v1.auth.password', 'api.admin.v1.auth.logout',
    ];

    public function handle(Request $request, Closure $next, ?string $guard = null): Response
    {
        $actor = $request->user($guard);
        if ($actor && ($actor->must_change_password ?? false) && ! $request->routeIs(...self::ALLOWED)) {
            throw new DomainException('Please set your own password before continuing.', 'PASSWORD_CHANGE_REQUIRED', 403);
        }

        return $next($request);
    }
}

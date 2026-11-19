<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A platform admin deactivated by a super admin is signed out on their next
 * request (admin sessions do not otherwise re-check the account).
 */
class EnsureActivePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('platform');
        if ($admin && ! $admin->is_active) {
            Auth::guard('platform')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
            }
            throw new AuthenticationException;
        }

        return $next($request);
    }
}

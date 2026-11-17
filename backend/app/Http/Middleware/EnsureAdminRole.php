<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform admin roles: super_admin may do everything; billing manages plans
 * and payments; support handles tickets. Usage: admin.role:billing,support
 */
class EnsureAdminRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->user('platform')?->role;
        if ($role !== 'super_admin' && ! in_array($role, $roles, true)) {
            throw new DomainException('Your admin role does not allow this.', 'FORBIDDEN', 403);
        }

        return $next($request);
    }
}

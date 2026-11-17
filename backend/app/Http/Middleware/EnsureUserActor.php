<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only a human tenant user may call this route (device tokens are rejected).
 * With `actor.user:verified` the user's e-mail must also be verified.
 */
class EnsureUserActor
{
    public function handle(Request $request, Closure $next, ?string $requirement = null): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new DomainException('This action requires a user login.', 'USER_REQUIRED', 403);
        }

        if ($requirement === 'verified' && ! $user->hasVerifiedEmail()) {
            throw new DomainException('Please verify your e-mail address first.', 'EMAIL_NOT_VERIFIED', 403);
        }

        return $next($request);
    }
}

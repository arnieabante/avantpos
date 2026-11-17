<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use App\Models\Terminal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only a registered POS terminal (device token) holding the given ability
 * may call this route. Usage: `actor.terminal` or `actor.terminal:terminal:sync`.
 */
class EnsureTerminalActor
{
    public function handle(Request $request, Closure $next, string $ability = Terminal::ABILITY_SYNC): Response
    {
        $terminal = $request->user();

        if (! $terminal instanceof Terminal || ! $terminal->tokenCan($ability)) {
            throw new DomainException('This endpoint requires a registered POS terminal.', 'TERMINAL_REQUIRED', 403);
        }

        $terminal->forceFill(['last_seen_at' => now()])->saveQuietly();

        return $next($request);
    }
}

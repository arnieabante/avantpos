<?php

namespace App\Http\Middleware;

use App\Exceptions\DomainException;
use App\Models\Subscription;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An expired (or ended) subscription makes the business read-only: users can
 * still sign in, view and export their records, and pay — but not record
 * new sales or changes. Runs after tenant resolution.
 */
class EnsureSubscriptionWritable
{
    /** Routes that stay usable without an active subscription. */
    private const ALWAYS_ALLOWED = ['api.v1.billing.*', 'api.v1.auth.*', 'api.v1.support.*'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() || $request->routeIs(...self::ALWAYS_ALLOWED)) {
            return $next($request);
        }

        $status = Subscription::query()->latest('id')->value('status');
        $status = $status instanceof \BackedEnum ? $status->value : $status;
        if (in_array($status, ['expired', 'cancelled'], true)) {
            throw new DomainException(
                'Your AvantPOS subscription has expired, so your account is read-only. Renew it under Settings → Subscription to continue.',
                'SUBSCRIPTION_EXPIRED',
                402,
            );
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Services\Billing\PlanGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: `feature:purchasing,Purchasing` — 402 FEATURE_NOT_AVAILABLE when the
 * tenant's plan does not include the feature. Must run after `tenant`.
 */
class RequirePlanFeature
{
    public function __construct(private readonly PlanGate $plan) {}

    public function handle(Request $request, Closure $next, string $feature, ?string $label = null): Response
    {
        $this->plan->assertFeature($feature, $label ?? ucfirst(str_replace('_', ' ', $feature)));

        return $next($request);
    }
}

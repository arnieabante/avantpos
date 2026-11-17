<?php

namespace App\Services\Billing;

use App\Exceptions\DomainException;
use App\Exceptions\PlanLimitReachedException;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Terminal;
use App\Models\User;
use App\Models\UserInvitation;
use App\Tenancy\TenantContext;

/**
 * Enforces plan limits (branches/users/terminals/products) and feature
 * availability for the current tenant. Always checked server-side.
 */
class PlanGate
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Not memoised on purpose: services (and the controllers holding them) can
     * outlive a request, and a stale plan would apply the wrong limits.
     */
    public function plan(): ?SubscriptionPlan
    {
        return Subscription::query()->with('plan.features')->latest('id')->first()?->plan;
    }

    public function hasFeature(string $feature): bool
    {
        return (bool) $this->plan()?->hasFeature($feature);
    }

    public function assertFeature(string $feature, string $label): void
    {
        if (! $this->hasFeature($feature)) {
            throw new DomainException(
                "{$label} is not included in your current plan. Upgrade your subscription to use it.",
                'FEATURE_NOT_AVAILABLE',
                402,
                ['feature' => $feature],
            );
        }
    }

    /**
     * @param  'branches'|'users'|'terminals'|'products'  $resource
     */
    public function assertCanAdd(string $resource, int $adding = 1): void
    {
        $limits = $this->plan()?->limits() ?? [];

        // null = unlimited. (Do not use `?? 0` here: it would turn "unlimited" into 0.)
        if (array_key_exists($resource, $limits) && $limits[$resource] === null) {
            return;
        }

        $limit = (int) ($limits[$resource] ?? 0);

        if ($this->usage($resource) + $adding > $limit) {
            throw PlanLimitReachedException::for($resource, $limit);
        }
    }

    /**
     * @param  'branches'|'users'|'terminals'|'products'  $resource
     */
    public function usage(string $resource): int
    {
        $this->context->requireId();

        return match ($resource) {
            'branches' => Branch::query()->count(),
            // Pending invitations reserve a seat.
            'users' => User::query()->where('status', 'active')->count()
                + UserInvitation::query()->pending()->count(),
            'terminals' => Terminal::query()->where('status', '!=', 'retired')->count(),
            // Products (not variants), excluding deleted ones.
            'products' => Product::query()->count(),
        };
    }
}

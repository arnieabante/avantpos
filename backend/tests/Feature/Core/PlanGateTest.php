<?php

use App\Exceptions\PlanLimitReachedException;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\Billing\PlanGate;

function switchPlan(int $tenantId, string $code): void
{
    Subscription::withoutTenantScope()->where('tenant_id', $tenantId)
        ->update(['subscription_plan_id' => SubscriptionPlan::query()->where('code', $code)->value('id')]);
}

it('treats a null limit as unlimited', function () {
    ['tenant' => $tenant] = $this->createTenant();
    switchPlan($tenant->id, 'enterprise');

    $this->asTenant($tenant, function () {
        $gate = app(PlanGate::class);

        // Must not throw for any resource on an unlimited plan.
        foreach (['branches', 'users', 'terminals', 'products'] as $resource) {
            $gate->assertCanAdd($resource, 1000);
        }
    });

    expect(true)->toBeTrue();
});

it('enforces a numeric limit', function () {
    ['tenant' => $tenant] = $this->createTenant();
    switchPlan($tenant->id, 'basic');

    $this->asTenant($tenant, fn () => app(PlanGate::class)->assertCanAdd('products', 1001));
})->throws(PlanLimitReachedException::class);

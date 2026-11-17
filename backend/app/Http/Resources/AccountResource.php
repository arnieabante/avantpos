<?php

namespace App\Http\Resources;

use App\Models\Branch;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Media\MediaStorage;
use Illuminate\Http\Request;

/**
 * Session payload for the SPA (GET /auth/me, login, register):
 * who am I, which business, what may I do, which branches, which plan.
 *
 * @property User $resource
 */
class AccountResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $tenant = $user->tenant()->firstOrFail();

        $branches = $user->canAccessAllBranches()
            ? Branch::query()->where('is_active', true)->orderByDesc('is_main')->orderBy('name')->get()
            : $user->branches()->where('is_active', true)->orderByDesc('is_main')->orderBy('name')->get();

        $subscription = Subscription::query()->with('plan.features')->latest('id')->first();
        $plan = $subscription?->plan;

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'email_verified' => $user->hasVerifiedEmail(),
                'has_pin' => $user->hasPin(),
                'is_owner' => $tenant->owner_user_id === $user->id,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
            ],
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'subdomain' => $tenant->subdomain,
                'status' => $tenant->status->value,
                'business_type' => $tenant->business_type,
                'vat_registered' => $tenant->vat_registered,
                'timezone' => $tenant->timezone,
                'currency' => $tenant->currency,
                'logo_url' => app(MediaStorage::class)->url($tenant->logo_path),
            ],
            'roles' => $user->getRoleNames()->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->sort()->values(),
            'branches' => $branches->map(fn (Branch $b) => [
                'id' => $b->id,
                'code' => $b->code,
                'name' => $b->name,
                'is_main' => $b->is_main,
            ])->values(),
            'subscription' => $subscription ? [
                'status' => $subscription->status->value,
                'billing_cycle' => $subscription->billing_cycle,
                'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
                'current_period_end' => $subscription->current_period_end?->toIso8601String(),
                'plan' => [
                    'code' => $plan?->code,
                    'name' => $plan?->name,
                    'limits' => $plan?->limits(),
                    'features' => $plan?->featureMap(),
                ],
            ] : null,
        ];
    }
}

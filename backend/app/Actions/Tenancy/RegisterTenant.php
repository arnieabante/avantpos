<?php

namespace App\Actions\Tenancy;

use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\Subscription;
use App\Models\SubscriptionHistory;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Access\TenantRoleProvisioner;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\TenantDefaultsProvisioner;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Registers a new business: tenant + owner user + main branch + default
 * roles + default settings + trial subscription, atomically.
 */
class RegisterTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantRoleProvisioner $roles,
        private readonly TenantDefaultsProvisioner $defaults,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *     business_name: string, business_type?: ?string, subdomain?: ?string, vat_registered?: bool,
     *     owner_name: string, email: string, phone?: ?string, password: string,
     * }  $data  Already validated.
     * @return array{tenant: Tenant, user: User}
     */
    public function handle(array $data): array
    {
        $plan = $this->trialPlan();

        $result = DB::transaction(function () use ($data, $plan) {
            $trialEndsAt = $plan->trial_days > 0 ? now()->addDays($plan->trial_days) : null;

            $tenant = Tenant::query()->create([
                'name' => $data['business_name'],
                'slug' => $this->uniqueSlug($data['business_name']),
                'subdomain' => $data['subdomain'] ?? null,
                'status' => TenantStatus::Trial,
                'business_type' => $data['business_type'] ?? null,
                'vat_registered' => (bool) ($data['vat_registered'] ?? false),
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'timezone' => config('avantpos.app.defaults.timezone'),
                'currency' => config('avantpos.app.defaults.currency'),
                'locale' => config('avantpos.app.defaults.locale'),
                'trial_ends_at' => $trialEndsAt,
            ]);

            return $this->context->runAs($tenant, function (Tenant $tenant) use ($data, $plan, $trialEndsAt) {
                $branch = Branch::query()->create([
                    'code' => 'MAIN',
                    'name' => 'Main Branch',
                    'is_main' => true,
                    'is_active' => true,
                    'phone' => $data['phone'] ?? null,
                ]);

                $user = User::query()->create([
                    'name' => $data['owner_name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'phone' => $data['phone'] ?? null,
                    'status' => UserStatus::Active,
                ]);

                $tenant->forceFill(['owner_user_id' => $user->getKey()])->save();

                $roles = $this->roles->provision($tenant);
                $user->assignRole($roles[TenantRoleProvisioner::OWNER]);
                $user->syncBranches([$branch->getKey()]);

                $this->defaults->provision($tenant);

                $subscription = Subscription::query()->create([
                    'subscription_plan_id' => $plan->getKey(),
                    'billing_cycle' => 'monthly',
                    'status' => $trialEndsAt ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
                    'trial_ends_at' => $trialEndsAt,
                    'current_period_start' => now(),
                    'current_period_end' => $trialEndsAt,
                ]);

                SubscriptionHistory::query()->create([
                    'subscription_id' => $subscription->getKey(),
                    'to_plan_id' => $plan->getKey(),
                    'change_type' => 'created',
                    'prorated_amount' => '0.00',
                    'actor_type' => 'user',
                    'actor_id' => $user->getKey(),
                    'effective_at' => now(),
                ]);

                $this->audit->log('tenant.registered', $tenant, new: [
                    'name' => $tenant->name,
                    'subdomain' => $tenant->subdomain,
                    'plan' => $plan->code,
                ], actor: $user, tenantId: $tenant->getKey());

                return ['tenant' => $tenant->refresh(), 'user' => $user->refresh()];
            });
        });

        // After commit: send the verification e-mail.
        event(new Registered($result['user']));

        return $result;
    }

    private function trialPlan(): SubscriptionPlan
    {
        $code = config('avantpos.app.trial.plan_code');

        return SubscriptionPlan::query()->where('code', $code)->where('is_active', true)->first()
            ?? throw new RuntimeException("The trial plan '{$code}' is missing or inactive. Run: php artisan db:seed --class=SubscriptionPlanSeeder");
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::limit(Str::slug($name), 50, '') ?: 'business';
        $slug = $base;

        while (Tenant::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(5));
        }

        return $slug;
    }
}

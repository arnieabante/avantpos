<?php

namespace Database\Seeders;

use App\Actions\Tenancy\RegisterTenant;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantStatus;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CashRegister;
use App\Models\CustomerGroup;
use App\Models\DiscountPreset;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\Terminal;
use App\Models\Unit;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Creates the launch businesses listed in database/seeders/data/businesses.php
 * (users, plan, branch, POS counter, categories, brands, units, suppliers,
 * customer groups, discounts; no products). Runs in every environment as part
 * of `php artisan db:seed` and skips businesses that already exist.
 */
class BusinessSeeder extends Seeder
{
    /** Local PIN for managers/cashiers so supervisor approvals can be tested; none in production. */
    private const LOCAL_PIN = '2468';

    public function run(RegisterTenant $register, TenantContext $context): void
    {
        $businesses = require database_path('seeders/data/businesses.php');
        $production = app()->isProduction();
        $shared = (string) env('BUSINESS_SEED_PASSWORD', '');
        if ($shared === '' && ! $production) {
            $shared = (string) env('DEMO_PASSWORD', 'Password123');
        }
        $printed = [];

        // Verification e-mails are not sent for seeded accounts (their addresses are marked verified).
        Event::fake([Registered::class]);

        foreach ($businesses as $b) {
            if (Tenant::withTrashed()->where('subdomain', $b['subdomain'])->exists()) {
                $this->command?->info("{$b['name']}: already exists, skipped.");

                continue;
            }
            $this->assertUsable($b, $production);
            $plan = SubscriptionPlan::query()->where('code', $b['plan'])->where('is_active', true)->first()
                ?? throw new RuntimeException("Plan '{$b['plan']}' not found or not offered (run the SubscriptionPlanSeeder first).");
            $passwords = collect($b['users'])->mapWithKeys(fn ($u) => [$u['email'] => $shared !== '' ? $shared : Str::password(14, symbols: false)])->all();
            $ownerData = collect($b['users'])->firstWhere('role', 'owner');

            DB::transaction(function () use ($b, $plan, $passwords, $ownerData, $register, $context, $production) {
                ['tenant' => $tenant, 'user' => $owner] = $register->handle([
                    'business_name' => $b['name'], 'business_type' => $b['business_type'], 'subdomain' => $b['subdomain'],
                    'vat_registered' => $b['vat_registered'], 'owner_name' => $ownerData['name'], 'email' => $ownerData['email'],
                    'phone' => $b['phone'], 'password' => $passwords[$ownerData['email']],
                ]);
                $owner->forceFill(['email_verified_at' => now()])->save();

                // Trial of the chosen plan.
                $trialEnds = now()->addDays(max(1, (int) $plan->trial_days));
                Subscription::withoutTenantScope()->where('tenant_id', $tenant->id)->update([
                    'subscription_plan_id' => $plan->id, 'status' => SubscriptionStatus::Trialing->value,
                    'trial_ends_at' => $trialEnds, 'current_period_start' => now(), 'current_period_end' => $trialEnds,
                ]);
                $tenant->forceFill(['status' => TenantStatus::Trial, 'trial_ends_at' => $trialEnds])->save();

                $context->runAs($tenant, function () use ($b, $owner, $passwords, $production) {
                    $main = Branch::query()->where('is_main', true)->firstOrFail();
                    $main->forceFill(['name' => $b['branch']])->save();
                    $terminal = Terminal::query()->create(['branch_id' => $main->id, 'code' => 'POS-1', 'name' => $b['terminal'], 'status' => 'active']);
                    CashRegister::query()->create(['branch_id' => $main->id, 'terminal_id' => $terminal->id, 'name' => 'Drawer 1', 'is_active' => true]);

                    if (! $production) {
                        $owner->setPin(self::LOCAL_PIN);
                    }
                    foreach ($b['users'] as $u) {
                        if ($u['role'] === 'owner') {
                            continue;
                        }
                        $user = User::query()->create(['name' => $u['name'], 'email' => Str::lower($u['email']), 'password' => $passwords[$u['email']], 'status' => UserStatus::Active]);
                        $user->forceFill(['email_verified_at' => now()])->save();
                        $user->assignRole(Role::forCurrentTenant()->where('key', $u['role'])->firstOrFail());
                        $user->syncBranches([$main->id]);
                        if (! $production) {
                            $user->setPin(self::LOCAL_PIN);
                        }
                    }

                    foreach (array_values($b['categories']) as $i => $name) {
                        ProductCategory::query()->create(['name' => $name, 'is_active' => true, 'sort_order' => $i + 1]);
                    }
                    foreach ($b['brands'] as $name) {
                        Brand::query()->create(['name' => $name]);
                    }
                    foreach ($b['units'] as $unit) {
                        Unit::query()->firstOrCreate(['abbreviation' => $unit['abbreviation']], $unit);
                    }
                    foreach ($b['suppliers'] as $supplier) {
                        Supplier::query()->create($supplier + ['is_active' => true]);
                    }
                    foreach ($b['customer_groups'] as $group) {
                        CustomerGroup::query()->create($group);
                    }
                    foreach ($b['discounts'] as $discount) {
                        DiscountPreset::query()->create($discount + ['is_active' => true, 'requires_permission' => false]);
                    }
                });
            });

            $this->command?->info("{$b['name']}: created ({$plan->name} trial, {$b['subdomain']}, ".count($b['users']).' users).');
            if ($shared === '') {
                foreach ($passwords as $email => $password) {
                    $printed[] = [$b['name'], $email, $password];
                }
            }
        }

        if ($printed !== []) {
            $this->command?->warn('Passwords (shown once, give them to each person and ask them to change it):');
            $this->command?->table(['Business', 'E-mail', 'Password'], $printed);
        } elseif (! $production) {
            $this->command?->warn("Local password for seeded users: {$shared}   POS PIN: ".self::LOCAL_PIN);
        }
    }

    /** @param  array<string, mixed>  $b */
    private function assertUsable(array $b, bool $production): void
    {
        $emails = array_map(fn ($u) => Str::lower($u['email']), $b['users']);
        if (collect($b['users'])->where('role', 'owner')->count() !== 1) {
            throw new RuntimeException("{$b['name']}: exactly one user must have the owner role.");
        }
        if ($production && collect($emails)->contains(fn ($e) => Str::endsWith($e, ['.test', '.example', '.local', '.invalid']))) {
            throw new RuntimeException("{$b['name']}: replace the placeholder e-mail addresses in database/seeders/data/businesses.php with real ones before seeding production.");
        }
        $taken = User::withoutTenantScope()->withTrashed()->whereIn('email', $emails)->pluck('email')->all();
        if ($taken !== []) {
            throw new RuntimeException("{$b['name']}: these e-mail addresses already have an AvantPOS account: ".implode(', ', $taken));
        }
    }
}

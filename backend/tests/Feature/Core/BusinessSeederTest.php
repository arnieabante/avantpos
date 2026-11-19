<?php

use App\Models\Branch;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Subscription;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\BusinessSeeder;
use Illuminate\Support\Facades\Hash;

/* The launch businesses seeded by `php artisan db:seed` (database/seeders/data/businesses.php). */

it('creates the launch businesses with their plan, users and setup, and no products', function () {
    $this->seed(BusinessSeeder::class);

    $trd = Tenant::query()->where('subdomain', 'trd-motorshop')->firstOrFail();
    $dayans = Tenant::query()->where('subdomain', 'dayans-apparel')->firstOrFail();
    expect($trd->name)->toBe('TRD Motorshop')->and($dayans->name)->toBe("Dayan's Ukay-Ukay & Apparel");

    $plan = fn (Tenant $t) => Subscription::withoutTenantScope()->with('plan')->where('tenant_id', $t->id)->latest('id')->first();
    expect($plan($trd)->plan->code)->toBe('basic')->and($plan($trd)->status->value)->toBe('trialing')
        ->and($plan($dayans)->plan->code)->toBe('professional')->and($plan($dayans)->status->value)->toBe('trialing');

    foreach ([$trd, $dayans] as $tenant) {
        $this->asTenant($tenant, function () {
            $users = User::query()->with('roles')->get();
            expect($users)->toHaveCount(3)
                ->and($users->map(fn ($u) => $u->roles->first()->key)->sort()->values()->all())->toBe(['branch_manager', 'cashier', 'owner'])
                ->and($users->every(fn ($u) => $u->email_verified_at !== null && Hash::check('Password123', $u->password)))->toBeTrue()
                ->and(Product::query()->count())->toBe(0)
                ->and(ProductCategory::query()->count())->toBeGreaterThan(10);
        });
    }
    $this->asTenant($trd, fn () => expect(Supplier::query()->pluck('name')->all())->toBe(['Golden Rule Motor Supplier']));
    $this->asTenant($dayans, fn () => expect(Unit::query()->where('abbreviation', 'bale')->exists())->toBeTrue());

    // Running db:seed again changes nothing.
    $this->seed(BusinessSeeder::class);
    expect(Tenant::query()->whereIn('subdomain', ['trd-motorshop', 'dayans-apparel'])->count())->toBe(2)
        ->and(User::withoutTenantScope()->whereIn('tenant_id', [$trd->id, $dayans->id])->count())->toBe(6);

    // The owner can sign in and use the business right away.
    $owner = User::withoutTenantScope()->where('email', 'owner@trd-motorshop.avantpos.co')->firstOrFail();
    $branch = $this->asTenant($trd, fn () => Branch::query()->value('id'));
    $this->actingAsApi($owner)->withHeader('X-Branch-Id', (string) $branch)->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('data.tenant.name', 'TRD Motorshop');
});

it('refuses placeholder e-mail addresses in production', function () {
    $check = new ReflectionMethod(BusinessSeeder::class, 'assertUsable');
    $business = ['name' => 'Shop', 'users' => [['role' => 'owner', 'email' => 'owner@shop.test']]];

    expect(fn () => $check->invoke(new BusinessSeeder, $business, true))->toThrow(RuntimeException::class, 'replace the placeholder e-mail addresses');
    $check->invoke(new BusinessSeeder, $business, false); // fine locally
    $check->invoke(new BusinessSeeder, [...$business, 'users' => [['role' => 'owner', 'email' => 'owner@shop.avantpos.co']]], true);

    // The launch data itself uses real addresses.
    $emails = collect(require database_path('seeders/data/businesses.php'))->flatMap(fn ($b) => array_column($b['users'], 'email'));
    expect($emails)->toHaveCount(6)->each->not->toEndWith('.test');
});

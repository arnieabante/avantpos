<?php

use App\Models\PlanFeature;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Pest configuration
|--------------------------------------------------------------------------
|
| Every test uses Tests\TestCase: MySQL test database, migrated once,
| one rolled-back transaction per test.
|
*/

pest()->extend(TestCase::class)->in('Feature', 'Unit');

require_once __DIR__.'/Fixtures.php';

/*
|--------------------------------------------------------------------------
| Shared fixtures
|--------------------------------------------------------------------------
*/

/** Minimal PSGC fixture: region > province > city > barangay, plus an NCR-style city without province. */
function seedPsgcFixture(): void
{
    DB::table('ph_regions')->insert(['code' => 'T-R1', 'name' => 'Test Region', 'region_name' => 'Region T']);
    DB::table('ph_provinces')->insert(['code' => 'T-P1', 'name' => 'Test Province', 'region_code' => 'T-R1']);
    DB::table('ph_cities')->insert([
        ['code' => 'T-C1', 'name' => 'Test City', 'type' => 'city', 'region_code' => 'T-R1', 'province_code' => 'T-P1'],
        ['code' => 'T-C2', 'name' => 'Independent City', 'type' => 'city', 'region_code' => 'T-R1', 'province_code' => null],
    ]);
    DB::table('ph_barangays')->insert([
        ['code' => 'T-B1', 'name' => 'Poblacion', 'city_code' => 'T-C1'],
        ['code' => 'T-B2', 'name' => 'San Roque', 'city_code' => 'T-C2'],
    ]);
}

/**
 * A small plan for limit/feature tests (there is no Free plan any more):
 * 1 branch, 2 users, 1 terminal, 100 products, core features only.
 * Created inside the test's transaction, so it is rolled back afterwards.
 */
function limitedPlan(): SubscriptionPlan
{
    $plan = SubscriptionPlan::query()->firstOrCreate(['code' => 'test-limited'], [
        'name' => 'Limited (test)', 'monthly_price' => '99.00', 'annual_price' => '990.00', 'trial_days' => 0, 'grace_days' => 0,
        'max_branches' => 1, 'max_users' => 2, 'max_terminals' => 1, 'max_products' => 100,
        'is_public' => false, 'is_active' => true, 'sort_order' => 99,
    ]);
    $core = ['pos', 'inventory', 'customers', 'receipts_thermal', 'offline_pos'];
    foreach (config('avantpos.plans.feature_keys') as $key) {
        PlanFeature::query()->firstOrCreate(['subscription_plan_id' => $plan->id, 'feature_key' => $key], ['enabled' => in_array($key, $core, true)]);
    }

    return $plan;
}

/** Switch a tenant's subscription to another plan (by code; 'test-limited' = limitedPlan()). */
function usePlan(int $tenantId, string $code): void
{
    if ($code === 'test-limited') {
        limitedPlan();
    }
    Subscription::withoutTenantScope()->where('tenant_id', $tenantId)
        ->update(['subscription_plan_id' => SubscriptionPlan::query()->where('code', $code)->value('id')]);
}

/**
 * Create a stock-tracked product through the API as the acting user and
 * return the id of its (default) variant.
 *
 * @param  array<string, mixed>  $overrides  merged recursively into the product document
 */
function createStockItem(int $baseUnitId, array $overrides = []): int
{
    $payload = array_replace_recursive([
        'name' => 'Item '.Str::random(6),
        'base_unit_id' => $baseUnitId,
        'type' => 'standard',
        'tax_class' => 'vatable',
        'track_inventory' => true,
        'variants' => [['selling_price' => '20', 'cost_price' => '9.5000', 'reorder_level' => '5']],
    ], $overrides);

    return (int) test()->postJson('/api/v1/products', $payload)->assertCreated()->json('data.variants.0.id');
}

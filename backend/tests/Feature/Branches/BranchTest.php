<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Subscription;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function useLimitedPlan(Tenant $tenant): void
{
    Subscription::withoutTenantScope()->where('tenant_id', $tenant->id)
        ->update(['subscription_plan_id' => limitedPlan()->id]);
}

beforeEach(function () {
    seedPsgcFixture();
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
});

it('creates a branch with an uppercase code, address and operating hours', function () {
    $response = $this->actingAsApi($this->owner)->postJson('/api/v1/branches', [
        'code' => 'qc-01',
        'name' => 'Quezon City',
        'phone' => '09171234567',
        'region_code' => 'T-R1', 'province_code' => 'T-P1', 'city_code' => 'T-C1', 'barangay_code' => 'T-B1',
        'bir_branch_code' => '00001',
        'operating_hours' => [
            'mon' => ['closed' => false, 'open' => '08:00', 'close' => '20:00'],
            'sun' => ['closed' => true],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.code', 'QC-01')
        ->assertJsonPath('data.is_main', false)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.address.city_name', 'Test City')
        ->assertJsonPath('data.operating_hours.mon.open', '08:00');

    expect(AuditLog::query()->where('event', 'branch.created')->where('auditable_id', $response->json('data.id'))->exists())->toBeTrue();
});

it('validates code uniqueness, opening hours and BIR branch code', function () {
    $this->actingAsApi($this->owner)->postJson('/api/v1/branches', [
        'code' => 'main',
        'name' => 'Duplicate',
        'bir_branch_code' => '1',
        'operating_hours' => ['mon' => ['closed' => false, 'open' => '08:00'], 'xyz' => ['closed' => true]],
    ])->assertUnprocessable()->assertJsonValidationErrors(['code', 'bir_branch_code', 'operating_hours', 'operating_hours.mon.close']);
});

it('allows the same branch code in another business', function () {
    ['owner' => $otherOwner] = $this->createTenant();

    $this->actingAsApi($otherOwner)->postJson('/api/v1/branches', ['code' => 'QC', 'name' => 'QC'])->assertCreated();
    $this->actingAsApi($this->owner)->postJson('/api/v1/branches', ['code' => 'QC', 'name' => 'QC'])->assertCreated();
});

it('enforces the plan branch limit', function () {
    useLimitedPlan($this->tenant);

    $this->actingAsApi($this->owner)->postJson('/api/v1/branches', ['code' => 'B2', 'name' => 'Second'])
        ->assertStatus(402)->assertJsonPath('code', 'PLAN_LIMIT_REACHED')->assertJsonPath('context.limit', 1);
});

it('updates a branch and audits the old and new values', function () {
    $this->actingAsApi($this->owner)->putJson("/api/v1/branches/{$this->main->id}", ['code' => 'MAIN', 'name' => 'Head Office'])
        ->assertOk()->assertJsonPath('data.name', 'Head Office');

    $log = AuditLog::query()->where('event', 'branch.updated')->where('auditable_id', $this->main->id)->latest('id')->first();
    expect($log->old_values)->toBe(['name' => 'Main Branch'])->and($log->new_values)->toBe(['name' => 'Head Office']);
});

it('protects the main branch and switches the main branch', function () {
    $second = $this->actingAsApi($this->owner)->postJson('/api/v1/branches', ['code' => 'B2', 'name' => 'Second'])->json('data.id');

    $this->postJson("/api/v1/branches/{$this->main->id}/deactivate")->assertUnprocessable()->assertJsonPath('code', 'BRANCH_IS_MAIN');

    $this->postJson("/api/v1/branches/{$second}/make-main")->assertOk()->assertJsonPath('data.is_main', true);
    expect($this->main->fresh()->is_main)->toBeFalse();

    $this->postJson("/api/v1/branches/{$this->main->id}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
    $this->postJson("/api/v1/branches/{$this->main->id}/make-main")->assertUnprocessable()->assertJsonPath('code', 'BRANCH_INACTIVE');
    $this->postJson("/api/v1/branches/{$this->main->id}/activate")->assertOk()->assertJsonPath('data.is_active', true);

    $mainCount = $this->asTenant($this->tenant, fn () => Branch::query()->where('is_main', true)->count());
    expect($mainCount)->toBe(1);
});

it('refuses to deactivate a branch with an open register session', function () {
    $second = $this->actingAsApi($this->owner)->postJson('/api/v1/branches', ['code' => 'B2', 'name' => 'Second'])->json('data.id');
    $terminal = $this->createTerminal($this->tenant, $this->asTenant($this->tenant, fn () => Branch::query()->find($second)));

    DB::table('cash_register_sessions')->insert([
        'tenant_id' => $this->tenant->id, 'client_uuid' => (string) Str::uuid(), 'branch_id' => $second,
        'terminal_id' => $terminal->id, 'user_id' => $this->owner->id, 'status' => 'open',
        'opened_at' => now(), 'opening_float' => '1000.00', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->postJson("/api/v1/branches/{$second}/deactivate")->assertUnprocessable()->assertJsonPath('code', 'BRANCH_HAS_OPEN_SESSION');
});

it('lets inventory managers list branches but not change them', function () {
    $user = $this->createUser($this->tenant, 'inventory_manager');

    $this->actingAsApi($user)->getJson('/api/v1/branches')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.terminals_count', 0);
    $this->actingAsApi($user)->postJson('/api/v1/branches', ['code' => 'X', 'name' => 'X'])->assertForbidden();

    $cashier = $this->createUser($this->tenant, 'cashier');
    $this->actingAsApi($cashier)->getJson('/api/v1/branches')->assertForbidden();
});

it('filters and searches branches', function () {
    $this->actingAsApi($this->owner)->postJson('/api/v1/branches', ['code' => 'CAL', 'name' => 'Caloocan'])->assertCreated();
    $id = $this->postJson('/api/v1/branches', ['code' => 'MAL', 'name' => 'Malabon'])->json('data.id');
    $this->postJson("/api/v1/branches/{$id}/deactivate")->assertOk();

    expect(collect($this->getJson('/api/v1/branches?status=active')->json('data'))->pluck('code')->all())->toBe(['MAIN', 'CAL'])
        ->and(collect($this->getJson('/api/v1/branches?status=inactive')->json('data'))->pluck('code')->all())->toBe(['MAL'])
        ->and(collect($this->getJson('/api/v1/branches?search=calo')->json('data'))->pluck('code')->all())->toBe(['CAL']);
});

it('leaves fields that were not sent unchanged', function () {
    $this->actingAsApi($this->owner)->putJson("/api/v1/branches/{$this->main->id}", ['code' => 'MAIN', 'name' => 'Head Office'])->assertOk();

    expect($this->main->fresh()->phone)->toBe('+639171234567');
});

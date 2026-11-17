<?php

use App\Models\AuditLog;
use App\Models\CashRegister;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    $this->actingAsApi($this->owner);
});

function terminalPayload(int $branchId, array $overrides = []): array
{
    return $overrides + ['branch_id' => $branchId, 'code' => 'pos-01', 'name' => 'Counter 1'];
}

function openSessionFor(int $tenantId, int $branchId, int $terminalId, int $userId, ?int $registerId = null): void
{
    DB::table('cash_register_sessions')->insert([
        'tenant_id' => $tenantId, 'client_uuid' => (string) Str::uuid(), 'branch_id' => $branchId,
        'terminal_id' => $terminalId, 'cash_register_id' => $registerId, 'user_id' => $userId, 'status' => 'open',
        'opened_at' => now(), 'opening_float' => '500.00', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('creates a terminal with its own cash drawer', function () {
    $this->postJson('/api/v1/terminals', terminalPayload($this->main->id, ['min' => '1234567890', 'ptu_no' => 'FP1234']))
        ->assertCreated()
        ->assertJsonPath('data.code', 'POS-01')
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.branch.code', 'MAIN')
        ->assertJsonPath('data.device', null)
        ->assertJsonPath('data.cash_registers.0.name', 'Counter 1 drawer');
});

it('can skip creating the drawer', function () {
    $this->postJson('/api/v1/terminals', terminalPayload($this->main->id, ['create_cash_register' => false]))
        ->assertCreated()->assertJsonCount(0, 'data.cash_registers');
});

it('validates code uniqueness, PTU dates and the branch', function () {
    $this->postJson('/api/v1/terminals', terminalPayload($this->main->id))->assertCreated();

    $this->postJson('/api/v1/terminals', terminalPayload(999999, [
        'ptu_issued_at' => '2026-05-01', 'ptu_valid_until' => '2026-01-01',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['branch_id', 'code', 'ptu_valid_until']);
});

it('enforces the plan terminal limit', function () {
    Subscription::withoutTenantScope()->where('tenant_id', $this->tenant->id)
        ->update(['subscription_plan_id' => limitedPlan()->id]);

    $this->postJson('/api/v1/terminals', terminalPayload($this->main->id))->assertCreated();
    $this->postJson('/api/v1/terminals', terminalPayload($this->main->id, ['code' => 'POS-02']))
        ->assertStatus(402)->assertJsonPath('code', 'PLAN_LIMIT_REACHED');
});

it('deactivates, reactivates and retires a terminal; retiring revokes the device', function () {
    $id = $this->postJson('/api/v1/terminals', terminalPayload($this->main->id))->json('data.id');
    $token = $this->postJson("/api/v1/terminals/{$id}/device", ['device_label' => 'Counter PC'])->assertCreated()->json('data.device_token');

    $this->postJson("/api/v1/terminals/{$id}/deactivate")->assertOk()->assertJsonPath('data.status', 'inactive');
    $this->postJson("/api/v1/terminals/{$id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
    $this->postJson("/api/v1/terminals/{$id}/retire")->assertOk()
        ->assertJsonPath('data.status', 'retired')
        ->assertJsonPath('data.device', null)
        ->assertJsonPath('data.cash_registers.0.is_active', false);

    $this->putJson("/api/v1/terminals/{$id}", terminalPayload($this->main->id, ['name' => 'Renamed']))
        ->assertUnprocessable()->assertJsonPath('code', 'TERMINAL_RETIRED');
    $this->postJson("/api/v1/terminals/{$id}/activate")->assertUnprocessable()->assertJsonPath('code', 'TERMINAL_RETIRED');

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/terminal/ping')->assertUnauthorized();

    expect(AuditLog::query()->where('event', 'terminal.status_changed')->where('auditable_id', $id)->count())->toBe(3);
});

it('blocks deactivation while a register session is open, and moving a terminal with history', function () {
    $second = $this->postJson('/api/v1/branches', ['code' => 'B2', 'name' => 'Second'])->json('data.id');
    $id = $this->postJson('/api/v1/terminals', terminalPayload($this->main->id))->json('data.id');

    openSessionFor($this->tenant->id, $this->main->id, $id, $this->owner->id);

    $this->postJson("/api/v1/terminals/{$id}/deactivate")->assertUnprocessable()->assertJsonPath('code', 'TERMINAL_HAS_OPEN_SESSION');
    $this->postJson("/api/v1/terminals/{$id}/retire")->assertUnprocessable()->assertJsonPath('code', 'TERMINAL_HAS_OPEN_SESSION');
    $this->putJson("/api/v1/terminals/{$id}", terminalPayload($second, ['code' => 'POS-01']))
        ->assertUnprocessable()->assertJsonPath('code', 'TERMINAL_HAS_HISTORY');
});

it('moves a terminal without history together with its drawer', function () {
    $second = $this->postJson('/api/v1/branches', ['code' => 'B2', 'name' => 'Second'])->json('data.id');
    $id = $this->postJson('/api/v1/terminals', terminalPayload($this->main->id))->json('data.id');

    $this->putJson("/api/v1/terminals/{$id}", terminalPayload($second, ['code' => 'POS-01', 'name' => 'Moved']))
        ->assertOk()->assertJsonPath('data.branch_id', $second)->assertJsonPath('data.cash_registers.0.branch_id', $second);
});

it('manages cash drawers within a branch', function () {
    $terminal = $this->postJson('/api/v1/terminals', terminalPayload($this->main->id, ['create_cash_register' => false]))->json('data.id');
    $second = $this->postJson('/api/v1/branches', ['code' => 'B2', 'name' => 'Second'])->json('data.id');

    // A terminal of another branch is rejected.
    $this->postJson('/api/v1/cash-registers', ['branch_id' => $second, 'terminal_id' => $terminal, 'name' => 'Wrong'])
        ->assertUnprocessable()->assertJsonValidationErrors(['terminal_id']);

    $register = $this->postJson('/api/v1/cash-registers', ['branch_id' => $this->main->id, 'terminal_id' => $terminal, 'name' => 'Drawer A'])
        ->assertCreated()->assertJsonPath('data.terminal.id', $terminal)->json('data.id');

    $this->putJson("/api/v1/cash-registers/{$register}", ['branch_id' => $this->main->id, 'terminal_id' => null, 'name' => 'Shared drawer'])
        ->assertOk()->assertJsonPath('data.terminal_id', null);

    openSessionFor($this->tenant->id, $this->main->id, $terminal, $this->owner->id, $register);
    $this->postJson("/api/v1/cash-registers/{$register}/deactivate")->assertUnprocessable()->assertJsonPath('code', 'REGISTER_HAS_OPEN_SESSION');

    $count = $this->asTenant($this->tenant, fn () => CashRegister::query()->count());
    expect($count)->toBe(1);
});

it('is limited to users who manage terminals', function () {
    $manager = $this->createUser($this->tenant, 'branch_manager');

    $this->actingAsApi($manager)->getJson('/api/v1/terminals')->assertForbidden();
    $this->actingAsApi($manager)->getJson('/api/v1/cash-registers')->assertForbidden();
});

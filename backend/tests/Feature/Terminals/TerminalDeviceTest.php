<?php

use App\Models\AuditLog;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner] = $this->createTenant();
    $this->terminal = $this->createTerminal($this->tenant);
});

it('registers a device and the device token can call terminal endpoints only', function () {
    $token = $this->actingAsApi($this->owner)
        ->postJson("/api/v1/terminals/{$this->terminal->id}/device", ['device_label' => 'Sunmi T2', 'device_platform' => 'sunmi'])
        ->assertCreated()
        ->assertJsonPath('data.terminal.device_platform', 'sunmi')
        ->json('data.device_token');

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/terminal/ping')
        ->assertOk()
        ->assertJsonPath('data.terminal.id', $this->terminal->id)
        ->assertJsonPath('data.tenant.id', $this->tenant->id)
        ->assertJsonStructure(['data' => ['server_time']]);

    // A device token is not a user.
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertForbidden()->assertJsonPath('code', 'USER_REQUIRED');

    expect(AuditLog::query()->where('event', 'terminal.device_registered')->exists())->toBeTrue();
});

it('replaces the previous device when re-registering', function () {
    $first = $this->actingAsApi($this->owner)
        ->postJson("/api/v1/terminals/{$this->terminal->id}/device", ['device_label' => 'Old PC'])->json('data.device_token');
    $this->actingAsApi($this->owner)
        ->postJson("/api/v1/terminals/{$this->terminal->id}/device", ['device_label' => 'New tablet'])->assertCreated();

    $this->app['auth']->forgetGuards();
    $this->withToken($first)->getJson('/api/v1/terminal/ping')->assertUnauthorized();
});

it('revokes a device', function () {
    $token = $this->actingAsApi($this->owner)
        ->postJson("/api/v1/terminals/{$this->terminal->id}/device", ['device_label' => 'PC'])->json('data.device_token');

    $this->actingAsApi($this->owner)->deleteJson("/api/v1/terminals/{$this->terminal->id}/device")->assertNoContent();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/terminal/ping')->assertUnauthorized();
});

it('rejects user tokens on terminal endpoints', function () {
    $this->actingAsApi($this->owner)->getJson('/api/v1/terminal/ping')
        ->assertForbidden()->assertJsonPath('code', 'TERMINAL_REQUIRED');
});

it('requires terminals.manage to register devices', function () {
    $cashier = $this->createUser($this->tenant, 'cashier');

    $this->actingAsApi($cashier)
        ->postJson("/api/v1/terminals/{$this->terminal->id}/device", ['device_label' => 'X'])
        ->assertForbidden();
});

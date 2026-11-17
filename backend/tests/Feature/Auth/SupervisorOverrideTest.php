<?php

use App\Exceptions\DomainException;
use App\Models\AuditLog;
use App\Services\Auth\SupervisorOverrideService;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

beforeEach(function () {
    ['tenant' => $this->tenant] = $this->createTenant();
    $this->cashier = $this->createUser($this->tenant, 'cashier');
    $this->manager = $this->createUser($this->tenant, 'branch_manager');
    $this->asTenant($this->tenant, fn () => $this->manager->setPin('2580'));
});

it('lets a user set their own PIN with their current password', function () {
    $this->actingAsApi($this->cashier)->putJson('/api/v1/auth/pin', [
        'current_password' => TestCase::PASSWORD,
        'pin' => '9753',
        'pin_confirmation' => '9753',
    ])->assertOk();

    expect(Hash::check('9753', $this->cashier->fresh()->pin_hash))->toBeTrue();
});

it('rejects weak PINs and wrong passwords', function () {
    $this->actingAsApi($this->cashier)->putJson('/api/v1/auth/pin', [
        'current_password' => 'wrong',
        'pin' => '1234',
        'pin_confirmation' => '1234',
    ])->assertUnprocessable()->assertJsonValidationErrors(['current_password', 'pin']);
});

it('grants a single-use override token when a supervisor PIN is valid', function () {
    $token = $this->actingAsApi($this->cashier)->postJson('/api/v1/auth/override', [
        'supervisor_id' => $this->manager->id,
        'pin' => '2580',
        'permission' => 'sales.void',
    ])->assertCreated()
        ->assertJsonPath('data.supervisor.id', $this->manager->id)
        ->json('data.override_token');

    $service = app(SupervisorOverrideService::class);
    $approver = $this->asTenant($this->tenant, fn () => $service->consume($this->cashier, $token, 'sales.void'));

    expect($approver->id)->toBe($this->manager->id)
        ->and(AuditLog::query()->where('event', 'auth.override_granted')->exists())->toBeTrue();

    // Single use
    expect(fn () => $this->asTenant($this->tenant, fn () => $service->consume($this->cashier, $token, 'sales.void')))
        ->toThrow(DomainException::class);
});

it('denies overrides for a wrong PIN or a supervisor lacking the permission', function () {
    $this->actingAsApi($this->cashier)->postJson('/api/v1/auth/override', [
        'supervisor_id' => $this->manager->id, 'pin' => '0001', 'permission' => 'sales.void',
    ])->assertForbidden()->assertJsonPath('code', 'OVERRIDE_DENIED');

    // Branch managers cannot approve expense approvals (not in their role).
    $this->actingAsApi($this->cashier)->postJson('/api/v1/auth/override', [
        'supervisor_id' => $this->manager->id, 'pin' => '2580', 'permission' => 'expenses.approve',
    ])->assertForbidden()->assertJsonPath('code', 'OVERRIDE_DENIED');
});

it('locks a supervisor PIN after repeated failures', function () {
    foreach (range(1, 5) as $i) {
        $this->actingAsApi($this->cashier)->postJson('/api/v1/auth/override', [
            'supervisor_id' => $this->manager->id, 'pin' => '0000'.$i % 10, 'permission' => 'sales.void',
        ]);
    }

    $this->actingAsApi($this->cashier)->postJson('/api/v1/auth/override', [
        'supervisor_id' => $this->manager->id, 'pin' => '2580', 'permission' => 'sales.void',
    ])->assertStatus(429)->assertJsonPath('code', 'PIN_LOCKED');
});

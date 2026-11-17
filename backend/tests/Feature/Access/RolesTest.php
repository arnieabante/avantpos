<?php

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Subscription;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner] = $this->createTenant();
});

it('exposes the grouped permission catalogue', function () {
    $this->actingAsApi($this->owner)->getJson('/api/v1/permissions')
        ->assertOk()
        ->assertJsonPath('data.0.key', 'business')
        ->assertJsonStructure(['data' => [['key', 'label', 'permissions' => [['name', 'label']]]]]);
});

it('lets the owner create, update and delete a custom role (audited)', function () {
    $this->actingAsApi($this->owner);

    $id = $this->postJson('/api/v1/roles', [
        'name' => 'Stock Clerk',
        'description' => 'Counts stock',
        'permissions' => ['products.view', 'inventory.view', 'inventory.count'],
    ])->assertCreated()
        ->assertJsonPath('data.is_system', false)
        ->assertJsonPath('data.permissions', ['inventory.count', 'inventory.view', 'products.view'])
        ->json('data.id');

    $this->putJson("/api/v1/roles/{$id}", ['permissions' => ['products.view']])
        ->assertOk()
        ->assertJsonPath('data.permissions', ['products.view']);

    $log = AuditLog::query()->where('event', 'role.updated')->where('auditable_id', $id)->firstOrFail();
    expect($log->meta['removed'])->toBe(['inventory.count', 'inventory.view']);

    $this->deleteJson("/api/v1/roles/{$id}")->assertNoContent();
    expect(Role::query()->find($id))->toBeNull();
});

it('rejects unknown permissions and duplicate names', function () {
    $this->actingAsApi($this->owner)->postJson('/api/v1/roles', [
        'name' => 'Cashier',
        'permissions' => ['products.view', 'nuke.everything'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'permissions.1']);
});

it('protects the locked Owner role', function () {
    $ownerRole = $this->asTenant($this->tenant, fn () => Role::forCurrentTenant()->where('key', 'owner')->firstOrFail());

    $this->actingAsApi($this->owner)->putJson("/api/v1/roles/{$ownerRole->id}", ['name' => 'Boss'])
        ->assertUnprocessable()->assertJsonPath('code', 'ROLE_LOCKED');

    $this->actingAsApi($this->owner)->deleteJson("/api/v1/roles/{$ownerRole->id}")
        ->assertUnprocessable()->assertJsonPath('code', 'ROLE_LOCKED');
});

it('does not delete default roles or roles still in use', function () {
    $cashierRole = $this->asTenant($this->tenant, fn () => Role::forCurrentTenant()->where('key', 'cashier')->firstOrFail());

    $this->actingAsApi($this->owner)->deleteJson("/api/v1/roles/{$cashierRole->id}")
        ->assertUnprocessable()->assertJsonPath('code', 'ROLE_SYSTEM');
});

it('forbids users without roles.manage', function () {
    $cashier = $this->createUser($this->tenant, 'cashier');

    $this->actingAsApi($cashier)->getJson('/api/v1/roles')
        ->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
});

it('prevents privilege escalation when editing roles', function () {
    // A custom "role admin" that can manage roles but cannot void sales.
    $this->actingAsApi($this->owner);
    $roleAdminRoleId = $this->postJson('/api/v1/roles', [
        'name' => 'Role Admin',
        'permissions' => ['roles.manage', 'products.view'],
    ])->json('data.id');

    $roleAdmin = $this->createUser($this->tenant, 'cashier');
    $this->asTenant($this->tenant, fn () => $roleAdmin->syncRoles([Role::query()->find($roleAdminRoleId)]));

    $this->actingAsApi($roleAdmin->fresh())->postJson('/api/v1/roles', [
        'name' => 'Super Cashier',
        'permissions' => ['pos.sell', 'sales.void'],
    ])->assertForbidden()
        ->assertJsonPath('code', 'PERMISSION_ESCALATION')
        ->assertJsonPath('context.permissions', ['pos.sell', 'sales.void']);
});

it('requires the custom_roles plan feature', function () {
    $this->asTenant($this->tenant, function () {
        Subscription::query()->first()->update([
            'subscription_plan_id' => limitedPlan()->id,
        ]);
    });

    $this->actingAsApi($this->owner)->postJson('/api/v1/roles', [
        'name' => 'Clerk', 'permissions' => ['products.view'],
    ])->assertStatus(402)->assertJsonPath('code', 'FEATURE_NOT_AVAILABLE');
});

it('reports how many users each role has', function () {
    ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();
    $this->createUser($tenant, 'cashier');
    $this->createUser($tenant, 'cashier');

    $roles = collect($this->actingAsApi($owner)->getJson('/api/v1/roles')->assertOk()->json('data'))->keyBy('key');

    expect($roles['owner']['users_count'])->toBe(1)
        ->and($roles['cashier']['users_count'])->toBe(2)
        ->and($roles['accountant']['users_count'])->toBe(0);
});

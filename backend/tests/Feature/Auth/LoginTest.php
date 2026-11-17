<?php

use App\Enums\TenantStatus;
use App\Models\AuditLog;
use Tests\TestCase;

it('logs a user in with a session cookie and returns the account payload', function () {
    ['owner' => $owner] = $this->createTenant();

    $this->withHeader('Origin', 'https://app.avantpos.ph')
        ->postJson('/api/v1/auth/login', ['email' => strtoupper($owner->email), 'password' => TestCase::PASSWORD])
        ->assertOk()
        ->assertJsonPath('data.user.id', $owner->id)
        ->assertJsonStructure(['data' => ['user', 'tenant', 'roles', 'permissions', 'branches', 'subscription']]);

    $this->assertAuthenticatedAs($owner, 'web');
    expect(AuditLog::query()->where('event', 'auth.login')->where('actor_id', $owner->id)->exists())->toBeTrue();
});

it('rejects wrong credentials with a generic message', function () {
    ['owner' => $owner] = $this->createTenant();

    $this->withHeader('Origin', 'https://app.avantpos.ph')
        ->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => 'wrong-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->withHeader('Origin', 'https://app.avantpos.ph')
        ->postJson('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'whatever'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');

    $this->assertGuest('web');
});

it('blocks users of a suspended business', function () {
    ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();
    $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

    $this->withHeader('Origin', 'https://app.avantpos.ph')
        ->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => TestCase::PASSWORD])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_SUSPENDED');
});

it('blocks deactivated users', function () {
    ['tenant' => $tenant] = $this->createTenant();
    $cashier = $this->createUser($tenant, 'cashier');
    $cashier->forceFill(['status' => 'inactive'])->save();

    $this->withHeader('Origin', 'https://app.avantpos.ph')
        ->postJson('/api/v1/auth/login', ['email' => $cashier->email, 'password' => TestCase::PASSWORD])
        ->assertForbidden()
        ->assertJsonPath('code', 'USER_INACTIVE');
});

it("rejects login from another business's subdomain and points to the right one", function () {
    $this->createTenant(['subdomain' => 'otherstore']);
    ['owner' => $owner] = $this->createTenant(['subdomain' => 'mystore']);

    $this->withHeader('Origin', 'https://otherstore.avantpos.ph')
        ->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => TestCase::PASSWORD])
        ->assertForbidden()
        ->assertJsonPath('code', 'TENANT_MISMATCH')
        ->assertJsonPath('context.login_url', 'https://mystore.avantpos.ph');

    $this->assertGuest('web');

    $this->withHeader('Origin', 'https://mystore.avantpos.ph')
        ->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => TestCase::PASSWORD])
        ->assertOk();
});

it('refuses cookie login for non-browser clients', function () {
    ['owner' => $owner] = $this->createTenant();

    // No Origin/Referer from a stateful domain -> no session.
    $this->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => TestCase::PASSWORD])
        ->assertStatus(400)
        ->assertJsonPath('code', 'SESSION_UNAVAILABLE');
});

it('issues a bearer token for native/mobile apps', function () {
    ['owner' => $owner] = $this->createTenant();

    $token = $this->postJson('/api/v1/auth/token', [
        'email' => $owner->email,
        'password' => TestCase::PASSWORD,
        'device_name' => 'Android phone',
    ])->assertCreated()->json('data.token');

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('data.user.id', $owner->id);

    $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('logs out a session user', function () {
    ['owner' => $owner] = $this->createTenant();

    $this->withHeader('Origin', 'https://app.avantpos.ph')
        ->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => TestCase::PASSWORD])
        ->assertOk();

    $this->withHeader('Origin', 'https://app.avantpos.ph')->postJson('/api/v1/auth/logout')->assertOk();
    $this->assertGuest('web');
});

it('lets a signed-in user of a business suspended mid-session log out', function () {
    ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();

    $this->withHeader('Origin', 'https://app.avantpos.ph')
        ->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => TestCase::PASSWORD])
        ->assertOk();

    $tenant->forceFill(['status' => TenantStatus::Suspended])->save();

    $this->withHeader('Origin', 'https://app.avantpos.ph')->getJson('/api/v1/auth/me')
        ->assertForbidden()->assertJsonPath('code', 'TENANT_SUSPENDED');

    $this->withHeader('Origin', 'https://app.avantpos.ph')->postJson('/api/v1/auth/logout')->assertOk();
    $this->assertGuest('web');

    $log = AuditLog::query()->where('event', 'auth.logout')->where('actor_id', $owner->id)->first();
    expect($log?->tenant_id)->toBe($tenant->id);
});

it('lets a session user log out from another business subdomain', function () {
    ['owner' => $owner] = $this->createTenant();

    $this->withHeader('Origin', 'https://app.avantpos.ph')
        ->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => TestCase::PASSWORD])
        ->assertOk();

    $this->withHeader('Origin', 'https://someoneelse.avantpos.ph')->getJson('/api/v1/auth/me')
        ->assertForbidden()->assertJsonPath('code', 'TENANT_MISMATCH');

    $this->withHeader('Origin', 'https://someoneelse.avantpos.ph')->postJson('/api/v1/auth/logout')->assertOk();
    $this->assertGuest('web');
});

it('does not let a POS device token use the user logout endpoint', function () {
    ['tenant' => $tenant, 'owner' => $owner] = $this->createTenant();
    $terminal = $this->createTerminal($tenant);

    $token = $this->actingAsApi($owner)
        ->postJson("/api/v1/terminals/{$terminal->id}/device", ['device_label' => 'Counter PC'])
        ->assertCreated()
        ->json('data.device_token');

    $this->app['auth']->forgetGuards();

    $this->withToken($token)->postJson('/api/v1/auth/logout')
        ->assertForbidden()->assertJsonPath('code', 'USER_REQUIRED');

    // The device stays registered.
    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/terminal/ping')->assertOk();
});

it('returns 401 for unauthenticated requests', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
});

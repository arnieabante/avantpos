<?php

use App\Models\PlatformAdmin;
use Tests\TestCase;

beforeEach(function () {
    $this->admin = PlatformAdmin::query()->create([
        'name' => 'Admin',
        'email' => 'admin@example.com',
        'password' => 'AdminSecret1',
        'role' => PlatformAdmin::ROLE_SUPER_ADMIN,
        'is_active' => true,
    ]);
});

it('logs a platform admin in on the separate guard', function () {
    $this->withHeader('Origin', 'https://app.avantpos.ph')
        ->postJson('/api/admin/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'AdminSecret1'])
        ->assertOk()
        ->assertJsonPath('data.role', 'super_admin');

    $this->assertAuthenticatedAs($this->admin, 'platform');
    $this->assertGuest('web');

    $this->withHeader('Origin', 'https://app.avantpos.ph')->getJson('/api/admin/v1/auth/me')->assertOk();
});

it('rejects inactive admins', function () {
    $this->admin->update(['is_active' => false]);

    $this->withHeader('Origin', 'https://app.avantpos.ph')
        ->postJson('/api/admin/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'AdminSecret1'])
        ->assertUnprocessable();
});

it('does not let tenant users into the platform admin API', function () {
    ['owner' => $owner] = $this->createTenant();

    $this->withHeader('Origin', 'https://app.avantpos.ph')
        ->postJson('/api/v1/auth/login', ['email' => $owner->email, 'password' => TestCase::PASSWORD])
        ->assertOk();

    $this->withHeader('Origin', 'https://app.avantpos.ph')->getJson('/api/admin/v1/auth/me')->assertUnauthorized();
});

it('does not let platform admins into the tenant API', function () {
    $this->withHeader('Origin', 'https://app.avantpos.ph')
        ->postJson('/api/admin/v1/auth/login', ['email' => 'admin@example.com', 'password' => 'AdminSecret1'])
        ->assertOk();

    $this->withHeader('Origin', 'https://app.avantpos.ph')->getJson('/api/v1/auth/me')->assertUnauthorized();
});

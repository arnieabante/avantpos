<?php

use App\Models\PlatformAdmin;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/*
| Accounts whose password was chosen by someone else must set their own at
| their first sign-in; until then everything else is refused.
*/

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'enterprise');
    $this->as = fn (User $u) => $this->actingAsApi($u)->withHeader('X-Branch-Id', (string) $this->main->id);
});

it('makes a user added by an administrator set their own password first', function () {
    ($this->as)($this->owner);
    $role = $this->asTenant($this->tenant, fn () => Role::forCurrentTenant()->where('key', 'cashier')->value('id'));
    $id = $this->postJson('/api/v1/users', ['name' => 'Ana Cruz', 'email' => 'ana@example.com', 'role_id' => $role, 'branch_ids' => [$this->main->id],
        'password' => 'Start-pass-1', 'password_confirmation' => 'Start-pass-1'])->assertCreated()->json('data.id');
    app('auth')->forgetGuards();

    $ana = User::withoutTenantScope()->findOrFail($id);
    ($this->as)($ana);
    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.must_change_password', true);
    $this->getJson('/api/v1/products')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    $this->postJson('/api/v1/pos/quote', ['items' => []])->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');

    // The same password again is not accepted; a new one clears the requirement.
    $this->putJson('/api/v1/auth/password', ['current_password' => 'Start-pass-1', 'password' => 'Start-pass-1', 'password_confirmation' => 'Start-pass-1'])
        ->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->putJson('/api/v1/auth/password', ['current_password' => 'Start-pass-1', 'password' => 'Own-secret-2', 'password_confirmation' => 'Own-secret-2'])->assertOk();

    $this->getJson('/api/v1/auth/me')->assertJsonPath('data.user.must_change_password', false);
    $this->getJson('/api/v1/products')->assertOk();
    expect(Hash::check('Own-secret-2', $ana->fresh()->password))->toBeTrue();
});

it('does not ask people who chose their own password', function () {
    ($this->as)($this->owner);
    $this->getJson('/api/v1/auth/me')->assertJsonPath('data.user.must_change_password', false);
    $this->getJson('/api/v1/products')->assertOk();
});

it('clears the requirement after a password reset by e-mail', function () {
    $user = $this->createUser($this->tenant, 'cashier');
    $user->forceFill(['must_change_password' => true])->save();
    $token = app('auth.password.broker')->createToken($user);
    $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Fresh-pass-9', 'password_confirmation' => 'Fresh-pass-9'])->assertOk();
    expect($user->fresh()->must_change_password)->toBeFalse();
});

it('makes platform admins added by another admin set their own password first', function () {
    $super = PlatformAdmin::query()->create(['name' => 'Chief', 'email' => 'chief@example.com', 'password' => 'AdminSecret1', 'role' => 'super_admin', 'is_active' => true])->fresh();
    $asAdmin = fn (PlatformAdmin $a) => $this->actingAs($a, 'platform')->withHeader('Origin', 'https://app.avantpos.ph');
    $asAdmin($super);
    $id = $this->postJson('/api/admin/v1/admins', ['name' => 'Ana', 'email' => 'ana@avantpos.ph', 'role' => 'support', 'password' => 'Start-pass-1', 'password_confirmation' => 'Start-pass-1'])
        ->assertCreated()->json('data.id');
    app('auth')->forgetGuards();

    $ana = PlatformAdmin::query()->findOrFail($id);
    $asAdmin($ana);
    $this->getJson('/api/admin/v1/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true);
    $this->getJson('/api/admin/v1/tenants')->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    $this->putJson('/api/admin/v1/auth/password', ['current_password' => 'wrong', 'password' => 'Own-secret-2', 'password_confirmation' => 'Own-secret-2'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_password');
    $this->putJson('/api/admin/v1/auth/password', ['current_password' => 'Start-pass-1', 'password' => 'Own-secret-2', 'password_confirmation' => 'Own-secret-2'])
        ->assertOk()->assertJsonPath('data.must_change_password', false);
    app('auth')->forgetGuards(); // the next request reads the account again
    $asAdmin($ana->fresh());
    $this->getJson('/api/admin/v1/tenants')->assertOk();

    // A password set by another admin is a starting password again.
    app('auth')->forgetGuards();
    $asAdmin($super);
    $this->postJson("/api/admin/v1/admins/{$id}/password", ['password' => 'Reset-pass-3', 'password_confirmation' => 'Reset-pass-3'])->assertOk();
    expect($ana->fresh()->must_change_password)->toBeTrue();
});

it('leaves POS devices alone', function () {
    $terminal = $this->createTerminal($this->tenant, $this->main);
    $this->owner->forceFill(['must_change_password' => true])->save();
    $this->actingAsApi($terminal, ['terminal:sync'])->getJson('/api/v1/terminal/ping')->assertOk();
});

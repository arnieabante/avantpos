<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\Auth\QueuedResetPassword;
use App\Services\Users\InvitationService;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    $this->second = $this->asTenant($this->tenant, fn () => Branch::query()->create(['code' => 'B2', 'name' => 'Second', 'is_active' => true]));
    $this->role = fn (string $key) => $this->asTenant($this->tenant, fn () => Role::forCurrentTenant()->where('key', $key)->firstOrFail());

    $this->admin = $this->createUser($this->tenant, 'business_admin');
    $this->cashierMain = $this->createUser($this->tenant, 'cashier', [$this->main->id]);
    $this->cashierSecond = $this->createUser($this->tenant, 'cashier', [$this->second->id]);
    $this->managerSecond = $this->createUser($this->tenant, 'branch_manager', [$this->second->id]);
});

it('lists users with role and branches, filtered and paginated', function () {
    $this->actingAsApi($this->owner);

    $response = $this->getJson('/api/v1/users?per_page=2')->assertOk()
        ->assertJsonPath('meta.pagination.total', 5)
        ->assertJsonPath('meta.pagination.per_page', 2);
    expect($response->json('data.0'))->toHaveKeys(['role', 'branches', 'has_pin', 'is_owner', 'all_branches']);

    $cashierRole = ($this->role)('cashier');
    expect(collect($this->getJson("/api/v1/users?role_id={$cashierRole->id}")->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$this->cashierMain->id, $this->cashierSecond->id])->sort()->values()->all());

    expect(collect($this->getJson("/api/v1/users?branch_id={$this->second->id}")->json('data'))->pluck('id'))
        ->toContain($this->cashierSecond->id)->not->toContain($this->cashierMain->id);

    $owner = collect($this->getJson('/api/v1/users?search='.urlencode($this->owner->email))->json('data'))->first();
    expect($owner['is_owner'])->toBeTrue()->and($owner['all_branches'])->toBeTrue()->and($owner['role']['key'])->toBe('owner');
});

it('shows branch-limited users only the users of their branches', function () {
    $ids = collect($this->actingAsApi($this->managerSecond)->getJson('/api/v1/users')->assertOk()->json('data'))->pluck('id');

    expect($ids)->toContain($this->cashierSecond->id)->toContain($this->managerSecond->id)
        ->not->toContain($this->cashierMain->id);

    $this->getJson("/api/v1/users/{$this->cashierMain->id}")->assertNotFound();
});

it('changes role, branches and contact details with an audit trail', function () {
    $manager = ($this->role)('branch_manager');

    $this->actingAsApi($this->admin)->putJson("/api/v1/users/{$this->cashierMain->id}", [
        'name' => 'Promoted Cashier',
        'phone' => '0918 765 4321',
        'role_id' => $manager->id,
        'branch_ids' => [$this->main->id, $this->second->id],
    ])->assertOk()
        ->assertJsonPath('data.role.key', 'branch_manager')
        ->assertJsonPath('data.phone', '+639187654321')
        ->assertJsonCount(2, 'data.branches');

    expect(AuditLog::query()->where('event', 'user.role_changed')->where('auditable_id', $this->cashierMain->id)->first()->new_values)
        ->toBe(['role' => 'Branch Manager'])
        ->and(AuditLog::query()->where('event', 'user.branches_changed')->where('auditable_id', $this->cashierMain->id)->exists())->toBeTrue();
});

it('protects the owner and the actor themself', function () {
    $this->actingAsApi($this->admin);

    $this->putJson("/api/v1/users/{$this->owner->id}", ['name' => 'Hijacked'])->assertForbidden()->assertJsonPath('code', 'OWNER_LOCKED');
    $this->postJson("/api/v1/users/{$this->owner->id}/deactivate")->assertForbidden()->assertJsonPath('code', 'OWNER_LOCKED');

    $this->putJson("/api/v1/users/{$this->admin->id}", ['role_id' => ($this->role)('cashier')->id])->assertForbidden()->assertJsonPath('code', 'CANNOT_CHANGE_SELF');
    $this->postJson("/api/v1/users/{$this->admin->id}/deactivate")->assertForbidden()->assertJsonPath('code', 'CANNOT_CHANGE_SELF');

    // Editing your own name is fine.
    $this->putJson("/api/v1/users/{$this->admin->id}", ['name' => 'Admin Two'])->assertOk();

    // The owner may edit their own details but not their role.
    $this->actingAsApi($this->owner)->putJson("/api/v1/users/{$this->owner->id}", ['role_id' => ($this->role)('cashier')->id])
        ->assertForbidden()->assertJsonPath('code', 'CANNOT_CHANGE_SELF');
});

it('never assigns the owner role or more permissions than the actor holds', function () {
    $this->actingAsApi($this->owner)->putJson("/api/v1/users/{$this->cashierMain->id}", ['role_id' => ($this->role)('owner')->id])
        ->assertForbidden()->assertJsonPath('code', 'OWNER_ROLE_LOCKED');

    // A custom role with a permission the admin lacks (billing.manage).
    $custom = $this->asTenant($this->tenant, function () {
        $role = Role::query()->create(['name' => 'Billing clerk', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id, 'is_system' => false, 'is_locked' => false]);
        $role->syncPermissions(['billing.manage', 'pos.sell']);

        return $role;
    });

    $this->actingAsApi($this->admin)->putJson("/api/v1/users/{$this->cashierMain->id}", ['role_id' => $custom->id])
        ->assertForbidden()->assertJsonPath('code', 'PERMISSION_ESCALATION');

    expect(collect($this->getJson('/api/v1/roles/assignable')->assertOk()->json('data'))->pluck('key')->filter()->all())
        ->not->toContain('owner')
        ->and(collect($this->getJson('/api/v1/roles/assignable')->json('data'))->pluck('id'))->not->toContain($custom->id);
});

it('does not let a user manage someone more powerful', function () {
    // An "HR" role with users.manage only: weaker than the cashier? No — it lacks pos.sell, so it cannot manage cashiers either.
    $hr = $this->asTenant($this->tenant, function () {
        $role = Role::query()->create(['name' => 'HR', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id, 'is_system' => false, 'is_locked' => false]);
        $role->syncPermissions(['users.view', 'users.manage', 'branches.access_all']);

        return $role;
    });
    $hrUser = $this->createUser($this->tenant, 'cashier');
    $this->asTenant($this->tenant, fn () => $hrUser->syncRoles([$hr]));

    $this->actingAsApi($hrUser->fresh())->postJson("/api/v1/users/{$this->admin->id}/deactivate")
        ->assertForbidden()->assertJsonPath('code', 'PERMISSION_ESCALATION');
});

it('deactivates a user and signs them out everywhere, then reactivates within the plan limit', function () {
    $token = $this->cashierMain->createToken('phone')->plainTextToken;
    DB::table('sessions')->insert(['id' => 'sess-cashier', 'user_id' => $this->cashierMain->id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAsApi($this->admin)->postJson("/api/v1/users/{$this->cashierMain->id}/deactivate")
        ->assertOk()->assertJsonPath('data.status', 'inactive');

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $this->cashierMain->id)->where('tokenable_type', User::class)->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', 'sess-cashier')->exists())->toBeFalse();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();

    // Basic allows 3 active users: owner + admin + 2 active others already exceed it.
    Subscription::withoutTenantScope()->where('tenant_id', $this->tenant->id)
        ->update(['subscription_plan_id' => SubscriptionPlan::query()->where('code', 'basic')->value('id')]);
    $this->actingAsApi($this->admin)->postJson("/api/v1/users/{$this->cashierMain->id}/activate")
        ->assertStatus(402)->assertJsonPath('code', 'PLAN_LIMIT_REACHED');

    Subscription::withoutTenantScope()->where('tenant_id', $this->tenant->id)
        ->update(['subscription_plan_id' => SubscriptionPlan::query()->where('code', 'professional')->value('id')]);
    $this->postJson("/api/v1/users/{$this->cashierMain->id}/activate")->assertOk()->assertJsonPath('data.status', 'active');
});

it('clears a forgotten PIN and sends a password reset link', function () {
    $this->asTenant($this->tenant, fn () => $this->cashierMain->setPin('8642'));
    $this->actingAsApi($this->admin);

    $this->deleteJson("/api/v1/users/{$this->cashierMain->id}/pin")->assertOk()->assertJsonPath('data.has_pin', false);

    $this->postJson("/api/v1/users/{$this->cashierMain->id}/password-reset")->assertOk();
    Notification::assertSentTo($this->cashierMain, QueuedResetPassword::class);
});

it('rejects inactive or inaccessible branches when assigning', function () {
    $this->asTenant($this->tenant, fn () => $this->second->forceFill(['is_active' => false])->save());

    $this->actingAsApi($this->admin)->putJson("/api/v1/users/{$this->cashierMain->id}", ['branch_ids' => [$this->second->id]])
        ->assertForbidden()->assertJsonPath('code', 'BRANCH_FORBIDDEN');
});

it('requires users.manage to change users', function () {
    $this->actingAsApi($this->managerSecond)->putJson("/api/v1/users/{$this->cashierSecond->id}", ['name' => 'X Y'])->assertForbidden();
    $this->actingAsApi($this->cashierMain)->getJson('/api/v1/users')->assertForbidden();
});

it('shows branch-limited users only their own and their branches\' invitations', function () {
    $service = app(InvitationService::class);
    $cashierRole = ($this->role)('cashier');

    [$mainInvite, $secondInvite] = $this->asTenant($this->tenant, function () use ($service, $cashierRole) {
        $this->actingAsApi($this->owner);

        return [
            $service->invite($this->owner, ['email' => 'main-new@example.com', 'role_id' => $cashierRole->id, 'branch_ids' => [$this->main->id]]),
            $service->invite($this->owner, ['email' => 'second-new@example.com', 'role_id' => $cashierRole->id, 'branch_ids' => [$this->second->id]]),
        ];
    });
    app(TenantContext::class)->forget();

    $ids = collect($this->actingAsApi($this->managerSecond)->getJson('/api/v1/invitations')->assertOk()->json('data'))->pluck('id');
    expect($ids)->toContain($secondInvite->id)->not->toContain($mainInvite->id);

    $this->deleteJson("/api/v1/invitations/{$mainInvite->id}")->assertNotFound();
    $this->deleteJson("/api/v1/invitations/{$secondInvite->id}")->assertNoContent();
});

it('lets the owner or an administrator add a user directly with a starting password', function () {
    $cashier = ($this->role)('cashier');

    $created = $this->actingAsApi($this->admin)->postJson('/api/v1/users', [
        'name' => 'Ana Cruz', 'email' => ' Ana.Cruz@Example.com ', 'phone' => '0917 111 2222',
        'role_id' => $cashier->id, 'branch_ids' => [$this->second->id],
        'password' => 'Secret-pass1', 'password_confirmation' => 'Secret-pass1',
    ])->assertCreated()
        ->assertJsonPath('data.email', 'ana.cruz@example.com')
        ->assertJsonPath('data.role.key', 'cashier')
        ->assertJsonPath('data.status', 'active')
        ->json('data');

    $user = User::withoutTenantScope()->findOrFail($created['id']);
    expect($user->tenant_id)->toBe($this->tenant->id)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($this->asTenant($this->tenant, fn () => $user->branches()->pluck('branches.id')->all()))->toBe([$this->second->id])
        ->and($this->asTenant($this->tenant, fn () => AuditLog::forCurrentTenant()->where('event', 'user.created')->where('auditable_id', $user->id)->exists()))->toBeTrue();

    // The new user can sign in with the starting password right away.
    $this->app['auth']->forgetGuards();
    $this->postJson('/api/v1/auth/token', ['email' => 'ana.cruz@example.com', 'password' => 'Secret-pass1', 'device_name' => 'test'])->assertCreated();
});

it('applies the invitation rules when adding a user', function () {
    $input = fn (array $over = []) => [
        'name' => 'New Person', 'email' => 'new.person@example.com', 'role_id' => ($this->role)('cashier')->id,
        'branch_ids' => [$this->main->id], 'password' => 'Secret-pass1', 'password_confirmation' => 'Secret-pass1', ...$over,
    ];

    // Only users.manage (branch managers can invite but not add directly).
    $this->actingAsApi($this->managerSecond)->postJson('/api/v1/users', $input(['branch_ids' => [$this->second->id]]))->assertForbidden();

    $this->actingAsApi($this->admin);
    $this->postJson('/api/v1/users', $input(['email' => $this->cashierMain->email]))->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->postJson('/api/v1/users', $input(['password_confirmation' => 'other']))->assertUnprocessable()->assertJsonValidationErrors('password');
    $this->postJson('/api/v1/users', $input(['role_id' => ($this->role)('owner')->id]))->assertForbidden();
    $this->postJson('/api/v1/users', $input(['branch_ids' => []]))->assertUnprocessable()->assertJsonValidationErrors('branch_ids');

    // Plan limit (Basic: 3 active users; there are already 5).
    Subscription::withoutTenantScope()->where('tenant_id', $this->tenant->id)
        ->update(['subscription_plan_id' => SubscriptionPlan::query()->where('code', 'basic')->value('id')]);
    $this->postJson('/api/v1/users', $input())->assertStatus(402)->assertJsonPath('code', 'PLAN_LIMIT_REACHED');
    expect(User::withoutTenantScope()->where('email', 'new.person@example.com')->exists())->toBeFalse();
});

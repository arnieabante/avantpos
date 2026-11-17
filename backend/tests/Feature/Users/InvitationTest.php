<?php

use App\Models\Branch;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserInvitation;
use App\Notifications\UserInvitationNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->branch] = $this->createTenant(['subdomain' => 'invitestore']);
    $this->cashierRole = $this->asTenant($this->tenant, fn () => Role::forCurrentTenant()->where('key', 'cashier')->firstOrFail());
});

function inviteAndCaptureToken($test, array $payload): string
{
    $test->actingAsApi($test->owner)->postJson('/api/v1/invitations', $payload)->assertCreated();

    $token = null;
    Notification::assertSentTo(new AnonymousNotifiable, UserInvitationNotification::class, function ($n, $channels, $notifiable) use (&$token, $payload) {
        if ($notifiable->routes['mail'] !== strtolower($payload['email'])) {
            return false;
        }
        parse_str(parse_url($n->acceptUrl, PHP_URL_QUERY), $query);
        $token = $query['token'];

        return str_starts_with($n->acceptUrl, 'https://invitestore.avantpos.ph/accept-invitation?token=');
    });

    return $token;
}

it('invites a cashier who accepts, gets the role and branch, and is signed in', function () {
    $token = inviteAndCaptureToken($this, [
        'email' => 'New.Cashier@Example.com',
        'name' => 'Juan',
        'role_id' => $this->cashierRole->id,
        'branch_ids' => [$this->branch->id],
    ]);

    $this->getJson("/api/v1/auth/invitations/{$token}")
        ->assertOk()
        ->assertJsonPath('data.email', 'new.cashier@example.com')
        ->assertJsonPath('data.role', 'Cashier');

    $this->withHeader('Origin', 'https://invitestore.avantpos.ph')
        ->postJson("/api/v1/auth/invitations/{$token}/accept", [
            'name' => 'Juan Dela Cruz',
            'phone' => '09181234567',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
        ])->assertCreated()
        ->assertJsonPath('data.user.email_verified', true)
        ->assertJsonPath('data.roles', ['Cashier'])
        ->assertJsonPath('data.branches.0.id', $this->branch->id);

    $user = User::withoutTenantScope()->where('email', 'new.cashier@example.com')->firstOrFail();
    expect($user->tenant_id)->toBe($this->tenant->id);

    // Token is single-use.
    $this->getJson("/api/v1/auth/invitations/{$token}")->assertNotFound()->assertJsonPath('code', 'INVITATION_INVALID');
});

it('rejects expired or revoked invitations', function () {
    $token = inviteAndCaptureToken($this, ['email' => 'late@example.com', 'role_id' => $this->cashierRole->id, 'branch_ids' => [$this->branch->id]]);

    UserInvitation::withoutTenantScope()->where('email', 'late@example.com')->update(['expires_at' => now()->subMinute()]);

    $this->postJson("/api/v1/auth/invitations/{$token}/accept", [
        'name' => 'Late', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
    ])->assertNotFound()->assertJsonPath('code', 'INVITATION_INVALID');
});

it('does not invite an e-mail already registered anywhere on the platform', function () {
    ['owner' => $other] = $this->createTenant();

    $this->actingAsApi($this->owner)->postJson('/api/v1/invitations', [
        'email' => $other->email, 'role_id' => $this->cashierRole->id, 'branch_ids' => [$this->branch->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('never assigns the Owner role through an invitation', function () {
    $ownerRole = $this->asTenant($this->tenant, fn () => Role::forCurrentTenant()->where('key', 'owner')->firstOrFail());

    $this->actingAsApi($this->owner)->postJson('/api/v1/invitations', [
        'email' => 'coowner@example.com', 'role_id' => $ownerRole->id, 'branch_ids' => [$this->branch->id],
    ])->assertForbidden()->assertJsonPath('code', 'OWNER_ROLE_LOCKED');
});

it('stops a branch manager from inviting a more powerful role', function () {
    $manager = $this->createUser($this->tenant, 'branch_manager');
    $adminRole = $this->asTenant($this->tenant, fn () => Role::forCurrentTenant()->where('key', 'business_admin')->firstOrFail());

    $this->actingAsApi($manager)->postJson('/api/v1/invitations', [
        'email' => 'admin2@example.com', 'role_id' => $adminRole->id, 'branch_ids' => [$this->branch->id],
    ])->assertForbidden()->assertJsonPath('code', 'PERMISSION_ESCALATION');

    // ...but may invite a cashier to their own branch
    $this->actingAsApi($manager)->postJson('/api/v1/invitations', [
        'email' => 'cashier2@example.com', 'role_id' => $this->cashierRole->id, 'branch_ids' => [$this->branch->id],
    ])->assertCreated();
});

it('stops a branch manager from assigning branches they cannot access', function () {
    $other = $this->asTenant($this->tenant, fn () => Branch::query()->create(['code' => 'BR2', 'name' => 'Other']));
    $manager = $this->createUser($this->tenant, 'branch_manager');

    $this->actingAsApi($manager)->postJson('/api/v1/invitations', [
        'email' => 'x@example.com', 'role_id' => $this->cashierRole->id, 'branch_ids' => [$other->id],
    ])->assertForbidden()->assertJsonPath('code', 'BRANCH_FORBIDDEN');
});

it('enforces the plan user limit (pending invitations reserve a seat)', function () {
    $this->asTenant($this->tenant, function () {
        $plan = limitedPlan(); // max 2 users
        Subscription::query()->first()->update(['subscription_plan_id' => $plan->id]);
    });

    $payload = fn ($email) => ['email' => $email, 'role_id' => $this->cashierRole->id, 'branch_ids' => [$this->branch->id]];

    $this->actingAsApi($this->owner)->postJson('/api/v1/invitations', $payload('one@example.com'))->assertCreated();
    $this->actingAsApi($this->owner)->postJson('/api/v1/invitations', $payload('two@example.com'))
        ->assertStatus(402)
        ->assertJsonPath('code', 'PLAN_LIMIT_REACHED')
        ->assertJsonPath('context.limit', 2);
});

it('revokes and resends invitations', function () {
    $id = $this->actingAsApi($this->owner)->postJson('/api/v1/invitations', [
        'email' => 'r@example.com', 'role_id' => $this->cashierRole->id, 'branch_ids' => [$this->branch->id],
    ])->json('data.id');

    $this->postJson("/api/v1/invitations/{$id}/resend")->assertOk()->assertJsonPath('data.status', 'pending');
    $this->deleteJson("/api/v1/invitations/{$id}")->assertNoContent();
    $this->getJson('/api/v1/invitations')->assertOk()->assertJsonPath('data.0.status', 'revoked')
        ->assertJsonStructure(['meta' => ['pagination' => ['current_page', 'per_page', 'total', 'last_page']]]);
});

it('forbids cashiers from inviting users', function () {
    $cashier = $this->createUser($this->tenant, 'cashier');

    $this->actingAsApi($cashier)->postJson('/api/v1/invitations', [
        'email' => 'x@example.com', 'role_id' => $this->cashierRole->id, 'branch_ids' => [$this->branch->id],
    ])->assertForbidden();
});

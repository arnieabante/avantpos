<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\Auth\QueuedVerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

function registrationPayload(array $overrides = []): array
{
    return $overrides + [
        'business_name' => 'Kuya Ben Hardware',
        'business_type' => 'hardware',
        'subdomain' => 'kuyabenhardware',
        'vat_registered' => true,
        'owner_name' => 'Ben Santos',
        'email' => 'Ben@Example.com',
        'phone' => '0917 123 4567',
        'password' => 'Secret123',
        'password_confirmation' => 'Secret123',
        'accept_terms' => true,
    ];
}

it('registers a business with owner, main branch, roles, defaults and a trial', function () {
    Notification::fake();

    $response = $this->withHeader('Origin', 'https://app.avantpos.ph')
        ->postJson('/api/v1/auth/register', registrationPayload());

    $response->assertCreated()
        ->assertJsonPath('data.user.email', 'ben@example.com')
        ->assertJsonPath('data.user.is_owner', true)
        ->assertJsonPath('data.user.email_verified', false)
        ->assertJsonPath('data.tenant.name', 'Kuya Ben Hardware')
        ->assertJsonPath('data.tenant.subdomain', 'kuyabenhardware')
        ->assertJsonPath('data.tenant.status', 'trial')
        ->assertJsonPath('data.subscription.status', 'trialing')
        ->assertJsonPath('data.subscription.plan.code', 'professional')
        ->assertJsonPath('data.branches.0.code', 'MAIN');

    $tenant = Tenant::query()->where('subdomain', 'kuyabenhardware')->firstOrFail();
    $owner = User::withoutTenantScope()->where('email', 'ben@example.com')->firstOrFail();

    expect($owner->tenant_id)->toBe($tenant->id)
        ->and($owner->phone)->toBe('+639171234567')
        ->and($tenant->owner_user_id)->toBe($owner->id)
        ->and($tenant->vat_registered)->toBeTrue();

    $this->asTenant($tenant, function () use ($owner) {
        expect(Branch::query()->count())->toBe(1)
            ->and(Role::forCurrentTenant()->count())->toBe(6)
            ->and($owner->hasRole('Owner'))->toBeTrue()
            ->and($owner->can('roles.manage'))->toBeTrue()
            ->and(Subscription::query()->count())->toBe(1)
            ->and(DB::table('payment_methods')->where('tenant_id', $owner->tenant_id)->count())->toBe(5);
    });

    expect(AuditLog::query()->where('tenant_id', $tenant->id)->where('event', 'tenant.registered')->exists())->toBeTrue();
    Notification::assertSentTo($owner, QueuedVerifyEmail::class);

    // Logged in via session (SPA)
    $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.user.id', $owner->id);
});

it('rejects an e-mail that is already registered (one e-mail = one tenant)', function () {
    $this->createTenant(['email' => 'taken@example.com']);

    $this->postJson('/api/v1/auth/register', registrationPayload(['email' => 'TAKEN@example.com']))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'VALIDATION_ERROR')
        ->assertJsonValidationErrors('email');
});

it('rejects reserved, malformed and taken subdomains', function (string $subdomain) {
    $this->createTenant(['subdomain' => 'takenstore']);

    $this->postJson('/api/v1/auth/register', registrationPayload(['subdomain' => $subdomain]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('subdomain');
})->with(['app', 'admin', 'Bad_Name', 'ab', 'takenstore']);

it('requires a valid Philippine mobile number and accepted terms', function () {
    $this->postJson('/api/v1/auth/register', registrationPayload(['phone' => '12345', 'accept_terms' => false]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['phone', 'accept_terms']);
});

it('ignores a client-supplied tenant_id', function () {
    ['tenant' => $victim] = $this->createTenant();

    $this->postJson('/api/v1/auth/register', registrationPayload(['tenant_id' => $victim->id, 'subdomain' => null]))
        ->assertCreated();

    $user = User::withoutTenantScope()->where('email', 'ben@example.com')->firstOrFail();
    expect($user->tenant_id)->not->toBe($victim->id);
});

<?php

use App\Models\AuditLog;
use App\Models\PlatformAdmin;
use App\Models\Subscription;
use App\Services\Billing\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
| Platform: tenant management (due dates, overdue filter, manual enable) and
| the admin team (super admins manage AvantPOS staff accounts and roles).
*/

beforeEach(function () {
    Notification::fake();
    $make = fn (string $role, string $email) => PlatformAdmin::query()->create(['name' => ucfirst($role).' Admin', 'email' => $email, 'password' => 'AdminSecret1', 'role' => $role, 'is_active' => true])->fresh();
    $this->super = $make(PlatformAdmin::ROLE_SUPER_ADMIN, 'super@example.com');
    $this->billingAdmin = $make(PlatformAdmin::ROLE_BILLING, 'billing@example.com');
    $this->supportAdmin = $make(PlatformAdmin::ROLE_SUPPORT, 'support@example.com');
    $this->asAdmin = fn (PlatformAdmin $a) => $this->actingAs($a, 'platform')->withHeader('Origin', 'https://app.avantpos.ph');

    ['tenant' => $this->trial] = $this->createTenant(['business_name' => 'Trial Shop']);        // 14-day trial
    ['tenant' => $this->late, 'owner' => $this->lateOwner, 'branch' => $this->lateBranch] = $this->createTenant(['business_name' => 'Late Payer']);
    $sub = fn ($tenant) => Subscription::withoutTenantScope()->where('tenant_id', $tenant->id)->latest('id')->first();
    $this->sub = $sub;
    // Late Payer: trial ran out, grace over → expired (read-only).
    $this->travelTo(now()->addDays(15));
    app(BillingService::class)->runLifecycle();
    $this->travelTo(now()->addDays(8));
    app(BillingService::class)->runLifecycle();
    $this->travelBack();
    $sub($this->trial)->forceFill(['status' => 'trialing', 'trial_ends_at' => now()->addDays(3)])->save();
    $this->tenant = fn ($t) => $t->fresh();
});

it('lists businesses with plan, due date and overdue flag, filtered by overdue or due soon', function () {
    ($this->asAdmin)($this->supportAdmin);
    expect(($this->sub)($this->late)->status->value)->toBe('expired');

    $rows = collect($this->getJson('/api/admin/v1/tenants?per_page=100')->assertOk()->json('data'))->keyBy('name');
    expect($rows['Late Payer'])->toMatchArray(['overdue' => true, 'plan' => 'Professional', 'subscription_status' => 'expired'])
        ->and($rows['Trial Shop'])->toMatchArray(['overdue' => false])
        ->and(CarbonImmutable::parse($rows['Trial Shop']['due_at'])->toDateString())->toBe(now()->addDays(3)->toDateString());

    $names = fn (string $due) => collect($this->getJson("/api/admin/v1/tenants?due={$due}&per_page=100")->json('data'))->pluck('name')->all();
    expect($names('overdue'))->toContain('Late Payer')->not->toContain('Trial Shop')
        ->and($names('soon'))->toContain('Trial Shop')->not->toContain('Late Payer');
    $this->getJson('/api/admin/v1/tenants?due=whenever')->assertUnprocessable();
});

it('enables a read-only business by hand until a chosen date, recorded with the reason', function () {
    // Read-only before.
    $this->actingAsApi($this->lateOwner)->withHeader('X-Branch-Id', (string) $this->lateBranch->id)
        ->postJson('/api/v1/product-categories', ['name' => 'Oils'])->assertStatus(402);
    app('auth')->forgetGuards();

    $until = now()->addMonth()->toDateString();
    ($this->asAdmin)($this->billingAdmin);
    $this->putJson("/api/admin/v1/tenants/{$this->late->id}/subscription", ['plan' => 'professional', 'billing_cycle' => 'monthly', 'status' => 'active',
        'current_period_end' => now()->subDay()->toDateString(), 'note' => 'Paid cash at the office'])
        ->assertUnprocessable()->assertJsonValidationErrors('current_period_end');
    $this->putJson("/api/admin/v1/tenants/{$this->late->id}/subscription", ['plan' => 'free', 'billing_cycle' => 'monthly', 'status' => 'active',
        'current_period_end' => $until, 'note' => 'x'])->assertUnprocessable();

    $done = $this->putJson("/api/admin/v1/tenants/{$this->late->id}/subscription", ['plan' => 'professional', 'billing_cycle' => 'monthly', 'status' => 'active',
        'current_period_end' => $until, 'note' => 'Paid cash at the office'])->assertOk()->json('data');
    expect($done['status'])->toBe('active')->and($done['subscription'])->toMatchArray(['status' => 'active', 'overdue' => false])
        ->and(CarbonImmutable::parse($done['subscription']['due_at'])->toDateString())->toBe($until);
    expect(AuditLog::query()->where('event', 'platform.subscription_adjusted')->where('auditable_id', $this->late->id)->value('new_values'))->toMatchArray(['note' => 'Paid cash at the office']);

    // The business can work again, and the nightly cycle leaves it alone.
    app('auth')->forgetGuards();
    app(BillingService::class)->runLifecycle();
    expect(($this->sub)($this->late)->status->value)->toBe('active');
    $this->actingAsApi($this->lateOwner)->withHeader('X-Branch-Id', (string) $this->lateBranch->id)
        ->postJson('/api/v1/product-categories', ['name' => 'Oils'])->assertCreated();

    // Support staff may look but not enable.
    app('auth')->forgetGuards();
    ($this->asAdmin)($this->supportAdmin);
    $this->putJson("/api/admin/v1/tenants/{$this->late->id}/subscription", ['plan' => 'professional', 'billing_cycle' => 'monthly', 'status' => 'active',
        'current_period_end' => $until, 'note' => 'Nope'])->assertForbidden();
});

it('lets super admins add AvantPOS staff with a role and manage them', function () {
    ($this->asAdmin)($this->super);
    $created = $this->postJson('/api/admin/v1/admins', ['name' => 'Ana Billing', 'email' => 'Ana@AvantPOS.ph', 'role' => 'billing',
        'password' => 'Start-pass-123', 'password_confirmation' => 'Start-pass-123'])->assertCreated()->json('data');
    expect($created)->toMatchArray(['email' => 'ana@avantpos.ph', 'role' => 'billing', 'is_active' => true, 'two_factor' => false])
        ->and($created)->not->toHaveKey('password');
    $this->postJson('/api/admin/v1/admins', ['name' => 'Dup', 'email' => 'ana@avantpos.ph', 'role' => 'support',
        'password' => 'Start-pass-123', 'password_confirmation' => 'Start-pass-123'])->assertUnprocessable()->assertJsonValidationErrors('email');
    expect(collect($this->getJson('/api/admin/v1/admins')->assertOk()->json('data'))->pluck('email'))->toContain('ana@avantpos.ph', 'super@example.com');

    // Promote, reset password and two-factor, deactivate.
    $this->patchJson("/api/admin/v1/admins/{$created['id']}", ['role' => 'super_admin'])->assertOk()->assertJsonPath('data.role', 'super_admin');
    $this->postJson("/api/admin/v1/admins/{$created['id']}/password", ['password' => 'New-pass-456', 'password_confirmation' => 'New-pass-456'])->assertOk();
    expect(Hash::check('New-pass-456', PlatformAdmin::query()->find($created['id'])->password))->toBeTrue();
    PlatformAdmin::query()->whereKey($created['id'])->update(['two_factor_secret' => encrypt('X'), 'two_factor_confirmed_at' => now()]);
    $this->postJson("/api/admin/v1/admins/{$created['id']}/reset-two-factor")->assertOk()->assertJsonPath('data.two_factor', false);
    $this->patchJson("/api/admin/v1/admins/{$created['id']}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    expect(AuditLog::query()->where('event', 'like', 'platform.admin_%')->count())->toBeGreaterThanOrEqual(4);

    // Only super admins manage the team.
    foreach ([$this->billingAdmin, $this->supportAdmin] as $admin) {
        app('auth')->forgetGuards();
        ($this->asAdmin)($admin);
        $this->getJson('/api/admin/v1/admins')->assertForbidden();
        $this->postJson('/api/admin/v1/admins', ['name' => 'X', 'email' => 'x@avantpos.ph', 'role' => 'super_admin', 'password' => 'Start-pass-123', 'password_confirmation' => 'Start-pass-123'])->assertForbidden();
    }
});

it('keeps at least one active super admin and nobody demotes or deactivates themself', function () {
    ($this->asAdmin)($this->super);
    $this->patchJson("/api/admin/v1/admins/{$this->super->id}", ['role' => 'support'])->assertForbidden()->assertJsonPath('code', 'CANNOT_CHANGE_SELF');
    $this->patchJson("/api/admin/v1/admins/{$this->super->id}", ['is_active' => false])->assertForbidden();
    $this->patchJson("/api/admin/v1/admins/{$this->super->id}", ['name' => 'Chief'])->assertOk(); // own name is fine

    // Another super admin may demote the first.
    $other = PlatformAdmin::query()->create(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'AdminSecret1', 'role' => 'super_admin', 'is_active' => true]);
    app('auth')->forgetGuards();
    ($this->asAdmin)($other->fresh());
    $this->patchJson("/api/admin/v1/admins/{$this->super->id}", ['role' => 'billing'])->assertOk();
    // Now 'Other' is the only super admin left, and cannot demote or deactivate themself.
    $this->patchJson("/api/admin/v1/admins/{$other->id}", ['is_active' => false])->assertForbidden();
    expect(PlatformAdmin::query()->where('role', 'super_admin')->where('is_active', true)->pluck('email')->all())->toBe(['other@example.com']);
});

it('signs out a deactivated admin on their next request', function () {
    ($this->asAdmin)($this->supportAdmin);
    $this->getJson('/api/admin/v1/tenants')->assertOk();
    PlatformAdmin::query()->whereKey($this->supportAdmin->id)->update(['is_active' => false]);
    app('auth')->forgetGuards();
    ($this->asAdmin)($this->supportAdmin->fresh());
    $this->getJson('/api/admin/v1/tenants')->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
});

it('creates or recovers a platform admin from the command line', function () {
    $this->artisan('avantpos:admin', ['email' => 'Owner@AvantPOS.ph', '--name' => 'Owner', '--password' => 'Cli-pass-123'])->assertExitCode(0);
    $admin = PlatformAdmin::query()->where('email', 'owner@avantpos.ph')->firstOrFail();
    expect($admin->role)->toBe('super_admin')->and(Hash::check('Cli-pass-123', $admin->password))->toBeTrue();

    $admin->forceFill(['is_active' => false, 'two_factor_secret' => 'X', 'two_factor_confirmed_at' => now()])->save();
    $this->artisan('avantpos:admin', ['email' => 'owner@avantpos.ph', '--reset-2fa' => true])->expectsOutputToContain('Password (shown once):')->assertExitCode(0);
    $admin->refresh();
    expect($admin->is_active)->toBeTrue()->and($admin->two_factor_confirmed_at)->toBeNull()->and(Hash::check('Cli-pass-123', $admin->password))->toBeFalse();

    $this->artisan('avantpos:admin', ['email' => 'x@avantpos.ph', '--role' => 'owner'])->assertExitCode(1);
});

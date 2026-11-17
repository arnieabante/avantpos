<?php

use App\Models\AuditLog;
use App\Models\BillingPayment;
use App\Models\PlatformAdmin;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Support\Security\Totp;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    config(['avantpos.billing.gateway' => 'manual']);
    $make = fn (string $role, string $email) => PlatformAdmin::query()->create(['name' => ucfirst($role).' Admin', 'email' => $email, 'password' => 'AdminSecret1', 'role' => $role, 'is_active' => true])->fresh();
    $this->super = $make(PlatformAdmin::ROLE_SUPER_ADMIN, 'super@example.com');
    $this->supportAdmin = $make('support', 'support@example.com');
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'starter');
    $this->asAdmin = fn (PlatformAdmin $a) => $this->actingAs($a, 'platform')->withHeader('Origin', 'https://app.avantpos.ph');
    $this->asOwner = fn () => $this->actingAsApi($this->owner)->withHeader('X-Branch-Id', (string) $this->main->id);
});

it('shows platform metrics: businesses, MRR / ARR, expiring subscriptions', function () {
    // The tenant pays for Professional monthly (1,999) by bank transfer.
    ($this->asOwner)();
    $invoice = $this->postJson('/api/v1/billing/checkout', ['plan' => 'professional', 'billing_cycle' => 'monthly'])->json('data.invoice');
    $this->postJson("/api/v1/billing/invoices/{$invoice['id']}/manual-payment", ['reference' => 'GCASH-55', 'channel' => 'GCash', 'amount' => '1999', 'paid_on' => now()->toDateString()])->assertCreated();

    ($this->asAdmin)($this->super);
    $dash = $this->getJson('/api/admin/v1/dashboard')->assertOk()->json('data');
    expect($dash['payments']['pending_verification'])->toBe(1)->and($dash['tenants']['total'])->toBeGreaterThanOrEqual(1);

    // Verify the transfer → subscription active, counted in MRR.
    $payment = $this->getJson('/api/admin/v1/payments?status=pending')->assertOk()->json('data.0');
    expect($payment)->toMatchArray(['reference' => 'GCASH-55', 'amount' => '1999.00']);
    $this->postJson("/api/admin/v1/payments/{$payment['id']}/verify")->assertOk();
    expect(Subscription::withoutTenantScope()->where('tenant_id', $this->tenant->id)->value('status')->value)->toBe('active');

    $dash = $this->getJson('/api/admin/v1/dashboard')->json('data');
    expect($dash['mrr'])->toBe('1999.00')->and($dash['arr'])->toBe('23988.00')->and($dash['subscriptions']['paying'])->toBe(1);
    expect(BillingPayment::withoutTenantScope()->value('verified_by'))->toBe($this->super->id);
});

it('suspends and re-activates a business; suspended users cannot use the app', function () {
    ($this->asAdmin)($this->super);
    $this->getJson('/api/admin/v1/tenants?search='.urlencode($this->tenant->name))->assertOk()->assertJsonPath('data.0.id', $this->tenant->id);
    $this->postJson("/api/admin/v1/tenants/{$this->tenant->id}/suspend", ['reason' => 'Fraud report'])->assertOk()->assertJsonPath('data.status', 'suspended');

    ($this->asOwner)();
    $this->getJson('/api/v1/auth/me')->assertForbidden();

    ($this->asAdmin)($this->super);
    $this->postJson("/api/admin/v1/tenants/{$this->tenant->id}/activate")->assertOk();
    ($this->asOwner)();
    $this->getJson('/api/v1/auth/me')->assertOk();

    expect(AuditLog::query()->where('event', 'platform.tenant_suspended')->where('tenant_id', $this->tenant->id)->exists())->toBeTrue();
});

it('lets admins extend a trial and edit plans, within their role', function () {
    ($this->asAdmin)($this->super);
    $this->putJson("/api/admin/v1/tenants/{$this->tenant->id}/subscription", [
        'plan' => 'professional', 'billing_cycle' => 'monthly', 'status' => 'trialing', 'trial_ends_at' => now()->addDays(30)->toDateTimeString(), 'note' => 'Extended trial for a reseller',
    ])->assertOk()->assertJsonPath('data.subscription.plan.code', 'professional')->assertJsonPath('data.subscription.status', 'trialing');

    $plans = $this->getJson('/api/admin/v1/plans')->assertOk()->json('data.plans');
    $starter = collect($plans)->firstWhere('code', 'starter');
    $this->putJson("/api/admin/v1/plans/{$starter['id']}", array_merge($starter, ['monthly_price' => '1099.00', 'features' => ['loyalty' => true]]))
        ->assertOk()->assertJsonPath('data.monthly_price', '1099.00')->assertJsonPath('data.features.loyalty', true);
    $this->postJson('/api/admin/v1/plans', array_merge($starter, ['code' => 'starter', 'features' => []]))->assertUnprocessable(); // code taken

    // A support admin cannot change prices or suspend, but can see the activity log.
    ($this->asAdmin)($this->supportAdmin);
    $this->putJson("/api/admin/v1/plans/{$starter['id']}", $starter)->assertForbidden();
    $this->postJson("/api/admin/v1/tenants/{$this->tenant->id}/suspend", ['reason' => 'x x x'])->assertForbidden();
    $this->getJson('/api/admin/v1/activity?scope=platform')->assertOk();
});

it('runs support tickets between a business and AvantPOS', function () {
    ($this->asOwner)();
    $ticket = $this->postJson('/api/v1/support/tickets', ['subject' => 'Printer not printing', 'category' => 'technical', 'body' => 'The 58mm printer prints blank.'])
        ->assertCreated()->json('data');
    expect($ticket['number'])->toMatch('/^T-\d{6}$/')->and($ticket['messages'])->toHaveCount(1);

    ($this->asAdmin)($this->supportAdmin);
    $this->getJson('/api/admin/v1/tickets?status=open')->assertOk()->assertJsonPath('data.0.number', $ticket['number']);
    $this->postJson("/api/admin/v1/tickets/{$ticket['id']}/reply", ['body' => 'Please check the paper is thermal side up.'])
        ->assertOk()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.assigned_to.id', $this->supportAdmin->id);

    ($this->asOwner)();
    $seen = $this->getJson("/api/v1/support/tickets/{$ticket['id']}")->assertOk()->json('data');
    expect($seen['messages'])->toHaveCount(2)->and($seen['messages'][1]['from'])->toBe('support');
    $this->postJson("/api/v1/support/tickets/{$ticket['id']}/reply", ['body' => 'That fixed it, salamat!'])->assertOk()->assertJsonPath('data.status', 'open');

    // Other businesses never see it.
    ['owner' => $other] = $this->createTenant();
    $this->actingAsApi($other)->getJson("/api/v1/support/tickets/{$ticket['id']}")->assertNotFound();
});

it('protects admin sign-in with an authenticator code once two-factor is on', function () {
    ($this->asAdmin)($this->super);
    $secret = $this->postJson('/api/admin/v1/auth/2fa/setup')->assertOk()->json('data.secret');
    $this->postJson('/api/admin/v1/auth/2fa/confirm', ['code' => '000000'])->assertUnprocessable();
    $this->postJson('/api/admin/v1/auth/2fa/confirm', ['code' => Totp::code($secret)])->assertOk()->assertJsonPath('data.two_factor', true);

    $this->postJson('/api/admin/v1/auth/logout')->assertOk();
    $this->app['auth']->forgetGuards();
    $login = fn (array $extra = []) => $this->withHeader('Origin', 'https://app.avantpos.ph')->postJson('/api/admin/v1/auth/login', ['email' => 'super@example.com', 'password' => 'AdminSecret1'] + $extra);
    $login()->assertStatus(422)->assertJsonPath('code', 'TWO_FACTOR_REQUIRED');
    $login(['code' => '123456'])->assertUnprocessable()->assertJsonValidationErrors('code');
    $login(['code' => Totp::code($secret)])->assertOk()->assertJsonPath('data.two_factor', true);
});

it('keeps the admin API away from tenant users and guests', function () {
    $this->getJson('/api/admin/v1/dashboard')->assertUnauthorized();
    ($this->asOwner)();
    $this->getJson('/api/admin/v1/dashboard')->assertUnauthorized();
});

it('computes RFC 6238 codes', function () {
    // RFC 6238 test vector (SHA1, secret "12345678901234567890" = GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ): T=59 → 287082
    expect(Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59))->toBe('287082')
        ->and(Totp::code('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1111111109))->toBe('081804');
    expect(SubscriptionInvoice::query()->getModel()->getTable())->toBe('subscription_invoices');
});

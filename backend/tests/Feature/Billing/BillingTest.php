<?php

use App\Models\BillingPayment;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Notifications\SubscriptionNotice;
use App\Services\Billing\BillingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    config(['avantpos.billing.gateway' => 'manual', 'avantpos.billing.vat_registered' => true]);
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'starter');
    $this->as = fn ($user) => $this->actingAsApi($user)->withHeader('X-Branch-Id', (string) $this->main->id);
    ($this->as)($this->owner);
    $this->sub = fn () => Subscription::withoutTenantScope()->with('plan')->where('tenant_id', $this->tenant->id)->first();
    $this->billing = app(BillingService::class);
    // Pay an invoice the way an admin verifies a bank transfer.
    $this->pay = function (int $invoiceId) {
        $invoice = SubscriptionInvoice::withoutTenantScope()->findOrFail($invoiceId);
        $this->billing->markPaid($invoice, 'manual', 'BPI-123', 'BPI', (string) $invoice->total, []);
    };
});

it('shows the plan, usage, plans to choose from and how to pay', function () {
    $data = $this->getJson('/api/v1/billing')->assertOk()->json('data');
    expect($data['subscription']['plan']['code'])->toBe('starter')
        ->and(collect($data['plans'])->pluck('code')->all())->toContain('basic', 'professional', 'enterprise')->not->toContain('free')
        ->and($data['usage']['branches'])->toBe(1)
        ->and($data['payment']['gateway'])->toBe('manual');
});

it('buys a plan by bank transfer: invoice with VAT, verification, then a paid period', function () {
    $res = $this->postJson('/api/v1/billing/checkout', ['plan' => 'professional', 'billing_cycle' => 'monthly'])->assertCreated();
    $invoice = $res->json('data.invoice');
    expect($invoice)->toMatchArray(['kind' => 'new', 'status' => 'open', 'subtotal' => '1999.00', 'total' => '1999.00', 'vat_amount' => '214.18'])
        ->and($invoice['number'])->toMatch('/^AVP-\d{6}$/')
        ->and($res->json('data.checkout_url'))->toBeNull();

    $this->postJson("/api/v1/billing/invoices/{$invoice['id']}/manual-payment", ['reference' => 'BPI-777', 'channel' => 'BPI', 'amount' => '1999', 'paid_on' => now()->toDateString()])
        ->assertCreated()->assertJsonPath('data.payments.0.status', 'pending');
    $this->postJson("/api/v1/billing/invoices/{$invoice['id']}/manual-payment", ['reference' => 'again', 'channel' => 'BPI', 'amount' => '1999', 'paid_on' => now()->toDateString()])
        ->assertStatus(409);

    $pending = BillingPayment::withoutTenantScope()->where('subscription_invoice_id', $invoice['id'])->first();
    $this->billing->markPaid(SubscriptionInvoice::withoutTenantScope()->find($invoice['id']), 'manual', null, null, '1999.00', [], null, $pending);

    $sub = ($this->sub)();
    expect($sub->status->value)->toBe('active')
        ->and($sub->plan->code)->toBe('professional')
        ->and($sub->current_period_end->toDateString())->toBe(now()->addMonth()->toDateString())
        ->and($this->tenant->fresh()->status->value)->toBe('active')
        ->and($pending->fresh()->status)->toBe('paid');
});

it('credits unused time on an upgrade and schedules a downgrade for the period end', function () {
    $this->postJson('/api/v1/billing/checkout', ['plan' => 'professional', 'billing_cycle' => 'monthly']);
    ($this->pay)(SubscriptionInvoice::withoutTenantScope()->latest('id')->value('id'));

    // Halfway through the month, upgrade: about half of 1,999.00 is credited.
    $this->travelTo(now()->addDays(15));
    $up = $this->postJson('/api/v1/billing/checkout', ['plan' => 'business', 'billing_cycle' => 'monthly'])->assertCreated()->json('data.invoice');
    expect($up['kind'])->toBe('upgrade')
        ->and((float) $up['credit'])->toBeGreaterThan(900)->toBeLessThan(1100)
        ->and($up['total'])->toBe(number_format(3999 - (float) $up['credit'], 2, '.', ''));
    ($this->pay)($up['id']);
    expect(($this->sub)()->plan->code)->toBe('business');

    // A cheaper plan waits for the end of the paid period.
    $down = $this->postJson('/api/v1/billing/checkout', ['plan' => 'starter', 'billing_cycle' => 'monthly'])->assertCreated();
    expect($down->json('data.scheduled'))->toBeTrue()->and(($this->sub)()->plan->code)->toBe('business');

    // At the end the renewal is for the cheaper plan.
    $this->travelTo(now()->addMonth()->addDay());
    $this->billing->runLifecycle();
    $renewal = SubscriptionInvoice::withoutTenantScope()->where('status', 'open')->with('plan')->firstOrFail();
    expect($renewal->plan->code)->toBe('starter')->and($renewal->total)->toBe('999.00');
    ($this->pay)($renewal->id);
    expect(($this->sub)()->plan->code)->toBe('starter')->and(($this->sub)()->status->value)->toBe('active');
});

it('renews: reminder and invoice before the end, grace when unpaid, then read-only until paid', function () {
    $this->postJson('/api/v1/billing/checkout', ['plan' => 'starter', 'billing_cycle' => 'monthly']);
    ($this->pay)(SubscriptionInvoice::withoutTenantScope()->latest('id')->value('id'));
    $end = CarbonImmutable::parse(($this->sub)()->current_period_end);

    $this->travelTo($end->subDays(5));
    $counts = $this->billing->runLifecycle();
    expect($counts)->toMatchArray(['invoices' => 1, 'reminders' => 1]);
    $this->billing->runLifecycle(); // a second run sends nothing new
    Notification::assertSentToTimes($this->owner, SubscriptionNotice::class, 1);
    $renewal = SubscriptionInvoice::withoutTenantScope()->where('status', 'open')->firstOrFail();
    expect($renewal->kind)->toBe('renewal')->and($renewal->period_start->toDateString())->toBe($end->toDateString());

    // Not paid by the end: past due for the grace days (starter: 7), still usable.
    $this->travelTo($end->addHour());
    $this->billing->runLifecycle();
    expect(($this->sub)()->status->value)->toBe('past_due')->and($this->tenant->fresh()->status->value)->toBe('past_due');
    $this->postJson('/api/v1/product-categories', ['name' => 'Drinks'])->assertCreated();

    // Grace over: read-only, but billing still works.
    $this->travelTo($end->addDays(8));
    $this->billing->runLifecycle();
    expect(($this->sub)()->status->value)->toBe('expired');
    $this->postJson('/api/v1/product-categories', ['name' => 'Snacks'])->assertStatus(402)->assertJsonPath('code', 'SUBSCRIPTION_EXPIRED');
    $this->getJson('/api/v1/product-categories')->assertOk();
    $this->getJson('/api/v1/billing')->assertOk();

    ($this->pay)($renewal->id);
    expect(($this->sub)()->status->value)->toBe('active');
    $this->postJson('/api/v1/product-categories', ['name' => 'Snacks'])->assertCreated();
});

it('cancels at the period end: read-only afterwards, data kept, a plan can be chosen again', function () {
    $this->postJson('/api/v1/billing/checkout', ['plan' => 'starter', 'billing_cycle' => 'annual'])->assertCreated();
    ($this->pay)(SubscriptionInvoice::withoutTenantScope()->latest('id')->value('id'));
    expect(($this->sub)()->current_period_end->toDateString())->toBe(now()->addYear()->toDateString());

    $this->postJson('/api/v1/billing/cancel')->assertOk();
    expect(($this->sub)()->cancel_at_period_end)->toBeTrue();
    $this->postJson('/api/v1/billing/resume')->assertOk();
    $this->postJson('/api/v1/billing/cancel')->assertOk();

    $this->travelTo(now()->addYear()->addDay());
    expect($this->billing->runLifecycle())->toMatchArray(['ended' => 1, 'invoices' => 0]);
    expect(($this->sub)()->status->value)->toBe('expired');
    Notification::assertSentTo($this->owner, SubscriptionNotice::class, fn ($n) => $n->kind === 'ended');
    $this->postJson('/api/v1/product-categories', ['name' => 'Drinks'])->assertStatus(402);
    $this->getJson('/api/v1/product-categories')->assertOk();

    $again = $this->postJson('/api/v1/billing/checkout', ['plan' => 'starter', 'billing_cycle' => 'monthly'])->assertCreated()->json('data.invoice');
    ($this->pay)($again['id']);
    expect(($this->sub)()->status->value)->toBe('active');
    $this->postJson('/api/v1/product-categories', ['name' => 'Drinks'])->assertCreated();
});

it('pays through Xendit: checkout page, verified webhook applied once', function () {
    config(['avantpos.billing.gateway' => 'xendit', 'avantpos.billing.gateways.xendit.secret_key' => 'xnd_test', 'avantpos.billing.gateways.xendit.callback_token' => 'cb-token']);
    Http::fake(['api.xendit.co/v2/invoices' => Http::response(['id' => 'inv_123', 'invoice_url' => 'https://checkout.xendit.co/web/inv_123'])]);

    $res = $this->postJson('/api/v1/billing/checkout', ['plan' => 'professional', 'billing_cycle' => 'monthly'])->assertCreated();
    expect($res->json('data.checkout_url'))->toBe('https://checkout.xendit.co/web/inv_123');
    $number = $res->json('data.invoice.number');
    Http::assertSent(fn ($r) => $r['external_id'] === $number && $r['amount'] == 1999);

    $payload = ['id' => 'inv_123', 'external_id' => $number, 'status' => 'PAID', 'paid_amount' => 1999, 'payment_channel' => 'GCASH', 'payment_id' => 'pay_9'];
    $this->postJson('/api/v1/billing/webhooks/xendit', $payload, ['x-callback-token' => 'wrong'])->assertStatus(401);
    $this->postJson('/api/v1/billing/webhooks/xendit', $payload, ['x-callback-token' => 'cb-token'])->assertOk()->assertJsonPath('message', 'OK');
    $this->postJson('/api/v1/billing/webhooks/xendit', $payload, ['x-callback-token' => 'cb-token'])->assertOk()->assertJsonPath('message', 'Already processed.');

    expect(SubscriptionInvoice::withoutTenantScope()->where('number', $number)->value('status'))->toBe('paid')
        ->and(BillingPayment::withoutTenantScope()->where('status', 'paid')->count())->toBe(1)
        ->and(($this->sub)()->plan->code)->toBe('professional');
});

it('verifies PayMongo webhook signatures', function () {
    config(['avantpos.billing.gateway' => 'paymongo', 'avantpos.billing.gateways.paymongo.secret_key' => 'sk_test', 'avantpos.billing.gateways.paymongo.webhook_secret' => 'whsk']);
    Http::fake(['api.paymongo.com/v1/checkout_sessions' => Http::response(['data' => ['id' => 'cs_1', 'attributes' => ['checkout_url' => 'https://checkout.paymongo.com/cs_1']]])]);
    $number = $this->postJson('/api/v1/billing/checkout', ['plan' => 'basic', 'billing_cycle' => 'monthly'])->assertCreated()->json('data.invoice.number');
    Http::assertSent(fn ($r) => $r['data']['attributes']['line_items'][0]['amount'] === 49900);

    $body = json_encode(['data' => ['id' => 'evt_1', 'attributes' => ['type' => 'checkout_session.payment.paid', 'data' => ['id' => 'cs_1', 'attributes' => [
        'reference_number' => $number, 'payments' => [['id' => 'pay_1', 'attributes' => ['amount' => 49900, 'source' => ['type' => 'gcash']]]],
    ]]]]]);
    $t = (string) time();
    $send = fn (string $sig) => $this->call('POST', '/api/v1/billing/webhooks/paymongo', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => $sig, 'HTTP_ACCEPT' => 'application/json'], $body);

    $send("t={$t},te=bad,li=")->assertStatus(401);
    $send("t={$t},te=".hash_hmac('sha256', "{$t}.{$body}", 'whsk').',li=')->assertOk();
    expect(SubscriptionInvoice::withoutTenantScope()->where('number', $number)->value('status'))->toBe('paid');
});

it('lets only billing managers change the plan', function () {
    ($this->as)($this->createUser($this->tenant, 'cashier'));
    $this->getJson('/api/v1/billing')->assertForbidden();
    $this->postJson('/api/v1/billing/checkout', ['plan' => 'business', 'billing_cycle' => 'monthly'])->assertForbidden();
});

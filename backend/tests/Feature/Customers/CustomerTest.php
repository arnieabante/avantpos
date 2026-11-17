<?php

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'business');
    $this->actingAsApi($this->owner)->withHeader('X-Branch-Id', (string) $this->main->id);
    $this->methods = $this->asTenant($this->tenant, fn () => PaymentMethod::query()->pluck('id', 'type')->all());

    $this->nena = $this->postJson('/api/v1/customers', [
        'name' => 'Aling Nena', 'phone' => '0917 123 4567', 'allow_credit' => true, 'credit_limit' => '2000',
    ])->assertCreated()->json('data.id');
});

/* ------------------------------------------------------------------ Records */

it('creates customers with a normalised, unique phone and optional details', function () {
    $this->getJson("/api/v1/customers/{$this->nena}")->assertOk()
        ->assertJsonPath('data.phone', '+639171234567')
        ->assertJsonPath('data.balance', '0.00')
        ->assertJsonPath('data.available_credit', '2000.00');

    // Name only is enough.
    $this->postJson('/api/v1/customers', ['name' => 'Mang Tonyo'])->assertCreated()->assertJsonPath('data.phone', null);

    $this->postJson('/api/v1/customers', ['name' => 'Copy', 'phone' => '+63 917 123 4567'])
        ->assertUnprocessable()->assertJsonValidationErrors(['phone' => 'already uses this mobile number']);
});

it('encrypts SC/PWD ID numbers, masks them, and keeps them out of the audit log', function () {
    $id = $this->postJson('/api/v1/customers', ['name' => 'Lola Charing', 'sc_pwd_id_type' => 'senior', 'sc_pwd_id_no' => 'QC 2019 004 5678'])
        ->assertCreated()
        ->assertJsonPath('data.sc_pwd.id_no', 'QC20190045678')
        ->json('data.id');

    $raw = DB::table('customers')->where('id', $id)->value('sc_pwd_id_no');
    expect($raw)->not->toContain('5678');

    $this->postJson('/api/v1/customers', ['name' => 'Half', 'sc_pwd_id_type' => 'pwd'])
        ->assertUnprocessable()->assertJsonValidationErrors('sc_pwd_id_no');

    // Staff who can only view see the masked number.
    $accountant = $this->createUser($this->tenant, 'accountant');
    $this->actingAsApi($accountant);
    $this->getJson("/api/v1/customers/{$id}")->assertOk()
        ->assertJsonPath('data.sc_pwd.id_masked', '••••5678')
        ->assertJsonMissingPath('data.sc_pwd.id_no');

    $this->actingAsApi($this->owner);
    $this->putJson("/api/v1/customers/{$id}", ['name' => 'Lola Charing', 'sc_pwd_id_type' => 'senior', 'sc_pwd_id_no' => 'QC20199999999'])->assertOk();
    $logs = AuditLog::query()->where('auditable_id', $id)->where('event', 'like', 'customer.%')->get();
    expect($logs->pluck('new_values')->toJson().$logs->pluck('old_values')->toJson())->not->toContain('5678')->not->toContain('9999')
        ->and($logs->firstWhere('event', 'customer.updated')->meta)->toBe(['sc_pwd_id_changed' => true]);
});

it('only lets credit managers on credit plans change credit settings', function () {
    $cashier = $this->createUser($this->tenant, 'cashier');
    $this->actingAsApi($cashier);
    $this->postJson('/api/v1/customers', ['name' => 'Walk-in', 'allow_credit' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('allow_credit');
    $this->postJson('/api/v1/customers', ['name' => 'Walk-in'])->assertCreated()->assertJsonPath('data.allow_credit', false);

    $this->actingAsApi($this->owner);
    usePlan($this->tenant->id, 'starter');
    $this->putJson("/api/v1/customers/{$this->nena}", ['name' => 'Aling Nena', 'credit_limit' => '5000'])
        ->assertUnprocessable()->assertJsonValidationErrors('credit_limit');
    $this->postJson("/api/v1/customers/{$this->nena}/payments", ['amount' => '1', 'payment_method_id' => $this->methods['cash']])
        ->assertStatus(402);
});

it('deletes customers without history and deactivates the rest', function () {
    $spare = $this->postJson('/api/v1/customers', ['name' => 'Spare', 'phone' => '09181112222'])->json('data.id');
    $this->deleteJson("/api/v1/customers/{$spare}")->assertNoContent();
    // The phone can be used again right away.
    $this->postJson('/api/v1/customers', ['name' => 'New owner of the number', 'phone' => '09181112222'])->assertCreated();

    $this->postJson("/api/v1/customers/{$this->nena}/opening-balance", ['amount' => '100'])->assertOk();
    $this->deleteJson("/api/v1/customers/{$this->nena}")->assertStatus(409)->assertJsonPath('code', 'CUSTOMER_IN_USE');
    $this->postJson("/api/v1/customers/{$this->nena}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
    $this->getJson('/api/v1/customers/lookup?q=nena')->assertOk()->assertJsonCount(0, 'data');
});

it('finds customers by name, code or any way of typing the phone', function () {
    foreach (['nena', '0917 123 4567', '9171234567', '+639171234567'] as $q) {
        $this->getJson('/api/v1/customers/lookup?q='.urlencode($q))->assertOk()->assertJsonPath('data.0.id', $this->nena);
    }
    $this->getJson('/api/v1/customers?search=zzz')->assertOk()->assertJsonCount(0, 'data');
});

/* ------------------------------------------------------------------ Account (utang) */

it('tracks the balance owed through an append-only ledger', function () {
    $this->postJson("/api/v1/customers/{$this->nena}/opening-balance", ['amount' => '1250.50', 'notes' => 'From the notebook'])
        ->assertOk()->assertJsonPath('data.balance', '1250.50')->assertJsonPath('data.available_credit', '749.50');
    $this->postJson("/api/v1/customers/{$this->nena}/opening-balance", ['amount' => '1'])->assertStatus(409);

    // GCash needs a reference number; cash does not.
    $this->postJson("/api/v1/customers/{$this->nena}/payments", ['amount' => '500', 'payment_method_id' => $this->methods['gcash']])
        ->assertUnprocessable()->assertJsonValidationErrors('reference_no');
    $paid = $this->postJson("/api/v1/customers/{$this->nena}/payments", ['amount' => '500', 'payment_method_id' => $this->methods['gcash'], 'reference_no' => '1009 223 5512'])
        ->assertCreated();
    expect($paid->json('data.number'))->toStartWith('CP-')->and($paid->json('data.balance_after'))->toBe('750.50');

    // No overpayment.
    $this->postJson("/api/v1/customers/{$this->nena}/payments", ['amount' => '750.51', 'payment_method_id' => $this->methods['cash']])
        ->assertUnprocessable()->assertJsonValidationErrors(['amount' => '750.50']);

    $this->postJson("/api/v1/customers/{$this->nena}/balance-adjustments", ['amount' => '-0.50', 'notes' => 'Rounding forgiven'])
        ->assertOk()->assertJsonPath('data.balance', '750.00');
    $this->postJson("/api/v1/customers/{$this->nena}/balance-adjustments", ['amount' => '-800', 'notes' => 'Too much'])
        ->assertUnprocessable()->assertJsonValidationErrors('amount');

    $ledger = $this->getJson("/api/v1/customers/{$this->nena}/ledger")->assertOk()->json('data');
    expect(collect($ledger)->pluck('type')->all())->toBe(['adjustment', 'payment', 'opening_balance'])
        ->and(collect($ledger)->pluck('balance_after')->all())->toBe(['750.00', '750.50', '1250.50'])
        ->and($ledger[1]['payment_method'])->toBe('GCash')
        ->and($ledger[1]['branch']['id'])->toBe($this->main->id);

    $this->asTenant($this->tenant, function () {
        $entry = CustomerLedgerEntry::query()->firstOrFail();
        expect(fn () => $entry->forceFill(['amount' => '1'])->save())->toThrow(LogicException::class);
    });
});

it('lets accountants collect but not edit customers', function () {
    $this->postJson("/api/v1/customers/{$this->nena}/opening-balance", ['amount' => '300'])->assertOk();
    $accountant = $this->createUser($this->tenant, 'accountant');
    $this->actingAsApi($accountant);

    $this->postJson("/api/v1/customers/{$this->nena}/payments", ['amount' => '300', 'payment_method_id' => $this->methods['cash']])->assertCreated();
    $this->putJson("/api/v1/customers/{$this->nena}", ['name' => 'Changed'])->assertForbidden();
    $this->getJson('/api/v1/customers?with_balance=1')->assertOk()->assertJsonCount(0, 'data');
});

/* ------------------------------------------------------------------ Groups & loyalty */

it('manages customer groups and keeps groups in use', function () {
    $group = $this->postJson('/api/v1/customer-groups', ['name' => 'Resellers', 'price_tier' => 'wholesale', 'discount_percent' => '5'])
        ->assertCreated()->json('data.id');
    $this->postJson('/api/v1/customer-groups', ['name' => 'Resellers', 'price_tier' => 'retail', 'discount_percent' => '0'])
        ->assertUnprocessable()->assertJsonValidationErrors('name');

    $this->putJson("/api/v1/customers/{$this->nena}", ['name' => 'Aling Nena', 'customer_group_id' => $group])->assertOk()
        ->assertJsonPath('data.group.price_tier', 'wholesale');
    $this->getJson('/api/v1/customer-groups')->assertOk()->assertJsonPath('data.0.customers_count', 1);
    $this->deleteJson("/api/v1/customer-groups/{$group}")->assertStatus(409);
});

it('adjusts loyalty points with a reason and stores program settings', function () {
    $this->getJson('/api/v1/loyalty-settings')->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.spend_per_point', '100.00');
    $this->putJson('/api/v1/loyalty-settings', ['enabled' => true, 'spend_per_point' => '200', 'point_value' => '0.5', 'min_redeem_points' => 50])
        ->assertOk()->assertJsonPath('data.spend_per_point', '200.00')->assertJsonPath('data.point_value', '0.50');

    $this->postJson("/api/v1/customers/{$this->nena}/loyalty-adjustments", ['points' => '25', 'notes' => 'Welcome bonus'])
        ->assertOk()->assertJsonPath('data.loyalty_points', '25.00');
    $this->postJson("/api/v1/customers/{$this->nena}/loyalty-adjustments", ['points' => '-30', 'notes' => 'Too many'])
        ->assertUnprocessable()->assertJsonValidationErrors('points');
    $this->getJson("/api/v1/customers/{$this->nena}/loyalty")->assertOk()
        ->assertJsonPath('data.0.points', '25.00')->assertJsonPath('data.0.notes', 'Welcome bonus');

    usePlan($this->tenant->id, 'starter');
    $this->postJson("/api/v1/customers/{$this->nena}/loyalty-adjustments", ['points' => '1', 'notes' => 'x y z'])->assertStatus(402);
});

it('keeps other tenants out', function () {
    ['owner' => $otherOwner, 'tenant' => $other, 'branch' => $otherBranch] = $this->createTenant();
    usePlan($other->id, 'business');
    $this->actingAsApi($otherOwner)->withHeader('X-Branch-Id', (string) $otherBranch->id);

    $this->getJson("/api/v1/customers/{$this->nena}")->assertNotFound();
    $this->postJson("/api/v1/customers/{$this->nena}/payments", ['amount' => '1', 'payment_method_id' => $this->methods['cash']])->assertNotFound();
    $this->getJson('/api/v1/customers/lookup?q=nena')->assertOk()->assertJsonCount(0, 'data');
    // Same phone is fine in another business.
    $this->postJson('/api/v1/customers', ['name' => 'Nena too', 'phone' => '09171234567'])->assertCreated();

    expect(Customer::withoutTenantScope()->where('phone', '+639171234567')->count())->toBe(2);
});

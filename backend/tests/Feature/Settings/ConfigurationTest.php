<?php

use App\Models\AuditLog;
use App\Models\DiscountPreset;
use App\Models\PaymentMethod;
use App\Models\ReceiptTemplate;
use App\Models\TaxRate;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner] = $this->createTenant();
    $this->actingAsApi($this->owner);
    $this->find = fn (string $model, array $where) => $this->asTenant($this->tenant, fn () => $model::query()->where($where)->firstOrFail());
});

/* ------------------------------------------------------------------ Tax rates */

it('lists the provisioned PH tax rates as decimal strings', function () {
    $rates = collect($this->getJson('/api/v1/tax-rates')->assertOk()->json('data'))->keyBy('type');

    expect($rates['vat']['rate'])->toBe('12.0000')
        ->and($rates['percentage_tax']['rate'])->toBe('3.0000')
        ->and($rates['percentage_tax']['is_default'])->toBeTrue();
});

it('creates and updates tax rates with exact decimals', function () {
    $id = $this->postJson('/api/v1/tax-rates', ['name' => 'VAT (zero-rated)', 'type' => 'vat', 'rate' => '0'])
        ->assertCreated()->assertJsonPath('data.rate', '0.0000')->json('data.id');

    $this->putJson("/api/v1/tax-rates/{$id}", ['name' => 'Special', 'type' => 'vat', 'rate' => '12.5'])
        ->assertOk()->assertJsonPath('data.rate', '12.5000');

    $this->postJson('/api/v1/tax-rates', ['name' => 'Bad', 'type' => 'sales_tax', 'rate' => '101.12345'])
        ->assertUnprocessable()->assertJsonValidationErrors(['type', 'rate']);
});

it('keeps exactly one default tax rate and never deactivates it', function () {
    $vat = ($this->find)(TaxRate::class, ['type' => 'vat']);
    $pt = ($this->find)(TaxRate::class, ['type' => 'percentage_tax']);

    $this->putJson("/api/v1/tax-rates/{$pt->id}", ['name' => $pt->name, 'type' => 'percentage_tax', 'rate' => '3', 'is_active' => false])
        ->assertUnprocessable()->assertJsonPath('code', 'TAX_RATE_IS_DEFAULT');

    $this->postJson("/api/v1/tax-rates/{$vat->id}/make-default")->assertOk()->assertJsonPath('data.is_default', true);

    $defaults = $this->asTenant($this->tenant, fn () => TaxRate::query()->where('is_default', true)->pluck('id')->all());
    expect($defaults)->toBe([$vat->id]);
});

/* ------------------------------------------------------------ Payment methods */

it('lists the default payment methods in order', function () {
    expect(collect($this->getJson('/api/v1/payment-methods')->assertOk()->json('data'))->pluck('type')->all())
        ->toBe(['cash', 'gcash', 'maya', 'bank_transfer', 'card']);
});

it('creates payment methods; cash never requires a reference, digital does by default', function () {
    $this->postJson('/api/v1/payment-methods', ['type' => 'gcash', 'name' => 'GCash (Store 2)'])
        ->assertCreated()->assertJsonPath('data.requires_reference', true)->assertJsonPath('data.sort_order', 6);

    $this->postJson('/api/v1/payment-methods', ['type' => 'cash', 'name' => 'Cash (USD)', 'requires_reference' => true])
        ->assertCreated()->assertJsonPath('data.requires_reference', false);

    $this->postJson('/api/v1/payment-methods', ['type' => 'gcash', 'name' => 'GCash'])
        ->assertUnprocessable()->assertJsonValidationErrors(['name']);
});

it('cannot change the type or deactivate the last cash method', function () {
    $cash = ($this->find)(PaymentMethod::class, ['type' => 'cash']);

    $this->putJson("/api/v1/payment-methods/{$cash->id}", ['type' => 'gcash', 'name' => 'Cash'])
        ->assertUnprocessable()->assertJsonValidationErrors(['type']);

    $this->putJson("/api/v1/payment-methods/{$cash->id}", ['name' => 'Cash', 'is_active' => false])
        ->assertUnprocessable()->assertJsonPath('code', 'LAST_CASH_METHOD');

    $maya = ($this->find)(PaymentMethod::class, ['type' => 'maya']);
    $this->putJson("/api/v1/payment-methods/{$maya->id}", ['name' => 'Maya', 'is_active' => false, 'sort_order' => 9])
        ->assertOk()->assertJsonPath('data.is_active', false);
});

/* ----------------------------------------------------------- Discount presets */

it('creates discount presets with validated values', function () {
    $this->postJson('/api/v1/discount-presets', ['name' => 'Suki 5%', 'type' => 'percent', 'value' => '5', 'scope' => 'transaction', 'max_amount' => '200'])
        ->assertCreated()->assertJsonPath('data.value', '5.0000')->assertJsonPath('data.max_amount', '200.00')->assertJsonPath('data.statutory', null);

    $this->postJson('/api/v1/discount-presets', ['name' => 'Bad', 'type' => 'percent', 'value' => '150', 'scope' => 'line', 'statutory' => 'senior'])
        ->assertUnprocessable()->assertJsonValidationErrors(['value', 'statutory']);

    $this->postJson('/api/v1/discount-presets', ['name' => 'Bad fixed', 'type' => 'fixed', 'value' => '10.123', 'scope' => 'line'])
        ->assertUnprocessable()->assertJsonValidationErrors(['value']);
});

it('locks the rate of statutory SC/PWD discounts but allows renaming', function () {
    $senior = ($this->find)(DiscountPreset::class, ['statutory' => 'senior']);
    $base = ['type' => 'percent', 'value' => '20', 'scope' => 'line', 'is_active' => true];

    $this->putJson("/api/v1/discount-presets/{$senior->id}", ['name' => 'Senior Citizen', 'value' => '10'] + $base)
        ->assertUnprocessable()->assertJsonPath('code', 'STATUTORY_DISCOUNT_LOCKED');

    $this->putJson("/api/v1/discount-presets/{$senior->id}", ['name' => 'Senior Citizen', 'is_active' => false] + $base)
        ->assertUnprocessable()->assertJsonPath('code', 'STATUTORY_DISCOUNT_LOCKED');

    $this->putJson("/api/v1/discount-presets/{$senior->id}", ['name' => 'Senior Citizen (RA 9994)', 'requires_permission' => true] + $base)
        ->assertOk()->assertJsonPath('data.name', 'Senior Citizen (RA 9994)')->assertJsonPath('data.value', '20.0000');
});

/* ---------------------------------------------------------- Receipt templates */

it('lists receipt templates with the full layout and switches the default', function () {
    $templates = collect($this->getJson('/api/v1/receipt-templates')->assertOk()->json('data'));
    expect($templates->pluck('paper')->sort()->values()->all())->toBe(['58mm', '80mm', 'a4'])
        ->and($templates->first()['layout'])->toHaveKey('show_tin', true);

    $a4 = ($this->find)(ReceiptTemplate::class, ['paper' => 'a4']);
    $this->postJson("/api/v1/receipt-templates/{$a4->id}/make-default")->assertOk();

    $defaults = $this->asTenant($this->tenant, fn () => ReceiptTemplate::query()->where('is_default', true)->pluck('id')->all());
    expect($defaults)->toBe([$a4->id]);
});

it('updates layout options (ignoring unknown keys) and protects the default template', function () {
    $default = ($this->find)(ReceiptTemplate::class, ['is_default' => true]);

    $this->putJson("/api/v1/receipt-templates/{$default->id}", [
        'name' => 'Thermal 80mm', 'paper' => '80mm', 'footer_text' => 'Salamat po!',
        'layout' => ['show_item_sku' => true, 'show_tin' => false],
    ])->assertOk()->assertJsonPath('data.layout.show_item_sku', true)->assertJsonPath('data.layout.show_tin', false)
        ->assertJsonPath('data.footer_text', 'Salamat po!');

    $this->putJson("/api/v1/receipt-templates/{$default->id}", ['name' => 'X1', 'paper' => '80mm', 'layout' => ['evil' => true]])
        ->assertUnprocessable()->assertJsonValidationErrors(['layout']);

    $this->deleteJson("/api/v1/receipt-templates/{$default->id}")->assertUnprocessable()->assertJsonPath('code', 'RECEIPT_TEMPLATE_IS_DEFAULT');

    $id = $this->postJson('/api/v1/receipt-templates', ['name' => 'Promo', 'paper' => '58mm'])->assertCreated()->json('data.id');
    $this->deleteJson("/api/v1/receipt-templates/{$id}")->assertNoContent();
});

/* ---------------------------------------------------------- Access & audit */

it('audits configuration changes', function () {
    $maya = ($this->find)(PaymentMethod::class, ['type' => 'maya']);
    $this->putJson("/api/v1/payment-methods/{$maya->id}", ['name' => 'Maya Business'])->assertOk();

    $log = AuditLog::query()->where('event', 'payment_method.updated')->where('auditable_id', $maya->id)->firstOrFail();
    expect($log->old_values)->toBe(['name' => 'Maya'])->and($log->actor_id)->toBe($this->owner->id);
});

it('lets viewers read configuration but only the right permission change it', function () {
    $manager = $this->createUser($this->tenant, 'branch_manager'); // business.view, no settings.*

    $this->actingAsApi($manager);
    foreach (['tax-rates', 'payment-methods', 'discount-presets', 'receipt-templates'] as $resource) {
        $this->getJson("/api/v1/{$resource}")->assertOk();
    }
    $this->postJson('/api/v1/tax-rates', ['name' => 'X', 'type' => 'vat', 'rate' => '1'])->assertForbidden();
    $this->postJson('/api/v1/payment-methods', ['name' => 'X', 'type' => 'other'])->assertForbidden();
    $this->postJson('/api/v1/discount-presets', ['name' => 'X', 'type' => 'percent', 'value' => '1', 'scope' => 'line'])->assertForbidden();
    $this->postJson('/api/v1/receipt-templates', ['name' => 'X', 'paper' => '58mm'])->assertForbidden();

    $cashier = $this->createUser($this->tenant, 'cashier');
    $this->actingAsApi($cashier)->getJson('/api/v1/tax-rates')->assertForbidden();
});

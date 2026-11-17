<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\HeldCart;
use App\Models\Unit;
use Illuminate\Support\Str;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'business');
    $this->tenant->forceFill(['vat_registered' => true, 'registered_name' => 'Juan Dela Cruz', 'tin' => '123-456-789'])->save();
    $this->units = $this->asTenant($this->tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());

    $this->terminal = $this->createTerminal($this->tenant, $this->main);
    $this->terminal->forceFill(['min' => '2511150001', 'serial_no' => 'SN-001', 'ptu_no' => 'FP112025-001'])->save();
    $this->asTenant($this->tenant, fn () => CashRegister::query()->create(['branch_id' => $this->main->id, 'terminal_id' => $this->terminal->id, 'name' => 'Drawer', 'is_active' => true]));

    $this->as = fn ($user) => $this->actingAsApi($user)->withHeader('X-Branch-Id', (string) $this->main->id);
    ($this->as)($this->owner);

    // Coke ₱20 a piece, ₱220 a box of 12 (box barcode 4800000000123).
    $this->coke = createStockItem($this->units['pc'], ['name' => 'Coke Mismo', 'variants' => [[
        'selling_price' => '20', 'barcodes' => ['4800000000017'],
        'units' => [['unit_id' => $this->units['box'], 'conversion_factor' => '12', 'selling_price' => '220', 'barcodes' => ['4800000000123']]],
    ]]]);
    createStockItem($this->units['kg'], ['name' => 'Rice', 'tax_class' => 'vat_exempt', 'variants' => [['selling_price' => '52.50']]]);
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true, 'items' => [
        ['product_variant_id' => $this->coke, 'qty' => '48', 'unit_cost' => '14'],
    ]])->assertCreated();

    $this->cashier = $this->createUser($this->tenant, 'cashier');
    $this->supervisor = $this->createUser($this->tenant, 'branch_manager');
    $this->supervisor->setPin('4321');
    $this->owner->setPin('1111');

    $this->openShift = function ($user) {
        ($this->as)($user);
        $this->postJson('/api/v1/cash-sessions', ['client_uuid' => (string) Str::uuid(), 'terminal_id' => $this->terminal->id, 'opening_float' => '1000'])->assertCreated();
    };
});

it('loads the selling screen set-up with the receipt and BIR details', function () {
    ($this->openShift)($this->cashier);

    $setup = $this->getJson('/api/v1/pos/setup')->assertOk();

    expect($setup->json('data.business'))->toMatchArray(['registered_name' => 'Juan Dela Cruz', 'tin' => '123-456-789', 'vat_registered' => true])
        ->and($setup->json('data.terminal'))->toMatchArray(['id' => $this->terminal->id, 'min' => '2511150001', 'serial_no' => 'SN-001', 'ptu_no' => 'FP112025-001'])
        ->and($setup->json('data.receipt.paper'))->toBeIn(['58mm', '80mm', 'a4'])
        ->and(collect($setup->json('data.payment_methods'))->pluck('type'))->toContain('cash', 'credit')
        ->and($setup->json('data.features'))->toMatchArray(['credit_sales' => true]);

    // Approvers: users with a PIN at this branch and what they can approve (the cashier has no PIN).
    $approvers = collect($setup->json('data.approvers'))->keyBy('id');
    expect($approvers->keys()->all())->toEqualCanonicalizing([$this->owner->id, $this->supervisor->id])
        ->and($approvers[$this->supervisor->id]['permissions'])->toContain('pos.price_override', 'pos.discount_override_limit', 'pos.credit_sale');
});

it('searches, browses and scans items with branch prices, units and stock', function () {
    ($this->openShift)($this->cashier);

    $found = $this->getJson('/api/v1/pos/items?q=mismo')->assertOk()->json('data');
    expect($found)->toHaveCount(1)
        ->and($found[0])->toMatchArray(['product_variant_id' => $this->coke, 'name' => 'Coke Mismo', 'price' => '20.00', 'qty_on_hand' => '48.000', 'tax_class' => 'vatable'])
        ->and(collect($found[0]['units'])->pluck('price', 'abbreviation')->all())->toBe(['pc' => '20.00', 'box' => '220.00']);

    expect($this->getJson('/api/v1/pos/items')->assertOk()->json('data'))->toHaveCount(2);

    // A piece barcode selects the base unit; the box barcode selects the box.
    $this->getJson('/api/v1/pos/items/scan?code=4800000000017')->assertOk()
        ->assertJsonPath('data.product_variant_id', $this->coke)->assertJsonPath('data.scanned_unit_id', null);
    $box = $this->getJson('/api/v1/pos/items/scan?code=4800000000123')->assertOk();
    expect(collect($box->json('data.units'))->firstWhere('id', $box->json('data.scanned_unit_id'))['abbreviation'])->toBe('box');

    $this->getJson('/api/v1/pos/items/scan?code=0000')->assertNotFound()->assertJsonPath('code', 'PRODUCT_NOT_FOUND');
});

it('holds a cart and lets another cashier of the branch resume it once', function () {
    ($this->openShift)($this->cashier);
    $uuid = (string) Str::uuid();
    $body = ['client_uuid' => $uuid, 'label' => 'Lady in red', 'cart' => [
        'items' => [['product_variant_id' => $this->coke, 'qty' => '2']],
        'preview' => ['total' => '40.00'],
        'sc_pwd' => ['type' => 'senior', 'name' => 'Lola Remedios', 'id_no' => 'SC-12345'],
    ]];

    $held = $this->postJson('/api/v1/pos/held-carts', $body)->assertCreated();
    $this->postJson('/api/v1/pos/held-carts', $body)->assertOk()->assertJsonPath('data.id', $held->json('data.id')); // retried
    expect($held->json('data'))->toMatchArray(['label' => 'Lady in red', 'item_count' => 1, 'preview' => ['total' => '40.00']]);

    $this->postJson('/api/v1/pos/held-carts', ['client_uuid' => (string) Str::uuid(), 'cart' => ['items' => []]])
        ->assertUnprocessable()->assertJsonValidationErrors('cart.items');

    ($this->as)($this->supervisor);
    $this->getJson('/api/v1/pos/held-carts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.held_by.id', $this->cashier->id);
    $resumed = $this->postJson("/api/v1/pos/held-carts/{$held->json('data.id')}/resume")->assertOk();
    expect($resumed->json('data.cart.items.0.product_variant_id'))->toBe($this->coke)
        ->and($resumed->json('data.cart.sc_pwd'))->toEqualCanonicalizing(['type' => 'senior', 'name' => 'Lola Remedios']); // ID number never parked

    $this->postJson("/api/v1/pos/held-carts/{$held->json('data.id')}/resume")->assertNotFound();
    $this->getJson('/api/v1/pos/held-carts')->assertJsonCount(0, 'data');
});

it('needs a shift to hold, and records who discarded a held cart', function () {
    ($this->as)($this->cashier);
    $cart = fn () => ['client_uuid' => (string) Str::uuid(), 'cart' => ['items' => [['product_variant_id' => $this->coke, 'qty' => '1']]]];
    $this->postJson('/api/v1/pos/held-carts', $cart())->assertStatus(409)->assertJsonPath('code', 'NO_OPEN_SHIFT');

    ($this->openShift)($this->owner);
    $id = $this->postJson('/api/v1/pos/held-carts', $cart())->assertCreated()->json('data.id');

    // Another branch cannot see or discard it.
    $other = $this->asTenant($this->tenant, fn () => Branch::query()->create(['code' => 'B2', 'name' => 'Second', 'is_active' => true]));
    $this->withHeader('X-Branch-Id', (string) $other->id)->deleteJson("/api/v1/pos/held-carts/{$id}")->assertNotFound();

    ($this->as)($this->owner);
    $this->deleteJson("/api/v1/pos/held-carts/{$id}")->assertOk();
    $this->asTenant($this->tenant, function () {
        expect(HeldCart::query()->count())->toBe(0)
            ->and(AuditLog::query()->where('event', 'pos.held_cart_discarded')->exists())->toBeTrue();
    });
});

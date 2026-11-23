<?php

use App\Models\AuditLog;
use App\Models\InventoryBalance;
use App\Models\ProductVariant;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Unit;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'business');
    $this->units = $this->asTenant($this->tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    $this->actingAsApi($this->owner)->withHeader('X-Branch-Id', (string) $this->main->id);
    $this->item = createStockItem($this->units['pc']);
    $this->onHand = fn (int $variantId, ?int $branchId = null) => $this->asTenant($this->tenant, fn () => InventoryBalance::query()
        ->where('branch_id', $branchId ?? $this->main->id)->where('product_variant_id', $variantId)->value('qty_on_hand'));
});

it('posts opening stock at the given cost and refuses a second opening', function () {
    $response = $this->postJson('/api/v1/stock-adjustments', [
        'reason_code' => 'opening',
        'post' => true,
        'items' => [['product_variant_id' => $this->item, 'qty' => '24', 'unit_cost' => '9.75']],
    ])->assertCreated();

    expect($response->json('data.status'))->toBe('posted')
        ->and($response->json('data.number'))->toStartWith('ADJ-')
        ->and($response->json('data.items.0.qty'))->toBe('24.000')
        ->and($response->json('data.items.0.unit_cost'))->toBe('9.7500')
        ->and($response->json('data.value_change'))->toBe('234.00')
        ->and(($this->onHand)($this->item))->toBe('24.000');

    $this->asTenant($this->tenant, fn () => expect(StockMovement::query()->where('product_variant_id', $this->item)->value('type'))->toBe('opening'));
    expect(AuditLog::query()->where('event', 'stock_adjustment.posted')->exists())->toBeTrue();

    $this->postJson('/api/v1/stock-adjustments', [
        'reason_code' => 'opening', 'post' => true,
        'items' => [['product_variant_id' => $this->item, 'qty' => '5', 'unit_cost' => '9']],
    ])->assertUnprocessable()->assertJsonValidationErrors('items.0.product_variant_id');
});

it('updates the product cost from an opening stock cost, but not from a blank one', function () {
    $other = createStockItem($this->units['pc']);
    $cost = fn (int $id) => $this->asTenant($this->tenant, fn () => ProductVariant::query()->whereKey($id)->value('cost_price'));
    $before = $cost($other);

    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true, 'items' => [
        ['product_variant_id' => $this->item, 'qty' => '10', 'unit_cost' => '45.50'],
        ['product_variant_id' => $other, 'qty' => '10'],
    ]])->assertCreated();

    expect($cost($this->item))->toBe('45.5000')
        ->and($cost($other))->toBe($before);
    $this->asTenant($this->tenant, fn () => expect(AuditLog::query()->where('event', 'stock_adjustment.posted')->latest('id')->value('new_values'))
        ->toHaveKey('cost_changes'));
});

it('removes stock for out-reasons whatever sign is typed, at FIFO cost', function () {
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true,
        'items' => [['product_variant_id' => $this->item, 'qty' => '10', 'unit_cost' => '8']]])->assertCreated();

    $response = $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'damaged', 'notes' => 'Dropped a case', 'post' => true,
        'items' => [['product_variant_id' => $this->item, 'qty' => '3']]])->assertCreated();

    expect($response->json('data.items.0.qty'))->toBe('-3.000')
        ->and($response->json('data.items.0.unit_cost'))->toBe('8.0000')
        ->and($response->json('data.value_change'))->toBe('-24.00')
        ->and(($this->onHand)($this->item))->toBe('7.000');

    // Correction keeps the sign typed by the user.
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'correction', 'post' => true,
        'items' => [['product_variant_id' => $this->item, 'qty' => '-2']]])->assertCreated();
    expect(($this->onHand)($this->item))->toBe('5.000');
});

it('reports not enough stock on the line and posts nothing', function () {
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'lost', 'post' => true,
        'items' => [['product_variant_id' => $this->item, 'qty' => '1']]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items.0.qty' => 'Not enough stock']);

    $this->asTenant($this->tenant, function () {
        expect(StockMovement::query()->count())->toBe(0);
        // The whole request rolled back: not even the draft remains.
        expect(StockAdjustment::query()->count())->toBe(0);
    });
});

it('saves drafts, posts them once and cancels drafts only', function () {
    $id = $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'found',
        'items' => [['product_variant_id' => $this->item, 'qty' => '2']]])->assertCreated()->json('data.id');
    expect(($this->onHand)($this->item))->toBeNull();

    $this->putJson("/api/v1/stock-adjustments/{$id}", ['reason_code' => 'found',
        'items' => [['product_variant_id' => $this->item, 'qty' => '6', 'unit_cost' => '11']]])->assertOk()
        ->assertJsonPath('data.items.0.qty', '6.000');

    $this->postJson("/api/v1/stock-adjustments/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');
    expect(($this->onHand)($this->item))->toBe('6.000');

    $this->postJson("/api/v1/stock-adjustments/{$id}/post")->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_NOT_EDITABLE');
    $this->postJson("/api/v1/stock-adjustments/{$id}/cancel")->assertStatus(409);
    $this->putJson("/api/v1/stock-adjustments/{$id}", ['reason_code' => 'found',
        'items' => [['product_variant_id' => $this->item, 'qty' => '1']]])->assertStatus(409);

    $draft = $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'found',
        'items' => [['product_variant_id' => $this->item, 'qty' => '1']]])->json('data.id');
    $this->postJson("/api/v1/stock-adjustments/{$draft}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
});

it('hides costs from users without inventory valuation and uses the current cost for them', function () {
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true,
        'items' => [['product_variant_id' => $this->item, 'qty' => '10', 'unit_cost' => '7.5']]])->assertCreated();

    $manager = $this->createUser($this->tenant, 'branch_manager');
    $this->actingAsApi($manager);

    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'found', 'post' => true,
        'items' => [['product_variant_id' => $this->item, 'qty' => '1', 'unit_cost' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.unit_cost');

    $response = $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'found', 'post' => true,
        'items' => [['product_variant_id' => $this->item, 'qty' => '2']]])->assertCreated();

    expect($response->json('data.items.0'))->not->toHaveKey('unit_cost')
        ->and($response->json('data'))->not->toHaveKey('value_change');
    $this->getJson('/api/v1/inventory/stock')->assertOk()->assertJsonMissingPath('data.0.value');

    $balance = $this->asTenant($this->tenant, fn () => InventoryBalance::query()->where('product_variant_id', $this->item)->first());
    expect($balance->qty_on_hand)->toBe('12.000')->and($balance->total_cost)->toBe('90.00');
});

it('requires expiry dates for batch-tracked stock coming in, and refuses them elsewhere', function () {
    $medicine = createStockItem($this->units['pc'], ['track_expiry' => true]);

    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true,
        'items' => [['product_variant_id' => $medicine, 'qty' => '5', 'unit_cost' => '3']]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.expiry_date');

    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true,
        'items' => [['product_variant_id' => $this->item, 'qty' => '5', 'unit_cost' => '3', 'batch_no' => 'B1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.batch_no');

    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true,
        'items' => [
            ['product_variant_id' => $medicine, 'qty' => '5', 'unit_cost' => '3', 'batch_no' => 'A', 'expiry_date' => '2027-03-01'],
            ['product_variant_id' => $medicine, 'qty' => '5', 'unit_cost' => '3', 'batch_no' => 'B', 'expiry_date' => '2027-06-01'],
        ]])->assertCreated();

    // Writing off one specific batch only touches that batch.
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'expired', 'post' => true,
        'items' => [['product_variant_id' => $medicine, 'qty' => '5', 'batch_no' => 'B']]])->assertCreated();

    $layers = $this->getJson("/api/v1/inventory/stock/{$medicine}")->assertOk()->json('data.layers');
    expect($layers)->toHaveCount(1)->and($layers[0]['batch_no'])->toBe('A');
});

it('refuses services, untracked products and fractions of whole units', function () {
    $service = createStockItem($this->units['pc'], ['type' => 'service', 'track_inventory' => false]);

    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'found',
        'items' => [['product_variant_id' => $service, 'qty' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['items.0.product_variant_id' => 'does not track stock']);

    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'found',
        'items' => [['product_variant_id' => $this->item, 'qty' => '1.5']]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.qty');
});

it('needs inventory.adjust and keeps other tenants out', function () {
    $cashier = $this->createUser($this->tenant, 'cashier');
    $this->actingAsApi($cashier);
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'found',
        'items' => [['product_variant_id' => $this->item, 'qty' => '1']]])->assertForbidden();

    ['tenant' => $other, 'owner' => $otherOwner, 'branch' => $otherBranch] = $this->createTenant();
    $this->actingAsApi($otherOwner)->withHeader('X-Branch-Id', (string) $otherBranch->id);
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'found',
        'items' => [['product_variant_id' => $this->item, 'qty' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.product_variant_id');
});

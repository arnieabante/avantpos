<?php

use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\InventoryLayer;
use App\Models\Unit;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'business');
    $this->second = $this->asTenant($this->tenant, fn () => Branch::query()->create(['code' => 'B2', 'name' => 'Lapu-Lapu', 'is_active' => true]));
    $this->units = $this->asTenant($this->tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    $this->actingAsApi($this->owner)->withHeader('X-Branch-Id', (string) $this->main->id);

    $this->item = createStockItem($this->units['pc']);
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true, 'items' => [
        ['product_variant_id' => $this->item, 'qty' => '10', 'unit_cost' => '10'],
    ]])->assertCreated();
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'found', 'post' => true, 'items' => [
        ['product_variant_id' => $this->item, 'qty' => '10', 'unit_cost' => '13'],
    ]])->assertCreated();

    $this->balance = fn (int $branchId) => $this->asTenant($this->tenant, fn () => InventoryBalance::query()
        ->where('branch_id', $branchId)->where('product_variant_id', $this->item)->first());
});

it('moves stock with its FIFO cost and records what did not arrive', function () {
    $transfer = $this->postJson('/api/v1/stock-transfers', [
        'to_branch_id' => $this->second->id,
        'items' => [['product_variant_id' => $this->item, 'qty' => '12']],
    ])->assertCreated()->assertJsonPath('data.status', 'draft');
    $id = $transfer->json('data.id');
    expect(($this->balance)($this->main->id)->qty_on_hand)->toBe('20.000');

    $this->postJson("/api/v1/stock-transfers/{$id}/dispatch")->assertOk()
        ->assertJsonPath('data.status', 'in_transit')
        ->assertJsonPath('data.value', '126.00'); // 10 x 10 + 2 x 13
    expect(($this->balance)($this->main->id)->qty_on_hand)->toBe('8.000')
        ->and(($this->balance)($this->main->id)->total_cost)->toBe('104.00');

    // Received at the other branch: 11 of the 12 arrived.
    $itemId = $transfer->json('data.items.0.id');
    $this->withHeader('X-Branch-Id', (string) $this->second->id)
        ->postJson("/api/v1/stock-transfers/{$id}/receive", ['items' => [['id' => $itemId, 'qty_received' => '11']]])
        ->assertOk()
        ->assertJsonPath('data.status', 'received')
        ->assertJsonPath('data.items.0.qty_short', '1.000');

    $destination = ($this->balance)($this->second->id);
    // The oldest layers arrive first: 10 x 10 + 1 x 13.
    expect($destination->qty_on_hand)->toBe('11.000')->and($destination->total_cost)->toBe('113.00');

    $this->postJson("/api/v1/stock-transfers/{$id}/receive")->assertStatus(409);
});

it('carries batches and expiry dates to the receiving branch', function () {
    $medicine = createStockItem($this->units['pc'], ['track_expiry' => true]);
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true, 'items' => [
        ['product_variant_id' => $medicine, 'qty' => '4', 'unit_cost' => '5', 'batch_no' => 'LOT-A', 'expiry_date' => '2027-02-01'],
    ]])->assertCreated();

    $id = $this->postJson('/api/v1/stock-transfers', ['to_branch_id' => $this->second->id, 'dispatch' => true,
        'items' => [['product_variant_id' => $medicine, 'qty' => '3']]])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/stock-transfers/{$id}/receive")->assertOk();

    $layer = $this->asTenant($this->tenant, fn () => InventoryLayer::query()
        ->where('branch_id', $this->second->id)->where('product_variant_id', $medicine)->firstOrFail());
    expect($layer->batch_no)->toBe('LOT-A')
        ->and($layer->expiry_date->toDateString())->toBe('2027-02-01')
        ->and($layer->unit_cost)->toBe('5.0000')
        ->and($layer->qty_remaining)->toBe('3.000');
});

it('returns the stock to the sender when an in-transit transfer is cancelled', function () {
    $id = $this->postJson('/api/v1/stock-transfers', ['to_branch_id' => $this->second->id, 'dispatch' => true,
        'items' => [['product_variant_id' => $this->item, 'qty' => '15']]])->assertCreated()->json('data.id');
    expect(($this->balance)($this->main->id)->qty_on_hand)->toBe('5.000');

    $this->postJson("/api/v1/stock-transfers/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');

    $back = ($this->balance)($this->main->id);
    expect($back->qty_on_hand)->toBe('20.000')->and($back->total_cost)->toBe('230.00');
    expect(($this->balance)($this->second->id))->toBeNull();
});

it('offers every other active branch as a destination', function () {
    $this->asTenant($this->tenant, fn () => Branch::query()->create(['code' => 'B3', 'name' => 'Closed', 'is_active' => false]));

    // A manager of the main branch only may still send to Lapu-Lapu.
    $this->actingAsApi($this->createUser($this->tenant, 'branch_manager', [$this->main->id]));
    $this->getJson('/api/v1/inventory/destinations')->assertOk()
        ->assertExactJson(['data' => [['id' => $this->second->id, 'name' => 'Lapu-Lapu']]]);
});

it('validates the destination, quantities and stock on hand', function () {
    $this->postJson('/api/v1/stock-transfers', ['to_branch_id' => $this->main->id,
        'items' => [['product_variant_id' => $this->item, 'qty' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('to_branch_id');

    $this->postJson('/api/v1/stock-transfers', ['to_branch_id' => $this->second->id, 'items' => [
        ['product_variant_id' => $this->item, 'qty' => '1'],
        ['product_variant_id' => $this->item, 'qty' => '2'],
    ]])->assertUnprocessable()->assertJsonValidationErrors('items.1.product_variant_id');

    $this->postJson('/api/v1/stock-transfers', ['to_branch_id' => $this->second->id, 'dispatch' => true,
        'items' => [['product_variant_id' => $this->item, 'qty' => '21']]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.qty');

    $id = $this->postJson('/api/v1/stock-transfers', ['to_branch_id' => $this->second->id, 'dispatch' => true,
        'items' => [['product_variant_id' => $this->item, 'qty' => '2']]])->json('data.id');
    $itemId = $this->getJson("/api/v1/stock-transfers/{$id}")->json('data.items.0.id');
    $this->postJson("/api/v1/stock-transfers/{$id}/receive", ['items' => [['id' => $itemId, 'qty_received' => '3']]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.qty_received');
});

it('lets only the receiving branch receive, and needs the stock transfers feature', function () {
    $id = $this->postJson('/api/v1/stock-transfers', ['to_branch_id' => $this->second->id, 'dispatch' => true,
        'items' => [['product_variant_id' => $this->item, 'qty' => '2']]])->json('data.id');

    $senderManager = $this->createUser($this->tenant, 'branch_manager', [$this->main->id]);
    $this->actingAsApi($senderManager);
    $this->getJson("/api/v1/stock-transfers/{$id}")->assertOk()->assertJsonPath('data.can.receive', false);
    $this->postJson("/api/v1/stock-transfers/{$id}/receive")->assertForbidden();

    $receiverManager = $this->createUser($this->tenant, 'branch_manager', [$this->second->id]);
    $this->actingAsApi($receiverManager)->withHeader('X-Branch-Id', (string) $this->second->id);
    $this->getJson('/api/v1/stock-transfers?direction=incoming')->assertOk()->assertJsonPath('data.0.id', $id);
    $this->postJson("/api/v1/stock-transfers/{$id}/receive")->assertOk();

    usePlan($this->tenant->id, 'starter');
    $this->actingAsApi($this->owner)->withHeader('X-Branch-Id', (string) $this->main->id);
    $this->postJson('/api/v1/stock-transfers', ['to_branch_id' => $this->second->id,
        'items' => [['product_variant_id' => $this->item, 'qty' => '1']]])
        ->assertStatus(402)->assertJsonPath('code', 'FEATURE_NOT_AVAILABLE');
});

it('keeps drafts private to the sending branch', function () {
    $id = $this->postJson('/api/v1/stock-transfers', ['to_branch_id' => $this->second->id,
        'items' => [['product_variant_id' => $this->item, 'qty' => '1']]])->json('data.id');

    $receiverManager = $this->createUser($this->tenant, 'branch_manager', [$this->second->id]);
    $this->actingAsApi($receiverManager)->withHeader('X-Branch-Id', (string) $this->second->id);
    $this->getJson("/api/v1/stock-transfers/{$id}")->assertNotFound();
    $this->getJson('/api/v1/stock-transfers')->assertOk()->assertJsonCount(0, 'data');
});

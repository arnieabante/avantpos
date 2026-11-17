<?php

use App\Models\Branch;
use App\Models\Unit;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'business');
    $this->units = $this->asTenant($this->tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    $this->actingAsApi($this->owner)->withHeader('X-Branch-Id', (string) $this->main->id);

    $this->full = createStockItem($this->units['pc'], ['name' => 'Alaska Evap', 'variants' => [['sku' => 'ALA-1', 'reorder_level' => '5', 'barcodes' => ['4800092110013']]]]);
    $this->low = createStockItem($this->units['pc'], ['name' => 'Bear Brand', 'variants' => [['reorder_level' => '5']]]);
    $this->none = createStockItem($this->units['pc'], ['name' => 'Coke', 'variants' => [['reorder_level' => '0']]]);
    $this->expiring = createStockItem($this->units['pc'], ['name' => 'Dextrose', 'track_expiry' => true]);
    createStockItem($this->units['pc'], ['name' => 'Load service', 'type' => 'service', 'track_inventory' => false]);

    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true, 'items' => [
        ['product_variant_id' => $this->full, 'qty' => '50', 'unit_cost' => '30'],
        ['product_variant_id' => $this->low, 'qty' => '3', 'unit_cost' => '20'],
        ['product_variant_id' => $this->expiring, 'qty' => '10', 'unit_cost' => '2', 'batch_no' => 'X1', 'expiry_date' => today()->addDays(10)->toDateString()],
    ]])->assertCreated();
});

it('lists stock levels of tracked items with status filters and search', function () {
    $rows = $this->getJson('/api/v1/inventory/stock')->assertOk()->json('data');
    expect(collect($rows)->pluck('variant.product_name')->all())->toBe(['Alaska Evap', 'Bear Brand', 'Coke', 'Dextrose'])
        ->and(collect($rows)->pluck('stock_status', 'variant.product_name')->all())
        ->toBe(['Alaska Evap' => 'in_stock', 'Bear Brand' => 'low', 'Coke' => 'out', 'Dextrose' => 'in_stock'])
        ->and($rows[0]['value'])->toBe('1500.00')
        ->and($rows[0]['average_cost'])->toBe('30.0000');

    expect($this->getJson('/api/v1/inventory/stock?status=low')->json('data.*.variant.product_name'))->toBe(['Bear Brand']);
    expect($this->getJson('/api/v1/inventory/stock?status=out')->json('data.*.variant.product_name'))->toBe(['Coke']);
    expect($this->getJson('/api/v1/inventory/stock?search=4800092110013')->json('data.*.variant.product_name'))->toBe(['Alaska Evap']);
    expect($this->getJson('/api/v1/inventory/stock?search=ala-')->json('data.*.variant.product_name'))->toBe(['Alaska Evap']);
});

it('counts alerts and lists expiring batches', function () {
    $this->getJson('/api/v1/inventory/alerts')->assertOk()
        ->assertJsonPath('data.low_stock', 1)
        ->assertJsonPath('data.out_of_stock', 1)
        ->assertJsonPath('data.expiring', 1)
        ->assertJsonPath('data.expired', 0);

    $this->getJson('/api/v1/inventory/expiring')->assertOk()
        ->assertJsonPath('data.0.batch_no', 'X1')
        ->assertJsonPath('data.0.expired', false)
        ->assertJsonPath('data.0.value', '20.00');
});

it('shows the ledger with document numbers, and the item detail per branch', function () {
    $movements = $this->getJson("/api/v1/inventory/movements?product_variant_id={$this->full}")->assertOk()->json('data');
    expect($movements)->toHaveCount(1)
        ->and($movements[0]['type'])->toBe('opening')
        ->and($movements[0]['reference']['number'])->toStartWith('ADJ-')
        ->and($movements[0]['total_cost'])->toBe('1500.00');

    $this->getJson('/api/v1/inventory/movements?type=sale')->assertOk()->assertJsonCount(0, 'data');

    $detail = $this->getJson("/api/v1/inventory/stock/{$this->full}")->assertOk();
    expect($detail->json('data.branches.0.qty_on_hand'))->toBe('50.000')
        ->and($detail->json('data.layers.0.unit_cost'))->toBe('30.0000');
});

it('values inventory per branch and per category for users with valuation access', function () {
    $second = $this->asTenant($this->tenant, fn () => Branch::query()->create(['code' => 'B2', 'name' => 'Second', 'is_active' => true]));

    $response = $this->getJson('/api/v1/inventory/valuation')->assertOk();
    expect($response->json('data.total'))->toBe('1580.00')
        ->and(collect($response->json('data.branches'))->pluck('value', 'branch.id')->all())
        ->toBe([$this->main->id => '1580.00', $second->id => '0.00'])
        ->and($response->json('data.categories.0.value'))->toBe('1580.00');

    $manager = $this->createUser($this->tenant, 'branch_manager');
    $this->actingAsApi($manager);
    $this->getJson('/api/v1/inventory/valuation')->assertForbidden();
});

it('finds items for documents by name, SKU or barcode', function () {
    $hits = $this->getJson('/api/v1/inventory/picker?q=4800092110013')->assertOk()->json('data');
    expect($hits)->toHaveCount(1)
        ->and($hits[0]['qty_on_hand'])->toBe('50.000')
        ->and($hits[0]['current_cost'])->toBe('30.0000');

    // Services never appear.
    $this->getJson('/api/v1/inventory/picker?q=Load')->assertOk()->assertJsonCount(0, 'data');
});

it('requires a branch, inventory.view, and keeps other tenants out', function () {
    $this->withHeaders(['X-Branch-Id' => ''])->getJson('/api/v1/inventory/stock')
        ->assertUnprocessable()->assertJsonPath('code', 'BRANCH_REQUIRED');

    $cashier = $this->createUser($this->tenant, 'cashier');
    $this->actingAsApi($cashier)->withHeader('X-Branch-Id', (string) $this->main->id);
    $this->getJson('/api/v1/inventory/stock')->assertForbidden();

    ['owner' => $otherOwner, 'branch' => $otherBranch] = $this->createTenant();
    $this->actingAsApi($otherOwner)->withHeader('X-Branch-Id', (string) $otherBranch->id);
    $this->getJson("/api/v1/inventory/stock/{$this->full}")->assertNotFound();
    $this->getJson('/api/v1/inventory/stock')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/inventory/movements?product_variant_id={$this->full}")->assertUnprocessable();
});

<?php

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\GoodsReceipt;
use App\Models\InventoryBalance;
use App\Models\InventoryLayer;
use App\Models\PaymentMethod;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Services\Inventory\InventoryVerifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Inventory consistency: after a realistic mix of every stock operation the
| ledger, running balances, FIFO cost layers and stock value must agree
| (InventoryVerifier). A second test proves each check catches corruption.
*/

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'business');
    $this->second = $this->asTenant($this->tenant, fn () => Branch::query()->create(['code' => 'B2', 'name' => 'Second', 'is_active' => true]));
    $units = $this->asTenant($this->tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    $this->methods = $this->asTenant($this->tenant, fn () => PaymentMethod::query()->pluck('id', 'type')->all());
    $this->terminal = $this->createTerminal($this->tenant, $this->main);
    $this->asTenant($this->tenant, fn () => CashRegister::query()->create(['branch_id' => $this->main->id, 'terminal_id' => $this->terminal->id, 'name' => 'Drawer', 'is_active' => true]));

    $this->at = fn (Branch $branch) => $this->actingAsApi($this->owner)->withHeader('X-Branch-Id', (string) $branch->id);
    ($this->at)($this->main);

    $this->oil = createStockItem($units['pc'], ['name' => 'Engine Oil 1L', 'variants' => [['selling_price' => '380', 'cost_price' => '290']]]);
    $this->plug = createStockItem($units['pc'], ['name' => 'Spark Plug', 'variants' => [['selling_price' => '180', 'cost_price' => '125']]]);
    $this->bulb = createStockItem($units['pc'], ['name' => 'Signal Bulb', 'allow_negative_stock' => true, 'variants' => [['selling_price' => '35', 'cost_price' => '18']]]);
    $this->supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Parts Supplier', 'vat_registered' => false, 'payment_terms_days' => 30])->assertCreated()->json('data.id');

    $this->sell = fn (array $lines) => $this->postJson('/api/v1/pos/sales', [
        'client_uuid' => (string) Str::uuid(),
        'items' => array_map(fn ($l) => ['product_variant_id' => $l[0], 'qty' => $l[1]], $lines),
        'payments' => [['payment_method_id' => $this->methods['cash'], 'amount' => '100000']],
    ])->assertCreated()->json('data');
    $this->verify = fn () => app(InventoryVerifier::class)->verify($this->tenant->id);
});

it('stays consistent through receipts, sales, refunds, voids, transfers, adjustments, counts and reversals', function () {
    // Opening stock at one cost, then two deliveries at different costs (three FIFO layers).
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true, 'items' => [
        ['product_variant_id' => $this->oil, 'qty' => '10', 'unit_cost' => '280'],
        ['product_variant_id' => $this->plug, 'qty' => '50', 'unit_cost' => '120'],
    ]])->assertCreated();
    $this->postJson('/api/v1/goods-receipts', ['supplier_id' => $this->supplier, 'items' => [
        ['product_variant_id' => $this->oil, 'qty' => '12', 'unit_cost' => '290'],
        ['product_variant_id' => $this->plug, 'qty' => '20', 'unit_cost' => '125.50'],
    ]])->assertCreated();
    $late = $this->postJson('/api/v1/goods-receipts', ['supplier_id' => $this->supplier, 'items' => [
        ['product_variant_id' => $this->oil, 'qty' => '6', 'unit_cost' => '301.75'],
    ]])->assertCreated()->json('data.id');

    // A selling day: sales across layers, a void, a refund restocked and one written off.
    $this->postJson('/api/v1/cash-sessions', ['client_uuid' => (string) Str::uuid(), 'terminal_id' => $this->terminal->id, 'opening_float' => '2000'])->assertCreated();
    ($this->sell)([[$this->oil, '15'], [$this->plug, '7']]);           // oil crosses from layer 1 into layer 2
    $voided = ($this->sell)([[$this->oil, '2']]);
    $refunded = ($this->sell)([[$this->oil, '3'], [$this->plug, '4']]);
    ($this->sell)([[$this->bulb, '5']]);                                // oversold: negative stock allowed for this item
    $this->postJson("/api/v1/sales/{$voided['id']}/void", ['reason' => 'Wrong item'])->assertOk();
    $this->postJson("/api/v1/sales/{$refunded['id']}/refunds", ['client_uuid' => (string) Str::uuid(), 'reason' => 'Returned', 'items' => [
        ['sale_item_id' => $refunded['items'][0]['id'], 'qty' => '2', 'restock' => 'sellable'],
        ['sale_item_id' => $refunded['items'][1]['id'], 'qty' => '1', 'restock' => 'damaged'],
    ]])->assertCreated();

    // The oversold bulbs are settled by the next delivery.
    $this->postJson('/api/v1/goods-receipts', ['supplier_id' => $this->supplier, 'items' => [
        ['product_variant_id' => $this->bulb, 'qty' => '20', 'unit_cost' => '18'],
    ]])->assertCreated();

    // Transfer to the second branch and receive it there; one cancelled transfer.
    $transfer = $this->postJson('/api/v1/stock-transfers', ['to_branch_id' => $this->second->id, 'dispatch' => true, 'items' => [
        ['product_variant_id' => $this->oil, 'qty' => '4'], ['product_variant_id' => $this->plug, 'qty' => '10'],
    ]])->assertCreated()->json('data.id');
    $cancelled = $this->postJson('/api/v1/stock-transfers', ['to_branch_id' => $this->second->id, 'dispatch' => true, 'items' => [
        ['product_variant_id' => $this->plug, 'qty' => '3'],
    ]])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/stock-transfers/{$cancelled}/cancel")->assertOk();
    ($this->at)($this->second);
    $this->postJson("/api/v1/stock-transfers/{$transfer}/receive")->assertOk();

    // Damaged stock written off at the second branch.
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'damaged', 'post' => true, 'items' => [
        ['product_variant_id' => $this->plug, 'qty' => '2'],
    ]])->assertCreated();

    // Month-end count at the main branch with a shortage and an overage.
    ($this->at)($this->main);
    $count = $this->postJson('/api/v1/stock-counts', ['notes' => 'Month-end'])->assertCreated()->json('data.id');
    $items = collect($this->getJson("/api/v1/stock-counts/{$count}/items")->json('data'))->mapWithKeys(fn ($row) => [$row['variant']['id'] => $row['id']]);
    $this->putJson("/api/v1/stock-counts/{$count}/entries", ['entries' => [$items[$this->oil] => '5', $items[$this->plug] => '62']])->assertOk();
    $this->postJson("/api/v1/stock-counts/{$count}/finalize")->assertOk();

    // A delivery entered by mistake is reversed (only if its stock is still there).
    $this->postJson('/api/v1/goods-receipts', ['supplier_id' => $this->supplier, 'items' => [
        ['product_variant_id' => $this->plug, 'qty' => '5', 'unit_cost' => '130'],
    ]])->assertCreated();
    $mistake = $this->asTenant($this->tenant, fn () => GoodsReceipt::query()->latest('id')->value('id'));
    $this->postJson("/api/v1/goods-receipts/{$mistake}/reverse", ['reason' => 'Entered twice'])->assertOk();

    // Everything agrees, for this business and across the whole database.
    expect(($this->verify)())->toBe([])
        ->and(app(InventoryVerifier::class)->verify())->toBe([]);
    $this->artisan('inventory:verify', ['--tenant' => $this->tenant->id])->assertExitCode(0);

    // Spot-check the numbers the verifier relies on.
    $this->asTenant($this->tenant, function () {
        $oil = InventoryBalance::query()->where('branch_id', $this->main->id)->where('product_variant_id', $this->oil)->firstOrFail();
        expect($oil->qty_on_hand)->toBe('5.000'); // counted
        $bulb = InventoryBalance::query()->where('branch_id', $this->main->id)->where('product_variant_id', $this->bulb)->firstOrFail();
        expect($bulb->qty_on_hand)->toBe('15.000')->and($bulb->total_cost)->toBe('270.00'); // 20 received − 5 oversold
        $second = InventoryBalance::query()->where('branch_id', $this->second->id)->where('product_variant_id', $this->plug)->firstOrFail();
        expect($second->qty_on_hand)->toBe('8.000');
        expect(StockMovement::query()->count())->toBeGreaterThan(15);
    });
    expect($late)->toBeInt();
});

it('detects every kind of inconsistency', function () {
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true, 'items' => [
        ['product_variant_id' => $this->oil, 'qty' => '10', 'unit_cost' => '280'],
        ['product_variant_id' => $this->plug, 'qty' => '10', 'unit_cost' => '120'],
    ]])->assertCreated();
    $this->postJson('/api/v1/cash-sessions', ['client_uuid' => (string) Str::uuid(), 'terminal_id' => $this->terminal->id, 'opening_float' => '0'])->assertCreated();
    ($this->sell)([[$this->oil, '3']]);
    expect(($this->verify)())->toBe([]);

    $checks = fn () => collect(($this->verify)())->pluck('check')->unique()->sort()->values()->all();

    // Balance edited by hand (ledger + layers disagree).
    DB::table('inventory_balances')->where('product_variant_id', $this->oil)->update(['qty_on_hand' => '9.000']);
    expect($checks())->toBe(['layers', 'ledger']);
    DB::table('inventory_balances')->where('product_variant_id', $this->oil)->update(['qty_on_hand' => '7.000']);

    // Wrong stock value.
    DB::table('inventory_balances')->where('product_variant_id', $this->plug)->update(['total_cost' => '1199.99']);
    expect($checks())->toBe(['layers']);
    DB::table('inventory_balances')->where('product_variant_id', $this->plug)->update(['total_cost' => '1200.00']);

    // A movement's running balance tampered with.
    $movement = DB::table('stock_movements')->where('product_variant_id', $this->oil)->orderBy('id')->first();
    DB::table('stock_movements')->where('id', $movement->id)->update(['balance_after' => '11.000']);
    expect($checks())->toBe(['history']);
    DB::table('stock_movements')->where('id', $movement->id)->update(['balance_after' => $movement->balance_after]);

    // A cost layer with more remaining than was received.
    $layer = $this->asTenant($this->tenant, fn () => InventoryLayer::query()->where('product_variant_id', $this->plug)->firstOrFail());
    DB::table('inventory_layers')->where('id', $layer->id)->update(['qty_remaining' => '11.000']);
    expect($checks())->toBe(['layer_bounds', 'layers']);
    DB::table('inventory_layers')->where('id', $layer->id)->update(['qty_remaining' => '10.000']);

    // A sale that took more from the cost layers than it sold.
    $out = DB::table('stock_movements')->where('product_variant_id', $this->oil)->where('qty', '<', 0)->value('id');
    DB::table('stock_movement_layers')->where('stock_movement_id', $out)->update(['qty' => '4.000']);
    expect($checks())->toBe(['costing']);
    DB::table('stock_movement_layers')->where('stock_movement_id', $out)->update(['qty' => '3.000']);

    expect(($this->verify)())->toBe([]);
    DB::table('inventory_balances')->where('product_variant_id', $this->oil)->update(['qty_on_hand' => '1.000']);
    $this->artisan('inventory:verify')->assertExitCode(1);
});

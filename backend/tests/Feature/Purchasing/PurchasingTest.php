<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\InventoryBalance;
use App\Models\ProductVariant;
use App\Models\Unit;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'business');
    $this->tenant->forceFill(['vat_registered' => true])->save();
    $this->units = $this->asTenant($this->tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    $this->actingAsApi($this->owner)->withHeader('X-Branch-Id', (string) $this->main->id);

    // Coke: sold per piece, bought per box of 12.
    $this->coke = createStockItem($this->units['pc'], ['name' => 'Coke Mismo', 'variants' => [[
        'cost_price' => null, 'reorder_level' => '24',
        'units' => [['unit_id' => $this->units['box'], 'conversion_factor' => '12', 'selling_price' => '228', 'is_sellable' => true, 'is_purchasable' => true, 'barcodes' => ['BOX-COKE']]],
    ]]]);
    $this->box = $this->asTenant($this->tenant, fn () => ProductVariant::query()->findOrFail($this->coke)->units()->value('id'));

    $this->supplier = $this->postJson('/api/v1/suppliers', [
        'name' => 'Coca-Cola Beverages PH', 'vat_registered' => true, 'payment_terms_days' => 30,
        'phone' => '0917 123 4567', 'tin' => '123456789000',
    ])->assertCreated()->json('data.id');

    $this->stock = fn (int $variantId, ?int $branchId = null) => $this->asTenant($this->tenant, fn () => InventoryBalance::query()
        ->where('branch_id', $branchId ?? $this->main->id)->where('product_variant_id', $variantId)->first());

    $this->order = fn (array $overrides = []) => $this->postJson('/api/v1/purchase-orders', array_replace([
        'supplier_id' => $this->supplier,
        'items' => [['product_variant_id' => $this->coke, 'product_unit_id' => $this->box, 'qty' => '5', 'unit_cost' => '224']],
    ], $overrides));
});

/* ------------------------------------------------------------------ Suppliers */

it('manages suppliers and only deletes those without history', function () {
    $this->getJson("/api/v1/suppliers/{$this->supplier}")->assertOk()
        ->assertJsonPath('data.tin', '123-456-789')
        ->assertJsonPath('data.phone', '+639171234567');

    $this->postJson('/api/v1/suppliers', ['name' => 'X', 'vat_registered' => false, 'payment_terms_days' => 0, 'tin' => '12'])
        ->assertUnprocessable()->assertJsonValidationErrors('tin');

    $spare = $this->postJson('/api/v1/suppliers', ['name' => 'Spare', 'vat_registered' => false, 'payment_terms_days' => 0])->json('data.id');
    $this->deleteJson("/api/v1/suppliers/{$spare}")->assertNoContent();

    ($this->order)()->assertCreated();
    $this->deleteJson("/api/v1/suppliers/{$this->supplier}")->assertStatus(409)->assertJsonPath('code', 'SUPPLIER_IN_USE');
    $this->postJson("/api/v1/suppliers/{$this->supplier}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
    ($this->order)()->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
});

it('requires the purchasing feature', function () {
    usePlan($this->tenant->id, 'test-limited');
    $this->getJson('/api/v1/suppliers')->assertStatus(402)->assertJsonPath('code', 'FEATURE_NOT_AVAILABLE');
    $this->getJson('/api/v1/purchase-orders')->assertStatus(402);
});

/* ------------------------------------------------------------------ Orders */

it('computes PO totals with the input VAT split out', function () {
    $po = ($this->order)()->assertCreated();

    // 5 boxes x 224.00 = 1,120.00 incl. 120.00 VAT (12/112)
    expect($po->json('data.status'))->toBe('draft')
        ->and($po->json('data.number'))->toStartWith('PO-')
        ->and($po->json('data.total'))->toBe('1120.00')
        ->and($po->json('data.tax_total'))->toBe('120.00')
        ->and($po->json('data.subtotal'))->toBe('1000.00')
        ->and($po->json('data.items.0.unit.abbreviation'))->toBe('box')
        ->and($po->json('data.items.0.unit.factor'))->toBe('12.0000');

    // Non-VAT supplier: no input VAT.
    $plain = $this->postJson('/api/v1/suppliers', ['name' => 'Palengke', 'vat_registered' => false, 'payment_terms_days' => 0])->json('data.id');
    ($this->order)(['supplier_id' => $plain])->assertCreated()->assertJsonPath('data.tax_total', '0.00')->assertJsonPath('data.total', '1120.00');
});

it('needs po.approve to approve, and never changes stock', function () {
    $manager = $this->createUser($this->tenant, 'inventory_manager');
    $this->actingAsApi($manager);

    $id = ($this->order)()->assertCreated()->assertJsonPath('data.can.approve', false)->json('data.id');
    ($this->order)(['approve' => true])->assertForbidden();
    $this->postJson("/api/v1/purchase-orders/{$id}/approve")->assertForbidden();

    $this->actingAsApi($this->owner);
    $this->postJson("/api/v1/purchase-orders/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    $this->putJson("/api/v1/purchase-orders/{$id}", ['supplier_id' => $this->supplier,
        'items' => [['product_variant_id' => $this->coke, 'qty' => '1', 'unit_cost' => '1']]])->assertStatus(409);

    expect(($this->stock)($this->coke))->toBeNull();
    expect(AuditLog::query()->where('event', 'purchase_order.approved')->exists())->toBeTrue();
});

it('validates PO lines: units, whole boxes and duplicates', function () {
    $other = createStockItem($this->units['pc']);
    $otherUnit = $this->asTenant($this->tenant, fn () => ProductVariant::query()->findOrFail($this->coke)->units()->value('id'));

    ($this->order)(['items' => [['product_variant_id' => $other, 'product_unit_id' => $otherUnit, 'qty' => '1', 'unit_cost' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.product_unit_id');
    ($this->order)(['items' => [['product_variant_id' => $this->coke, 'product_unit_id' => $this->box, 'qty' => '1.5', 'unit_cost' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.qty');
    ($this->order)(['items' => [
        ['product_variant_id' => $this->coke, 'qty' => '1', 'unit_cost' => '1'],
        ['product_variant_id' => $this->coke, 'qty' => '2', 'unit_cost' => '1'],
    ]])->assertUnprocessable()->assertJsonValidationErrors('items.1.product_variant_id');
});

/* ------------------------------------------------------------------ Receiving */

it('receives a PO in parts, valuing stock net of input VAT per base unit', function () {
    $po = ($this->order)(['approve' => true])->assertCreated();
    $id = $po->json('data.id');
    $line = $po->json('data.items.0.id');

    // 2 boxes arrive: 24 pieces at (448.00 - 48.00) / 24 = 16.6667
    $receipt = $this->postJson('/api/v1/goods-receipts', [
        'purchase_order_id' => $id, 'supplier_invoice_no' => 'SI-1001',
        'items' => [['purchase_order_item_id' => $line, 'qty' => '2']],
    ])->assertCreated();

    expect($receipt->json('data.number'))->toStartWith('GR-')
        ->and($receipt->json('data.total'))->toBe('448.00')
        ->and($receipt->json('data.tax_total'))->toBe('48.00')
        ->and($receipt->json('data.items.0.base_qty'))->toBe('24.000');

    $balance = ($this->stock)($this->coke);
    expect($balance->qty_on_hand)->toBe('24.000')->and($balance->total_cost)->toBe('400.00');
    expect($this->getJson("/api/v1/products/{$this->asTenant($this->tenant, fn () => ProductVariant::query()->find($this->coke)->product_id)}")
        ->json('data.variants.0.cost_price'))->toBe('16.6667');

    $this->getJson("/api/v1/purchase-orders/{$id}")->assertJsonPath('data.status', 'partially_received')
        ->assertJsonPath('data.items.0.qty_remaining', '3.000');

    // More than what is left is refused.
    $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $id, 'items' => [['purchase_order_item_id' => $line, 'qty' => '4']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['items.0.qty' => 'Only 3 left']);

    // The rest arrives at a different invoice price.
    $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $id, 'items' => [['purchase_order_item_id' => $line, 'qty' => '3', 'unit_cost' => '235.20']]])
        ->assertCreated();
    $this->getJson("/api/v1/purchase-orders/{$id}")->assertJsonPath('data.status', 'received');
    // 400.00 + (705.60 - 75.60) = 1,030.00
    expect(($this->stock)($this->coke)->total_cost)->toBe('1030.00');
});

it('refuses to receive drafts, other branches\' orders and closed orders', function () {
    $draft = ($this->order)()->json('data');
    $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $draft['id'], 'items' => [['purchase_order_item_id' => $draft['items'][0]['id'], 'qty' => '1']]])
        ->assertStatus(409)->assertJsonPath('code', 'PO_NOT_RECEIVABLE');

    $second = $this->asTenant($this->tenant, fn () => Branch::query()->create(['code' => 'B2', 'name' => 'Second', 'is_active' => true]));
    $po = ($this->order)(['approve' => true])->json('data');
    $this->withHeader('X-Branch-Id', (string) $second->id)
        ->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $po['id'], 'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('purchase_order_id');

    $this->withHeader('X-Branch-Id', (string) $this->main->id);
    $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $po['id'], 'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => '1']]])->assertCreated();
    $this->postJson("/api/v1/purchase-orders/{$po['id']}/cancel")->assertStatus(409);
    $this->postJson("/api/v1/purchase-orders/{$po['id']}/close")->assertOk()->assertJsonPath('data.status', 'closed');
    $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $po['id'], 'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => '1']]])
        ->assertStatus(409);
});

it('receives directly without a PO, with batches for expiry-tracked items', function () {
    $medicine = createStockItem($this->units['pc'], ['name' => 'Biogesic', 'track_expiry' => true, 'tax_class' => 'vat_exempt']);

    $this->postJson('/api/v1/goods-receipts', ['supplier_id' => $this->supplier,
        'items' => [['product_variant_id' => $medicine, 'qty' => '100', 'unit_cost' => '3.50']]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.expiry_date');

    $receipt = $this->postJson('/api/v1/goods-receipts', ['supplier_id' => $this->supplier, 'items' => [
        ['product_variant_id' => $medicine, 'qty' => '100', 'unit_cost' => '3.50', 'batch_no' => 'L-77', 'expiry_date' => '2028-01-31'],
    ]])->assertCreated();

    // VAT-exempt item: no input VAT even from a VAT supplier.
    expect($receipt->json('data.tax_total'))->toBe('0.00')->and($receipt->json('data.purchase_order'))->toBeNull();
    $layers = $this->getJson("/api/v1/inventory/stock/{$medicine}")->json('data.layers');
    expect($layers[0])->toMatchArray(['batch_no' => 'L-77', 'expiry_date' => '2028-01-31', 'unit_cost' => '3.5000']);
});

it('reverses an untouched receipt exactly, and refuses once its stock was used', function () {
    $po = ($this->order)(['approve' => true])->json('data');
    $receiptId = $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $po['id'],
        'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => '1']]])->json('data.id');

    $this->postJson("/api/v1/goods-receipts/{$receiptId}/reverse", ['reason' => 'Wrong delivery'])->assertOk()
        ->assertJsonPath('data.status', 'reversed');
    expect(($this->stock)($this->coke)->qty_on_hand)->toBe('0.000');
    $this->getJson("/api/v1/purchase-orders/{$po['id']}")->assertJsonPath('data.status', 'approved')->assertJsonPath('data.items.0.qty_received', '0.000');
    $this->postJson("/api/v1/goods-receipts/{$receiptId}/reverse", ['reason' => 'again'])->assertStatus(409);

    // Receive again, sell 1 piece (adjustment), then reversal is refused.
    $again = $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $po['id'],
        'items' => [['purchase_order_item_id' => $po['items'][0]['id'], 'qty' => '1']]])->json('data.id');
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'damaged', 'post' => true,
        'items' => [['product_variant_id' => $this->coke, 'qty' => '1']]])->assertCreated();
    $this->postJson("/api/v1/goods-receipts/{$again}/reverse", ['reason' => 'Wrong'])->assertStatus(409)->assertJsonPath('code', 'RECEIPT_STOCK_USED');

    $types = collect($this->getJson("/api/v1/inventory/movements?product_variant_id={$this->coke}")->json('data'))->pluck('type')->all();
    expect($types)->toBe(['adjustment_out', 'purchase_receipt', 'receipt_reversal', 'purchase_receipt']);
});

it('suggests low-stock items for ordering and finds items by box barcode', function () {
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true,
        'items' => [['product_variant_id' => $this->coke, 'qty' => '10', 'unit_cost' => '15']]])->assertCreated();

    $low = $this->getJson('/api/v1/purchasing/items?low_stock=1')->assertOk()->json('data');
    // reorder 24, on hand 10: suggest 48 - 10 = 38 pieces
    expect($low)->toHaveCount(1)->and($low[0]['suggested_qty'])->toBe('38')->and($low[0]['units'])->toHaveCount(2);

    $hit = $this->getJson('/api/v1/purchasing/items?q=BOX-COKE')->assertOk()->json('data');
    expect($hit[0]['variant']['id'])->toBe($this->coke)->and($hit[0]['default_unit_id'])->toBe($this->box);
});

it('keeps other tenants out', function () {
    $po = ($this->order)()->json('data.id');
    ['owner' => $otherOwner, 'tenant' => $other, 'branch' => $otherBranch] = $this->createTenant();
    usePlan($other->id, 'business');
    $this->actingAsApi($otherOwner)->withHeader('X-Branch-Id', (string) $otherBranch->id);

    $this->getJson("/api/v1/purchase-orders/{$po}")->assertNotFound();
    $this->getJson("/api/v1/suppliers/{$this->supplier}")->assertNotFound();
    $this->postJson('/api/v1/goods-receipts', ['supplier_id' => $this->supplier,
        'items' => [['product_variant_id' => $this->coke, 'qty' => '1', 'unit_cost' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['supplier_id', 'items.0.product_variant_id']);
});

it('receives a purchase order with several lines (box and piece) in one delivery', function () {
    $oil = createStockItem($this->units['pc'], ['name' => 'Engine Oil 1L', 'variants' => [['selling_price' => '380', 'cost_price' => '290']]]);
    $order = ($this->order)(['items' => [
        ['product_variant_id' => $this->coke, 'product_unit_id' => $this->box, 'qty' => '2', 'unit_cost' => '224'],
        ['product_variant_id' => $oil, 'qty' => '6', 'unit_cost' => '290'],
    ]])->assertCreated()->json('data');
    $this->postJson("/api/v1/purchase-orders/{$order['id']}/approve")->assertOk();
    $lines = collect($this->getJson("/api/v1/purchase-orders/{$order['id']}")->json('data.items'));

    $receipt = $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $order['id'], 'supplier_invoice_no' => 'SI-2002',
        'items' => $lines->map(fn ($l) => ['purchase_order_item_id' => $l['id'], 'qty' => $l['qty_ordered']])->all()])->assertCreated();

    expect($receipt->json('data.items'))->toHaveCount(2);
    expect(($this->stock)($this->coke)->qty_on_hand)->toBe('24.000')   // 2 boxes × 12
        ->and(($this->stock)($oil)->qty_on_hand)->toBe('6.000');
    $this->getJson("/api/v1/purchase-orders/{$order['id']}")->assertJsonPath('data.status', 'received');

    // Bill the two-line delivery, void the bill, then reverse the delivery.
    $bill = $this->postJson('/api/v1/supplier-bills', ['goods_receipt_id' => $receipt->json('data.id'), 'bill_date' => now('Asia/Manila')->toDateString()])
        ->assertCreated()->assertJsonPath('data.total', '2188.00')->json('data.id'); // 448 + 1,740
    $this->postJson("/api/v1/supplier-bills/{$bill}/void", ['reason' => 'Wrong invoice'])->assertOk();
    $this->postJson("/api/v1/goods-receipts/{$receipt->json('data.id')}/reverse", ['reason' => 'Entered twice'])->assertOk();
    expect(($this->stock)($this->coke)->qty_on_hand)->toBe('0.000')->and(($this->stock)($oil)->qty_on_hand)->toBe('0.000');

    // A second two-line order, partly received, then closed.
    $second = ($this->order)(['items' => [
        ['product_variant_id' => $this->coke, 'product_unit_id' => $this->box, 'qty' => '3', 'unit_cost' => '224'],
        ['product_variant_id' => $oil, 'qty' => '4', 'unit_cost' => '290'],
    ]])->assertCreated()->json('data.id');
    $this->postJson("/api/v1/purchase-orders/{$second}/approve")->assertOk();
    $first = collect($this->getJson("/api/v1/purchase-orders/{$second}")->json('data.items'))->first();
    $this->postJson('/api/v1/goods-receipts', ['purchase_order_id' => $second, 'items' => [['purchase_order_item_id' => $first['id'], 'qty' => '1']]])->assertCreated();
    $this->postJson("/api/v1/purchase-orders/{$second}/close")->assertOk();
    $this->getJson('/api/v1/purchase-orders')->assertOk();
});

<?php

use App\Models\Unit;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'business');
    $units = $this->asTenant($this->tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    $this->actingAsApi($this->owner)->withHeader('X-Branch-Id', (string) $this->main->id);
    $this->item = createStockItem($units['pc'], ['name' => 'Coke']);
    $this->supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Coca-Cola Beverages PH', 'vat_registered' => false, 'payment_terms_days' => 30])->assertCreated()->json('data.id');
    $this->receive = fn (string $qty, string $cost) => $this->postJson('/api/v1/goods-receipts', [
        'supplier_id' => $this->supplier, 'supplier_invoice_no' => 'SI-'.$qty,
        'items' => [['product_variant_id' => $this->item, 'qty' => $qty, 'unit_cost' => $cost]],
    ])->assertCreated()->json('data');
});

it('bills goods receipts, pays several bills at once and tracks balances', function () {
    $r1 = ($this->receive)('10', '100');   // 1,000.00
    $r2 = ($this->receive)('5', '50');     // 250.00

    expect(collect($this->getJson('/api/v1/supplier-bills/billable-receipts')->json('data'))->pluck('id')->all())->toEqualCanonicalizing([$r1['id'], $r2['id']]);

    $billDate = now('Asia/Manila')->subDays(40)->toDateString();
    $b1 = $this->postJson('/api/v1/supplier-bills', ['goods_receipt_id' => $r1['id'], 'bill_date' => $billDate])->assertCreated();
    expect($b1->json('data'))->toMatchArray(['total' => '1000.00', 'supplier_invoice_no' => 'SI-10', 'status' => 'open', 'overdue' => true])
        ->and($b1->json('data.due_date'))->toBe(now('Asia/Manila')->subDays(10)->toDateString()) // 30-day terms
        ->and($b1->json('data.number'))->toStartWith('BILL-');
    $this->postJson('/api/v1/supplier-bills', ['goods_receipt_id' => $r1['id'], 'bill_date' => $billDate])->assertUnprocessable(); // billed once
    $b2 = $this->postJson('/api/v1/supplier-bills', ['goods_receipt_id' => $r2['id'], 'bill_date' => now('Asia/Manila')->toDateString()])->assertCreated();
    $service = $this->postJson('/api/v1/supplier-bills', ['supplier_id' => $this->supplier, 'bill_date' => now('Asia/Manila')->toDateString(), 'total' => '500'])->assertCreated();

    // Pay 1,100.00: all of bill 1 and 100.00 of bill 2.
    $pay = fn (string $amount, array $allocations) => $this->postJson('/api/v1/supplier-payments', [
        'supplier_id' => $this->supplier, 'paid_at' => now('Asia/Manila')->toDateString(), 'amount' => $amount, 'allocations' => $allocations,
    ]);
    $pay('1200', [['supplier_bill_id' => $b1->json('data.id'), 'amount' => '1000'], ['supplier_bill_id' => $b2->json('data.id'), 'amount' => '100']])
        ->assertUnprocessable()->assertJsonValidationErrors('amount'); // must add up
    $pay('400', [['supplier_bill_id' => $b2->json('data.id'), 'amount' => '400']])->assertUnprocessable()->assertJsonValidationErrors('allocations.0.amount');
    $payment = $pay('1100', [['supplier_bill_id' => $b1->json('data.id'), 'amount' => '1000'], ['supplier_bill_id' => $b2->json('data.id'), 'amount' => '100']])->assertCreated();
    expect($payment->json('data.number'))->toStartWith('SP-');

    $this->getJson('/api/v1/supplier-bills/'.$b1->json('data.id'))->assertJsonPath('data.status', 'paid')->assertJsonPath('data.payments.0.amount', '1000.00');
    $this->getJson('/api/v1/supplier-bills/'.$b2->json('data.id'))->assertJsonPath('data.status', 'partially_paid')->assertJsonPath('data.balance', '150.00');

    // Statement: 1,000 + 250 + 500 − 1,100 = 650.00
    $statement = $this->getJson("/api/v1/suppliers/{$this->supplier}/statement")->assertOk();
    expect($statement->json('data.balance'))->toBe('650.00')->and($statement->json('data.entries'))->toHaveCount(4);

    // Aging: 150.00 (bill 2) + 500.00 (service bill) not yet due.
    $aging = $this->getJson('/api/v1/reports/payables')->assertOk()->json('data.rows.0');
    expect($aging)->toMatchArray(['supplier' => 'Coca-Cola Beverages PH', 'bills' => 2, 'current' => '650.00', 'balance' => '650.00']);

    // Voiding the payment puts the amounts back on the bills.
    $this->postJson('/api/v1/supplier-payments/'.$payment->json('data.id').'/void', ['reason' => 'Bounced check'])->assertOk()->assertJsonPath('data.status', 'void');
    $this->getJson('/api/v1/supplier-bills/'.$b1->json('data.id'))->assertJsonPath('data.status', 'open');
    $this->getJson("/api/v1/suppliers/{$this->supplier}/statement")->assertJsonPath('data.balance', '1750.00');

    // An unpaid bill can be voided.
    $this->postJson('/api/v1/supplier-bills/'.$service->json('data.id').'/void', ['reason' => 'Entered twice'])->assertOk()->assertJsonPath('data.status', 'void');
});

it('keeps paid deliveries from being reversed and voids an unpaid bill with its receipt', function () {
    $paid = ($this->receive)('2', '10');
    $unpaid = ($this->receive)('3', '10');
    $bill = $this->postJson('/api/v1/supplier-bills', ['goods_receipt_id' => $paid['id'], 'bill_date' => now()->toDateString()])->json('data.id');
    $open = $this->postJson('/api/v1/supplier-bills', ['goods_receipt_id' => $unpaid['id'], 'bill_date' => now()->toDateString()])->json('data.id');
    $this->postJson('/api/v1/supplier-payments', ['supplier_id' => $this->supplier, 'paid_at' => now()->toDateString(), 'amount' => '5',
        'allocations' => [['supplier_bill_id' => $bill, 'amount' => '5']]])->assertCreated();

    $this->postJson("/api/v1/goods-receipts/{$paid['id']}/reverse", ['reason' => 'Wrong delivery'])->assertStatus(409)->assertJsonPath('code', 'RECEIPT_BILLED');
    $this->postJson("/api/v1/goods-receipts/{$unpaid['id']}/reverse", ['reason' => 'Wrong delivery'])->assertOk();
    $this->getJson("/api/v1/supplier-bills/{$open}")->assertJsonPath('data.status', 'void');
});

it('limits payables to people who manage supplier payments', function () {
    ($this->receive)('1', '10');
    $cashier = $this->createUser($this->tenant, 'cashier');
    $this->actingAsApi($cashier)->withHeader('X-Branch-Id', (string) $this->main->id);
    $this->getJson('/api/v1/supplier-bills')->assertForbidden();
});

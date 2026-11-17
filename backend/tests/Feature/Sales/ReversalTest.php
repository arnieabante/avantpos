<?php

use App\Models\AuditLog;
use App\Models\CashRegister;
use App\Models\InventoryBalance;
use App\Models\PaymentMethod;
use App\Models\Refund;
use App\Models\Unit;
use Illuminate\Support\Str;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'business');
    $this->tenant->forceFill(['vat_registered' => true])->save();
    $units = $this->asTenant($this->tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    $this->methods = $this->asTenant($this->tenant, fn () => PaymentMethod::query()->pluck('id', 'type')->all());

    $this->terminal = $this->createTerminal($this->tenant, $this->main);
    $this->asTenant($this->tenant, fn () => CashRegister::query()->create(['branch_id' => $this->main->id, 'terminal_id' => $this->terminal->id, 'name' => 'Drawer', 'is_active' => true]));

    $this->as = fn ($user) => $this->actingAsApi($user)->withHeader('X-Branch-Id', (string) $this->main->id);
    ($this->as)($this->owner);

    $this->coke = createStockItem($units['pc'], ['name' => 'Coke', 'sc_pwd_eligible' => false, 'variants' => [['selling_price' => '20']]]);
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true, 'items' => [
        ['product_variant_id' => $this->coke, 'qty' => '100', 'unit_cost' => '14'],
    ]])->assertCreated();

    $this->cashier = $this->createUser($this->tenant, 'cashier');
    $this->manager = $this->createUser($this->tenant, 'branch_manager');
    $this->asTenant($this->tenant, fn () => $this->manager->setPin('4321'));

    $this->openShift = function ($user) {
        ($this->as)($user);

        return $this->postJson('/api/v1/cash-sessions', ['client_uuid' => (string) Str::uuid(), 'terminal_id' => $this->terminal->id, 'opening_float' => '1000'])
            ->assertCreated()->json('data.id');
    };
    $this->sell = fn (string $qty, array $extra = []) => $this->postJson('/api/v1/pos/sales', $extra + [
        'client_uuid' => (string) Str::uuid(),
        'items' => [['product_variant_id' => $this->coke, 'qty' => $qty]],
        'payments' => [['payment_method_id' => $this->methods['cash'], 'amount' => (string) ($qty * 20)]],
    ])->assertCreated()->json('data');
    $this->token = fn (string $permission) => $this->postJson('/api/v1/auth/override', ['supervisor_id' => $this->manager->id, 'pin' => '4321', 'permission' => $permission])
        ->assertSuccessful()->json('data.override_token');
    $this->stock = fn () => $this->asTenant($this->tenant, fn () => InventoryBalance::query()->where('product_variant_id', $this->coke)->value('qty_on_hand'));
    $this->expected = function (int $session) {
        ($this->as)($this->owner);

        return $this->getJson("/api/v1/cash-sessions/{$session}")->json('data.summary');
    };
});

it('voids a sale of the open shift with a supervisor PIN: stock and cash come back', function () {
    $session = ($this->openShift)($this->cashier);
    $sale = ($this->sell)('3');
    expect(($this->stock)())->toBe('97.000');

    $this->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Wrong item'])
        ->assertForbidden()->assertJsonPath('code', 'APPROVAL_REQUIRED')->assertJsonPath('context.permissions', ['sales.void']);

    $voided = $this->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Wrong item', 'override_token' => ($this->token)('sales.void')])->assertOk();
    expect($voided->json('data.status'))->toBe('voided')
        ->and($voided->json('data.void'))->toMatchArray(['reason' => 'Wrong item', 'authorized_by' => ['id' => $this->manager->id, 'name' => $this->manager->name]])
        ->and($voided->json('data.can'))->toMatchArray(['void' => false, 'refund' => false]);
    expect(($this->stock)())->toBe('100.000');

    $this->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Again', 'override_token' => ($this->token)('sales.void')])
        ->assertStatus(409)->assertJsonPath('code', 'SALE_NOT_VOIDABLE');

    // The shift no longer counts it.
    expect(($this->expected)($session))->toMatchArray(['cash_sales' => '0.00', 'expected_cash' => '1000.00', 'sales_count' => 0]);
    $this->asTenant($this->tenant, fn () => expect(AuditLog::query()->where('event', 'sale.voided')->value('authorized_by_id'))->toBe($this->manager->id));
});

it('cannot void once the shift is closed; a refund is used instead', function () {
    $session = ($this->openShift)($this->owner);
    $sale = ($this->sell)('1');
    $this->postJson("/api/v1/cash-sessions/{$session}/close", ['counted_cash' => '1020'])->assertOk();

    $this->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Late'])->assertStatus(409)->assertJsonPath('code', 'VOID_SHIFT_CLOSED');
});

it('refunds part of a line by its share, then the rest exactly, and restocks or writes off', function () {
    $session = ($this->openShift)($this->cashier);
    $sale = ($this->sell)('3'); // 60.00, VAT 6.43
    $item = $sale['items'][0]['id'];
    $refund = fn (string $qty, string $restock, array $extra = []) => $this->postJson("/api/v1/sales/{$sale['id']}/refunds", $extra + [
        'client_uuid' => (string) Str::uuid(), 'reason' => 'Customer changed mind', 'items' => [['sale_item_id' => $item, 'qty' => $qty, 'restock' => $restock]],
    ]);

    $refund('1', 'sellable')->assertForbidden()->assertJsonPath('code', 'APPROVAL_REQUIRED');

    $first = $refund('1', 'sellable', ['override_token' => ($this->token)('sales.refund')])->assertCreated();
    // 1/3 of 60.00 and of 6.43
    expect($first->json('data.refund'))->toMatchArray(['total' => '20.00', 'vat_amount' => '2.14', 'subtotal' => '17.86'])
        ->and($first->json('data.refund.number'))->toStartWith('RF-')
        ->and($first->json('data.refund.payments.0'))->toMatchArray(['type' => 'cash', 'amount' => '20.00'])
        ->and($first->json('data.sale.status'))->toBe('partially_refunded')
        ->and($first->json('data.sale.items.0.qty_refunded'))->toBe('1.000');
    expect(($this->stock)())->toBe('98.000');

    $refund('2.5', 'sellable', ['override_token' => ($this->token)('sales.refund')])->assertUnprocessable()->assertJsonValidationErrors('items.0.qty');
    $refund('3', 'sellable', ['override_token' => ($this->token)('sales.refund')])->assertUnprocessable()->assertJsonValidationErrors(['items.0.qty' => 'Only 2']);

    // The rest takes the exact remainder: 40.00 and 6.43 - 2.14 = 4.29. Damaged: not restocked.
    $second = $refund('2', 'damaged', ['override_token' => ($this->token)('sales.refund')])->assertCreated();
    expect($second->json('data.refund'))->toMatchArray(['total' => '40.00', 'vat_amount' => '4.29'])
        ->and($second->json('data.sale.status'))->toBe('refunded')
        ->and($second->json('data.sale.refunds'))->toHaveCount(2);
    expect(($this->stock)())->toBe('98.000');

    // Shift: 1000 + 60 - 60 refunded.
    expect(($this->expected)($session))->toMatchArray(['cash_sales' => '60.00', 'cash_refunds' => '60.00', 'expected_cash' => '1000.00']);
    $this->asTenant($this->tenant, fn () => expect(Refund::query()->sum('cogs_reversed'))->toEqual('14.00'));
});

it('is idempotent and puts credit sales back on the account', function () {
    $customer = $this->postJson('/api/v1/customers', ['name' => 'Aling Nena', 'allow_credit' => true, 'credit_limit' => '500'])->json('data.id');
    $account = $this->asTenant($this->tenant, fn () => PaymentMethod::chargeToAccount()->id);
    ($this->openShift)($this->owner);
    $sale = ($this->sell)('2', ['customer_id' => $customer, 'payments' => [['payment_method_id' => $account, 'amount' => '40']]]);
    $this->getJson("/api/v1/customers/{$customer}")->assertJsonPath('data.balance', '40.00');

    $body = ['client_uuid' => (string) Str::uuid(), 'reason' => 'Defective', 'items' => [['sale_item_id' => $sale['items'][0]['id'], 'qty' => '1', 'restock' => 'none']]];
    $first = $this->postJson("/api/v1/sales/{$sale['id']}/refunds", $body)->assertCreated();
    $this->postJson("/api/v1/sales/{$sale['id']}/refunds", $body)->assertOk()->assertJsonPath('data.refund.id', $first->json('data.refund.id'));

    expect($first->json('data.refund.payments'))->toBe([]); // nothing paid out: it went to the account
    $this->getJson("/api/v1/customers/{$customer}")->assertJsonPath('data.balance', '20.00');
});

it('produces X and Z readings with voids, refunds and a non-resettable grand total', function () {
    $session = ($this->openShift)($this->owner);
    $kept = ($this->sell)('3');                        // 60.00, VAT 6.43
    $voided = ($this->sell)('1');                      // 20.00, voided below
    $this->postJson("/api/v1/sales/{$voided['id']}/void", ['reason' => 'Wrong item'])->assertOk();
    $this->postJson("/api/v1/sales/{$kept['id']}/refunds", ['client_uuid' => (string) Str::uuid(), 'reason' => 'Return',
        'items' => [['sale_item_id' => $kept['items'][0]['id'], 'qty' => '1', 'restock' => 'sellable']]])->assertCreated();

    $expected = [
        'transaction_count' => 1, 'gross_sales' => '80.00', 'void_amount' => '20.00', 'refund_amount' => '20.00', 'net_sales' => '40.00',
        'vatable_sales' => '35.71', 'vat_amount' => '4.29', 'void_count' => 1, 'refund_count' => 1,
        'beginning_invoice_no' => '000000001', 'ending_invoice_no' => '000000002',
    ];
    $x = $this->postJson("/api/v1/cash-sessions/{$session}/x-reading")->assertCreated();
    expect($x->json('data'))->toMatchArray($expected + ['type' => 'X', 'reading_no' => 1])
        ->and($x->json('data.payments.0'))->toMatchArray(['type' => 'cash', 'received' => '60.00', 'refunded' => '20.00']);

    $this->postJson('/api/v1/pos/z-readings', ['terminal_id' => $this->terminal->id])->assertStatus(409)->assertJsonPath('code', 'SHIFT_STILL_OPEN');
    $this->postJson("/api/v1/cash-sessions/{$session}/close", ['counted_cash' => '1040'])->assertOk();

    $z = $this->postJson('/api/v1/pos/z-readings', ['terminal_id' => $this->terminal->id])->assertCreated();
    expect($z->json('data'))->toMatchArray($expected + ['type' => 'Z', 'reading_no' => 1, 'accumulated_grand_total_before' => '0.00', 'accumulated_grand_total_after' => '40.00']);
    $this->postJson('/api/v1/pos/z-readings', ['terminal_id' => $this->terminal->id])->assertStatus(409)->assertJsonPath('code', 'Z_ALREADY_DONE');

    // Next day: only the new sale, the grand total keeps growing.
    $this->travel(1)->days();
    $next = ($this->openShift)($this->owner);
    ($this->sell)('1');
    $this->postJson("/api/v1/cash-sessions/{$next}/close", ['counted_cash' => '1020'])->assertOk();
    $z2 = $this->postJson('/api/v1/pos/z-readings', ['terminal_id' => $this->terminal->id])->assertCreated();
    expect($z2->json('data'))->toMatchArray(['reading_no' => 2, 'gross_sales' => '20.00', 'net_sales' => '20.00', 'beginning_invoice_no' => '000000003',
        'accumulated_grand_total_before' => '40.00', 'accumulated_grand_total_after' => '60.00']);

    $this->getJson('/api/v1/pos/readings?type=Z')->assertOk()->assertJsonCount(2, 'data');

    // Cashiers cannot do Z readings.
    ($this->as)($this->cashier);
    $this->postJson('/api/v1/pos/z-readings', ['terminal_id' => $this->terminal->id])->assertForbidden();
});

it('lists sales (own only for cashiers), finds a receipt by number and logs reprints', function () {
    ($this->openShift)($this->cashier);
    $sale = ($this->sell)('1');

    $this->getJson('/api/v1/sales')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/sales/lookup?invoice_no=1')->assertOk()->assertJsonPath('data.0.id', $sale['id']);
    $this->postJson("/api/v1/sales/{$sale['id']}/reprint")->assertOk();
    $this->asTenant($this->tenant, fn () => expect(AuditLog::query()->where('event', 'sale.reprinted')->exists())->toBeTrue());

    $other = $this->createUser($this->tenant, 'cashier');
    ($this->as)($other);
    $this->getJson('/api/v1/sales')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/sales/{$sale['id']}")->assertNotFound();
    // ...but can find it by its receipt number to process a return.
    $this->getJson('/api/v1/sales/lookup?invoice_no=000000001')->assertOk()->assertJsonCount(1, 'data');
});

it('gives back points used and removes points earned when a sale is voided or fully refunded', function () {
    $this->putJson('/api/v1/loyalty-settings', ['enabled' => true, 'spend_per_point' => '50', 'point_value' => '1', 'min_redeem_points' => 0])->assertOk();
    $customer = $this->postJson('/api/v1/customers', ['name' => 'Suki'])->json('data.id');
    $this->postJson("/api/v1/customers/{$customer}/loyalty-adjustments", ['points' => '30', 'notes' => 'Opening points'])->assertOk();
    $points = function () use ($customer) {
        ($this->as)($this->owner);

        return $this->getJson("/api/v1/customers/{$customer}")->json('data.loyalty_points');
    };
    ($this->openShift)($this->owner);

    // 6 x 20 = 120 - 20 points = 100 → earns 2 points: 30 - 20 + 2 = 12.
    $sale = ($this->sell)('6', ['customer_id' => $customer, 'redeem_points' => 20, 'payments' => [['payment_method_id' => $this->methods['cash'], 'amount' => '100']]]);
    expect($points())->toBe('12.00');
    $this->postJson("/api/v1/sales/{$sale['id']}/void", ['reason' => 'Wrong customer'])->assertOk();
    expect($points())->toBe('30.00');

    // A 100.00 sale earns 2 points. Refunding 3 of 5 keeps 40.00, worth 0 points: both are removed.
    $sale = ($this->sell)('5', ['customer_id' => $customer]);
    expect($points())->toBe('32.00');
    $refund = fn (string $qty) => $this->postJson("/api/v1/sales/{$sale['id']}/refunds", ['client_uuid' => (string) Str::uuid(), 'reason' => 'Return',
        'items' => [['sale_item_id' => $sale['items'][0]['id'], 'qty' => $qty, 'restock' => 'sellable']]])->assertCreated();
    $refund('3'); // kept 40.00 → 0 points
    expect($points())->toBe('30.00');
    $refund('2');
    expect($points())->toBe('30.00');
});

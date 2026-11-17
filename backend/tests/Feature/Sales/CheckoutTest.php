<?php

use App\Models\CashRegister;
use App\Models\CustomerLedgerEntry;
use App\Models\InventoryBalance;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\SaleDiscount;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'business');
    $this->tenant->forceFill(['vat_registered' => true])->save();
    $this->units = $this->asTenant($this->tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    $this->methods = $this->asTenant($this->tenant, fn () => PaymentMethod::query()->pluck('id', 'type')->all());

    $this->terminal = $this->createTerminal($this->tenant, $this->main);
    $this->asTenant($this->tenant, fn () => CashRegister::query()->create(['branch_id' => $this->main->id, 'terminal_id' => $this->terminal->id, 'name' => 'Drawer', 'is_active' => true]));
    $this->terminal2 = $this->createTerminal($this->tenant, $this->main);
    $this->asTenant($this->tenant, fn () => CashRegister::query()->create(['branch_id' => $this->main->id, 'terminal_id' => $this->terminal2->id, 'name' => 'Drawer 2', 'is_active' => true]));

    $this->as = fn ($user) => $this->actingAsApi($user)->withHeader('X-Branch-Id', (string) $this->main->id);
    ($this->as)($this->owner);

    // Catalog: Coke ₱20 VATable; Biogesic ₱5.60 VATable, SC/PWD eligible; Rice ₱52.50/kg VAT-exempt.
    $this->coke = createStockItem($this->units['pc'], ['name' => 'Coke', 'sc_pwd_eligible' => false, 'variants' => [['selling_price' => '20', 'wholesale_price' => '18']]]);
    $this->biogesic = createStockItem($this->units['pc'], ['name' => 'Biogesic', 'sc_pwd_eligible' => true, 'variants' => [['selling_price' => '5.60']]]);
    $this->rice = createStockItem($this->units['kg'], ['name' => 'Rice', 'tax_class' => 'vat_exempt', 'variants' => [['selling_price' => '52.50']]]);
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true, 'items' => [
        ['product_variant_id' => $this->coke, 'qty' => '100', 'unit_cost' => '14'],
        ['product_variant_id' => $this->biogesic, 'qty' => '100', 'unit_cost' => '3'],
        ['product_variant_id' => $this->rice, 'qty' => '50', 'unit_cost' => '40'],
    ]])->assertCreated();

    $this->openShift = function ($user, $terminal = null) {
        ($this->as)($user);
        $this->postJson('/api/v1/cash-sessions', ['client_uuid' => (string) Str::uuid(), 'terminal_id' => ($terminal ?? $this->terminal)->id, 'opening_float' => '1000'])->assertCreated();
    };
    $this->sell = fn (array $cart) => $this->postJson('/api/v1/pos/sales', $cart + ['client_uuid' => (string) Str::uuid()]);
    $this->cash = fn (string $amount) => [['payment_method_id' => $this->methods['cash'], 'amount' => $amount]];
    $this->stock = fn (int $variant) => $this->asTenant($this->tenant, fn () => InventoryBalance::query()->where('product_variant_id', $variant)->value('qty_on_hand'));

    $this->cashier = $this->createUser($this->tenant, 'cashier');
});

it('sells for cash: VAT is extracted, stock goes out at FIFO cost, change is given', function () {
    ($this->openShift)($this->cashier);

    $sale = ($this->sell)(['items' => [['product_variant_id' => $this->coke, 'qty' => '3']], 'payments' => ($this->cash)('100')])->assertCreated();

    // 3 x 20.00 = 60.00 → VAT 60 - 60/1.12 = 6.43, net 53.57
    expect($sale->json('data.invoice_no'))->toBe('000000001')
        ->and($sale->json('data.totals'))->toMatchArray([
            'gross' => '60.00', 'vatable_sales' => '53.57', 'vat_amount' => '6.43', 'total' => '60.00', 'amount_paid' => '100.00', 'change' => '40.00',
        ])
        ->and($sale->json('data.payments.0'))->toMatchArray(['type' => 'cash', 'amount' => '60.00', 'tendered' => '100.00', 'change' => '40.00'])
        ->and($sale->json('data.totals'))->not->toHaveKey('cogs'); // cashiers do not see costs

    expect(($this->stock)($this->coke))->toBe('97.000');
    $this->asTenant($this->tenant, fn () => expect(Sale::query()->value('cogs_total'))->toBe('42.00'));

    // The shift's expected cash includes the sale.
    $shift = $this->getJson('/api/v1/pos/terminals')->json('data.my_session.id');
    ($this->as)($this->owner);
    $this->getJson("/api/v1/cash-sessions/{$shift}")->assertJsonPath('data.summary.cash_sales', '60.00')->assertJsonPath('data.summary.expected_cash', '1060.00')
        ->assertJsonPath('data.summary.sales_count', 1);
});

it('is idempotent: a retried checkout returns the same sale and charges nothing twice', function () {
    ($this->openShift)($this->cashier);
    $cart = ['client_uuid' => (string) Str::uuid(), 'items' => [['product_variant_id' => $this->coke, 'qty' => '1']], 'payments' => ($this->cash)('20')];

    $first = $this->postJson('/api/v1/pos/sales', $cart)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/pos/sales', $cart)->assertOk()->assertJsonPath('data.id', $first);

    expect(($this->stock)($this->coke))->toBe('99.000');
    $this->asTenant($this->tenant, fn () => expect(Sale::query()->count())->toBe(1));
});

it('splits VATable and VAT-exempt sales on one invoice', function () {
    ($this->openShift)($this->cashier);
    $totals = ($this->sell)(['items' => [
        ['product_variant_id' => $this->coke, 'qty' => '2'],
        ['product_variant_id' => $this->rice, 'qty' => '1.5'],
    ], 'payments' => ($this->cash)('118.75')])->assertCreated()->json('data.totals');

    // Coke 40.00 (net 35.71 + VAT 4.29), rice 1.5 kg x 52.50 = 78.75 VAT-exempt
    expect($totals)->toMatchArray(['vatable_sales' => '35.71', 'vat_amount' => '4.29', 'vat_exempt_sales' => '78.75', 'total' => '118.75', 'change' => '0.00']);
    expect(($this->stock)($this->rice))->toBe('48.500');
});

it('applies the Senior Citizen discount on the price net of VAT and keeps the ID encrypted', function () {
    ($this->openShift)($this->cashier);
    $sale = ($this->sell)([
        'sc_pwd' => ['type' => 'senior', 'name' => 'Lola Charing', 'id_no' => 'OSCA-2019-0045678'],
        'items' => [
            ['product_variant_id' => $this->biogesic, 'qty' => '10', 'sc_pwd' => true],
            ['product_variant_id' => $this->coke, 'qty' => '1'],
        ],
        'payments' => ($this->cash)('60'),
    ])->assertCreated();

    // Biogesic 56.00 → net of VAT 50.00 → less 20% (10.00) = 40.00, VAT-exempt. Coke 20.00 VATable.
    expect($sale->json('data.totals'))->toMatchArray([
        'gross' => '76.00', 'sc_pwd_discount' => '10.00', 'vat_exempt_sales' => '50.00',
        'vatable_sales' => '17.86', 'vat_amount' => '2.14', 'total' => '60.00',
    ])
        ->and($sale->json('data.items.0'))->toMatchArray(['tax_class' => 'vat_exempt', 'sc_pwd_discount' => '10.00', 'line_total' => '40.00', 'vat_amount' => '0.00'])
        ->and($sale->json('data.discounts.0.beneficiary'))->toBe(['name' => 'Lola Charing', 'id_masked' => '••••5678', 'tin' => null]);

    $raw = DB::table('sale_discounts')->value('beneficiary_id_no');
    expect($raw)->not->toContain('5678');
    $this->asTenant($this->tenant, fn () => expect(SaleDiscount::query()->first()->beneficiary_id_no)->toBe('OSCA-2019-0045678'));

    // Not eligible items, other discounts on SC lines, and missing details are refused.
    ($this->sell)(['sc_pwd' => ['type' => 'pwd', 'name' => 'X', 'id_no' => 'PWD-1'], 'items' => [['product_variant_id' => $this->coke, 'qty' => '1', 'sc_pwd' => true]], 'payments' => ($this->cash)('20')])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.sc_pwd');
    ($this->sell)(['items' => [['product_variant_id' => $this->biogesic, 'qty' => '1', 'sc_pwd' => true]], 'payments' => ($this->cash)('20')])
        ->assertUnprocessable()->assertJsonValidationErrors('sc_pwd');
});

it('spreads a sale discount over the lines before VAT', function () {
    ($this->openShift)($this->owner);
    $sale = ($this->sell)([
        'discount' => ['type' => 'percent', 'value' => '10'],
        'items' => [['product_variant_id' => $this->coke, 'qty' => '5'], ['product_variant_id' => $this->rice, 'qty' => '1']],
        'payments' => ($this->cash)('200'),
    ])->assertCreated();

    // Base 100.00 + 52.50 = 152.50; 10% = 15.25 → Coke 10.00, Rice 5.25.
    // Coke 90.00 (net 80.36 + VAT 9.64), Rice 47.25 exempt. Total 137.25.
    expect($sale->json('data.totals'))->toMatchArray([
        'transaction_discount' => '15.25', 'vatable_sales' => '80.36', 'vat_amount' => '9.64', 'vat_exempt_sales' => '47.25',
        'total' => '137.25', 'change' => '62.75',
    ])->and($sale->json('data.items.0.allocated_discount'))->toBe('10.00');
});

it('needs a supervisor PIN for what the cashier may not do, and records who approved', function () {
    $manager = $this->createUser($this->tenant, 'branch_manager');
    $this->asTenant($this->tenant, fn () => $manager->setPin('4321'));
    ($this->openShift)($this->cashier);
    $cart = ['items' => [['product_variant_id' => $this->coke, 'qty' => '1', 'discount' => ['type' => 'percent', 'value' => '50']]], 'payments' => ($this->cash)('10')];

    // The quote tells the POS what needs approval.
    $this->postJson('/api/v1/pos/quote', $cart)->assertOk()->assertJsonPath('data.approvals_missing', ['pos.discount_override_limit']);

    ($this->sell)($cart)->assertForbidden()->assertJsonPath('code', 'APPROVAL_REQUIRED')
        ->assertJsonPath('context.permissions', ['pos.discount_override_limit']);

    $token = $this->postJson('/api/v1/auth/override', ['supervisor_id' => $manager->id, 'pin' => '4321', 'permission' => 'pos.discount_override_limit'])
        ->assertSuccessful()->json('data.override_token');
    $sale = ($this->sell)($cart + ['overrides' => ['pos.discount_override_limit' => $token]])->assertCreated();
    expect($sale->json('data.totals.total'))->toBe('10.00')
        ->and($sale->json('data.discounts.0.authorized_by.id'))->toBe($manager->id);

    // The token was single-use.
    ($this->sell)($cart + ['overrides' => ['pos.discount_override_limit' => $token]])->assertForbidden()->assertJsonPath('code', 'OVERRIDE_REQUIRED');

    // Price overrides need permission too.
    ($this->sell)(['items' => [['product_variant_id' => $this->coke, 'qty' => '1', 'unit_price' => '15']], 'payments' => ($this->cash)('15')])
        ->assertForbidden()->assertJsonPath('context.permissions', ['pos.price_override']);
});

it('validates split payments: references, no overpayment by card, no short payment', function () {
    ($this->openShift)($this->cashier);
    $items = [['product_variant_id' => $this->coke, 'qty' => '3']]; // 60.00

    ($this->sell)(['items' => $items, 'payments' => [['payment_method_id' => $this->methods['gcash'], 'amount' => '60']]])
        ->assertUnprocessable()->assertJsonValidationErrors('payments.0.reference_no');
    ($this->sell)(['items' => $items, 'payments' => [['payment_method_id' => $this->methods['gcash'], 'amount' => '70', 'reference_no' => 'G1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('payments');
    ($this->sell)(['items' => $items, 'payments' => ($this->cash)('59.99')])
        ->assertUnprocessable()->assertJsonValidationErrors(['payments' => 'short by ₱0.01']);

    $sale = ($this->sell)(['items' => $items, 'payments' => [
        ['payment_method_id' => $this->methods['gcash'], 'amount' => '50', 'reference_no' => '1009 223 5512'],
        ['payment_method_id' => $this->methods['cash'], 'amount' => '20'],
    ]])->assertCreated();
    expect($sale->json('data.totals.change'))->toBe('10.00')
        ->and(collect($sale->json('data.payments'))->pluck('amount', 'type')->all())->toBe(['gcash' => '50.00', 'cash' => '10.00']);

    // Nothing was recorded for the refused attempts: invoice numbers stay gapless.
    expect($sale->json('data.invoice_no'))->toBe('000000001');
});

it('charges to a customer account within the credit limit', function () {
    $customer = $this->postJson('/api/v1/customers', ['name' => 'Aling Nena', 'allow_credit' => true, 'credit_limit' => '100'])->json('data.id');
    $account = $this->asTenant($this->tenant, fn () => PaymentMethod::chargeToAccount()->id);
    ($this->openShift)($this->owner);

    $sale = ($this->sell)(['customer_id' => $customer, 'items' => [['product_variant_id' => $this->coke, 'qty' => '3']],
        'payments' => [['payment_method_id' => $account, 'amount' => '60']]])->assertCreated();
    expect($sale->json('data.payments.0.type'))->toBe('credit');
    $this->getJson("/api/v1/customers/{$customer}")->assertJsonPath('data.balance', '60.00');
    $this->asTenant($this->tenant, fn () => expect(CustomerLedgerEntry::query()->value('reference_id'))->toBe($sale->json('data.id')));

    // Over the limit: refused, and nothing is recorded (stock, invoice number).
    ($this->sell)(['customer_id' => $customer, 'items' => [['product_variant_id' => $this->coke, 'qty' => '3']],
        'payments' => [['payment_method_id' => $account, 'amount' => '60']]])->assertUnprocessable()->assertJsonPath('code', 'CREDIT_LIMIT_EXCEEDED');
    expect(($this->stock)($this->coke))->toBe('97.000');

    // Cashiers need approval to sell on credit.
    ($this->openShift)($this->cashier, $this->terminal2);
    ($this->sell)(['customer_id' => $customer, 'items' => [['product_variant_id' => $this->coke, 'qty' => '1']],
        'payments' => [['payment_method_id' => $account, 'amount' => '20']]])->assertForbidden()->assertJsonPath('context.permissions', ['pos.credit_sale']);

    $next = ($this->sell)(['items' => [['product_variant_id' => $this->coke, 'qty' => '1']], 'payments' => ($this->cash)('20')])->assertCreated();
    expect($next->json('data.invoice_no'))->toBe('000000001'); // first invoice of terminal 2
});

it('redeems points as a discount before VAT and earns points on what was paid', function () {
    $this->putJson('/api/v1/loyalty-settings', ['enabled' => true, 'spend_per_point' => '100', 'point_value' => '1', 'min_redeem_points' => 10])->assertOk();
    $customer = $this->postJson('/api/v1/customers', ['name' => 'Suki'])->json('data.id');
    $this->postJson("/api/v1/customers/{$customer}/loyalty-adjustments", ['points' => '50', 'notes' => 'Opening points'])->assertOk();
    ($this->openShift)($this->cashier);

    $sale = ($this->sell)(['customer_id' => $customer, 'redeem_points' => 20, 'items' => [['product_variant_id' => $this->coke, 'qty' => '6']],
        'payments' => ($this->cash)('100')])->assertCreated();

    // 120.00 - 20 points (₱20.00) = 100.00 → VAT on 100.00 = 10.71; earns 1 point.
    expect($sale->json('data.totals'))->toMatchArray(['loyalty_discount' => '20.00', 'vat_amount' => '10.71', 'total' => '100.00'])
        ->and($sale->json('data.points'))->toBe(['redeemed' => '20.00', 'earned' => '1.00']);
    ($this->as)($this->owner);
    $this->getJson("/api/v1/customers/{$customer}")->assertJsonPath('data.loyalty_points', '31.00');

    ($this->as)($this->cashier);
    ($this->sell)(['customer_id' => $customer, 'redeem_points' => 5, 'items' => [['product_variant_id' => $this->coke, 'qty' => '1']], 'payments' => ($this->cash)('20')])
        ->assertUnprocessable()->assertJsonValidationErrors(['redeem_points' => 'At least 10']);
});

it('uses wholesale prices and the group discount for group customers', function () {
    $group = $this->postJson('/api/v1/customer-groups', ['name' => 'Resellers', 'price_tier' => 'wholesale', 'discount_percent' => '5'])->json('data.id');
    $customer = $this->postJson('/api/v1/customers', ['name' => 'Tindahan ni Mang Tonyo', 'customer_group_id' => $group])->json('data.id');
    ($this->openShift)($this->cashier);

    // 10 x 18.00 wholesale = 180.00, less 5% group discount (9.00) = 171.00
    $quote = $this->postJson('/api/v1/pos/quote', ['customer_id' => $customer, 'items' => [['product_variant_id' => $this->coke, 'qty' => '10']]])->assertOk();
    expect($quote->json('data.lines.0.unit_price'))->toBe('18.00')
        ->and($quote->json('data.totals.transaction_discount'))->toBe('9.00')
        ->and($quote->json('data.totals.total'))->toBe('171.00')
        ->and($quote->json('data.discounts.0.type'))->toBe('customer_group')
        ->and($quote->json('data.approvals_missing'))->toBe([]);
});

it('refuses checkout without an open shift, and when stock is short', function () {
    ($this->as)($this->cashier);
    ($this->sell)(['items' => [['product_variant_id' => $this->coke, 'qty' => '1']], 'payments' => ($this->cash)('20')])
        ->assertStatus(409)->assertJsonPath('code', 'NO_OPEN_SHIFT');

    ($this->openShift)($this->cashier);
    ($this->sell)(['items' => [['product_variant_id' => $this->coke, 'qty' => '101']], 'payments' => ($this->cash)('5000')])
        ->assertUnprocessable()->assertJsonValidationErrors(['items.0.qty' => 'Not enough stock']);
    ($this->sell)(['items' => [['product_variant_id' => $this->coke, 'qty' => '1.5']], 'payments' => ($this->cash)('50')])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.qty');

    $this->asTenant($this->tenant, fn () => expect(Sale::query()->count())->toBe(0));
    ($this->sell)(['items' => [['product_variant_id' => $this->coke, 'qty' => '1']], 'payments' => ($this->cash)('20')])
        ->assertCreated()->assertJsonPath('data.invoice_no', '000000001');
});

it('keeps sales private to their cashier and other tenants out', function () {
    ($this->openShift)($this->cashier);
    $id = ($this->sell)(['items' => [['product_variant_id' => $this->coke, 'qty' => '1']], 'payments' => ($this->cash)('20')])->json('data.id');

    $other = $this->createUser($this->tenant, 'cashier');
    ($this->as)($other);
    $this->getJson("/api/v1/sales/{$id}")->assertNotFound();

    ($this->as)($this->owner);
    $this->getJson("/api/v1/sales/{$id}")->assertOk()->assertJsonPath('data.totals.cogs', '14.00');

    ['owner' => $otherOwner, 'branch' => $otherBranch, 'tenant' => $otherTenant] = $this->createTenant();
    usePlan($otherTenant->id, 'business');
    $this->actingAsApi($otherOwner)->withHeader('X-Branch-Id', (string) $otherBranch->id);
    $this->getJson("/api/v1/sales/{$id}")->assertNotFound();
    $this->postJson('/api/v1/pos/quote', ['items' => [['product_variant_id' => $this->coke, 'qty' => '1']]])
        ->assertUnprocessable()->assertJsonValidationErrors('items.0.product_variant_id');
});

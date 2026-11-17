<?php

use App\Models\CashRegister;
use App\Models\PaymentMethod;
use App\Models\Unit;
use Illuminate\Support\Str;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'business');
    $this->tenant->forceFill(['vat_registered' => true])->save();
    $units = $this->asTenant($this->tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    $cash = $this->asTenant($this->tenant, fn () => PaymentMethod::query()->where('type', 'cash')->value('id'));
    $terminal = $this->createTerminal($this->tenant, $this->main);
    $this->asTenant($this->tenant, fn () => CashRegister::query()->create(['branch_id' => $this->main->id, 'terminal_id' => $terminal->id, 'name' => 'Drawer', 'is_active' => true]));

    $this->as = fn ($user) => $this->actingAsApi($user)->withHeader('X-Branch-Id', (string) $this->main->id);
    ($this->as)($this->owner);
    $coke = createStockItem($units['pc'], ['name' => 'Coke', 'sc_pwd_eligible' => false, 'variants' => [['selling_price' => '20']]]);
    $bio = createStockItem($units['pc'], ['name' => 'Biogesic', 'sc_pwd_eligible' => true, 'variants' => [['selling_price' => '5.60']]]);
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true, 'items' => [
        ['product_variant_id' => $coke, 'qty' => '50', 'unit_cost' => '14'],
        ['product_variant_id' => $bio, 'qty' => '50', 'unit_cost' => '3'],
    ]])->assertCreated();
    $this->postJson('/api/v1/cash-sessions', ['client_uuid' => (string) Str::uuid(), 'terminal_id' => $terminal->id, 'opening_float' => '1000'])->assertCreated();

    $sell = fn (array $cart, string $pay) => $this->postJson('/api/v1/pos/sales', $cart + ['client_uuid' => (string) Str::uuid(),
        'payments' => [['payment_method_id' => $cash, 'amount' => $pay]]])->assertCreated()->json('data');

    // A: 3 Coke = 60.00 (VAT 6.43, cost 42.00)
    $a = $sell(['items' => [['product_variant_id' => $coke, 'qty' => '3']]], '60');
    // B: 10 Biogesic to a senior: 56.00 → 50.00 net of VAT − 10.00 = 40.00 VAT-exempt (cost 30.00)
    $sell(['items' => [['product_variant_id' => $bio, 'qty' => '10', 'sc_pwd' => true]], 'sc_pwd' => ['type' => 'senior', 'name' => 'Lola Remedios', 'id_no' => 'SC-123456']], '40');
    // C: voided
    $c = $sell(['items' => [['product_variant_id' => $coke, 'qty' => '1']]], '20');
    $this->postJson("/api/v1/sales/{$c['id']}/void", ['reason' => 'Wrong item'])->assertOk();
    // 1 Coke of A returned to stock: 20.00 (VAT 2.14, cost 14.00 back)
    $this->postJson("/api/v1/sales/{$a['id']}/refunds", ['client_uuid' => (string) Str::uuid(), 'reason' => 'Return',
        'items' => [['sale_item_id' => $a['items'][0]['id'], 'qty' => '1', 'restock' => 'sellable']]])->assertCreated();

    $this->today = now('Asia/Manila')->toDateString();
    $this->report = fn (string $key, array $q = []) => $this->getJson("/api/v1/reports/{$key}?".http_build_query($q + ['from' => $this->today, 'to' => $this->today]));
});

it('reports sales with consistent definitions: net sales, VAT, revenue, cost and profit', function () {
    $totals = ($this->report)('sales')->assertOk()->json('data.totals');

    expect($totals)->toMatchArray([
        'transactions' => 2,              // the voided sale is excluded
        'gross_sales' => '116.00',        // 60.00 + 56.00
        'discounts' => '10.00',           // senior 20% of 50.00
        'sc_pwd_vat' => '6.00',           // VAT removed from the senior's items
        'returns' => '20.00',
        'net_sales' => '80.00',           // 60 + 40 − 20
        'vat' => '4.29',                  // 6.43 − 2.14
        'revenue' => '75.71',
        'cogs' => '58.00',                // 42 + 30 − 14
        'gross_profit' => '17.71',
        'margin' => '23.4',
    ]);

    // The shift's X reading agrees: net sales 80.00, with the 6.00 VAT removed from the senior's items shown.
    $session = $this->getJson('/api/v1/pos/terminals')->json('data.my_session.id');
    expect($this->postJson("/api/v1/cash-sessions/{$session}/x-reading")->assertCreated()->json('data'))
        ->toMatchArray(['net_sales' => '80.00', 'sc_pwd_vat' => '6.00', 'vat_amount' => '4.29', 'vat_exempt_sales' => '50.00']);
});

it('breaks sales down by product, payment method, discount, tax and cashier', function () {
    $products = collect(($this->report)('products')->assertOk()->json('data.rows'))->keyBy('name');
    expect($products['Coke'])->toMatchArray(['qty' => '2.000', 'net_sales' => '40.00', 'vat' => '4.29', 'cogs' => '28.00', 'gross_profit' => '7.71'])
        ->and($products['Biogesic'])->toMatchArray(['qty' => '10.000', 'net_sales' => '40.00', 'vat' => '0.00', 'gross_profit' => '10.00']);

    expect(($this->report)('payments')->json('data.rows.0'))->toMatchArray(['sales_received' => '100.00', 'refunds_paid' => '20.00', 'net_collected' => '80.00']);
    expect(($this->report)('discounts')->json('data.rows.0'))->toMatchArray(['discount' => 'Senior Citizen', 'sales' => 1, 'amount' => '10.00']);
    expect(($this->report)('tax')->json('data.totals'))->toMatchArray(['vatable_sales' => '35.71', 'vat' => '4.29', 'vat_exempt_sales' => '50.00', 'sc_pwd_discount' => '10.00']); // exempt at the price net of VAT, before the SC discount (BIR)
    expect(($this->report)('sc-pwd')->json('data.rows.0'))->toMatchArray(['name' => 'Lola Remedios', 'id_no' => '••••3456', 'discount' => '10.00']);
    expect(($this->report)('cashiers')->json('data.rows.0'))->toMatchArray(['transactions' => 2, 'net_sales' => '100.00', 'voids' => 1, 'void_amount' => '20.00', 'refund_amount' => '20.00']);

    // Sales over time: one row per day of the range, empty days included.
    $week = ($this->report)('sales', ['from' => now('Asia/Manila')->subDays(6)->toDateString()])->json('data.rows');
    expect($week)->toHaveCount(7)->and(end($week)['net_sales'])->toBe('80.00');
});

it('exports CSV and Excel, and hides costs from users who may not see them', function () {
    $csv = ($this->report)('sales', ['format' => 'csv'])->assertOk();
    expect($csv->headers->get('content-type'))->toContain('text/csv')
        ->and($csv->getContent())->toContain('Gross sales')->toContain(',80.00,')->not->toBeEmpty();

    $xlsx = ($this->report)('products', ['format' => 'xlsx'])->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($path, $xlsx->getContent());
    $zip = new ZipArchive;
    expect($zip->open($path))->toBeTrue();
    expect($zip->getFromName('xl/worksheets/sheet1.xml'))->toContain('Biogesic')->toContain('<v>40.00</v>');
    $zip->close();

    // A branch manager sees sales but not costs, and cannot open profit reports by cost.
    $manager = $this->createUser($this->tenant, 'branch_manager');
    ($this->as)($manager);
    $keys = array_column(($this->report)('sales')->assertOk()->json('data.columns'), 'key');
    expect($keys)->toContain('net_sales')->not->toContain('cogs')->not->toContain('gross_profit');

    // A cashier sees no reports at all.
    ($this->as)($this->createUser($this->tenant, 'cashier'));
    ($this->report)('sales')->assertForbidden();
});

it('respects plan features and branch access, and fills the dashboard', function () {
    $dash = $this->getJson('/api/v1/reports/dashboard')->assertOk();
    expect($dash->json('data.today'))->toMatchArray(['net_sales' => '80.00', 'transactions' => 2, 'cash_collected' => '80.00'])
        ->and($dash->json('data.series'))->toHaveCount(30)
        ->and($dash->json('data.top_products.0.name'))->toBeIn(['Coke', 'Biogesic']);

    usePlan($this->tenant->id, 'test-limited');
    ($this->report)('products')->assertStatus(402)->assertJsonPath('code', 'FEATURE_NOT_AVAILABLE');
    ($this->report)('sales')->assertOk();

    ['branch' => $foreign] = $this->createTenant();
    ($this->report)('sales', ['branch_id' => $foreign->id])->assertForbidden();
});

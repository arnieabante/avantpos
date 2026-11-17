<?php

use App\Models\CashRegister;
use App\Models\InventoryBalance;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\Unit;
use Illuminate\Support\Str;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'business');
    $this->tenant->forceFill(['vat_registered' => true])->save();
    $units = $this->asTenant($this->tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    $this->cash = $this->asTenant($this->tenant, fn () => PaymentMethod::query()->where('type', 'cash')->value('id'));

    $this->terminal = $this->createTerminal($this->tenant, $this->main);
    $this->asTenant($this->tenant, fn () => CashRegister::query()->create(['branch_id' => $this->main->id, 'terminal_id' => $this->terminal->id, 'name' => 'Drawer', 'is_active' => true]));

    $this->as = fn ($user) => $this->actingAsApi($user)->withHeader('X-Branch-Id', (string) $this->main->id);
    ($this->as)($this->owner);
    $this->coke = createStockItem($units['pc'], ['name' => 'Coke', 'variants' => [['selling_price' => '20', 'barcodes' => ['4800000000017']]]]);
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true, 'items' => [
        ['product_variant_id' => $this->coke, 'qty' => '10', 'unit_cost' => '14'],
    ]])->assertCreated();

    $this->cashier = $this->createUser($this->tenant, 'cashier');
    ($this->as)($this->cashier);
    $this->session = $this->postJson('/api/v1/cash-sessions', ['client_uuid' => (string) Str::uuid(), 'terminal_id' => $this->terminal->id, 'opening_float' => '1000'])
        ->assertCreated()->json('data.id');

    $this->offline = fn (string $invoice, string $qty = '1', string $price = '20.00', array $extra = []) => $extra + [
        'client_uuid' => (string) Str::uuid(),
        'invoice_no' => $invoice,
        'sold_at' => now()->subMinutes(30)->toIso8601String(),
        'cash_register_session_id' => $this->session,
        'total' => bcmul($qty, $price, 2),
        'items' => [['product_variant_id' => $this->coke, 'product_unit_id' => null, 'qty' => $qty, 'unit_price' => $price]],
        'payments' => [['payment_method_id' => $this->cash, 'amount' => bcmul($qty, $price, 2)]],
    ];
    $this->sync = fn (array $sales, ?string $batch = null) => $this->postJson('/api/v1/pos/sync', ['client_batch_uuid' => $batch ?? (string) Str::uuid(), 'sales' => $sales]);
    $this->stock = fn () => $this->asTenant($this->tenant, fn () => InventoryBalance::query()->where('product_variant_id', $this->coke)->value('qty_on_hand'));
});

it('gives the device the catalog, the VAT rate and the next invoice number', function () {
    $setup = $this->getJson('/api/v1/pos/setup')->assertOk();
    expect($setup->json('data.next_invoice_no'))->toBe('000000001')
        ->and($setup->json('data.vat_rate'))->not->toBeNull();

    $catalog = $this->getJson('/api/v1/pos/catalog')->assertOk();
    expect($catalog->json('data.items.0'))->toMatchArray(['product_variant_id' => $this->coke, 'price' => '20.00'])
        ->and($catalog->json('data.items.0.barcodes'))->toBe([['code' => '4800000000017', 'unit_id' => null]]);

    $version = $catalog->json('data.version');
    $this->getJson("/api/v1/pos/catalog?version={$version}")->assertOk()->assertJsonPath('data.unchanged', true);
});

it('records offline sales with their device invoice numbers and time, once', function () {
    $batch = (string) Str::uuid();
    $sales = [($this->offline)('000000002', '2'), ($this->offline)('000000001')];

    $result = ($this->sync)($sales, $batch)->assertOk();
    expect(collect($result->json('data.results'))->pluck('status')->all())->toBe(['accepted', 'accepted'])
        ->and($result->json('data.next_invoice_no'))->toBe('000000003');

    $this->asTenant($this->tenant, function () {
        $sale = Sale::query()->where('invoice_no', '000000002')->firstOrFail();
        expect($sale->origin)->toBe('offline')
            ->and($sale->total_amount)->toBe('40.00')
            ->and($sale->vat_amount)->toBe('4.29')
            ->and($sale->sold_at->lessThan(now()->subMinutes(20)))->toBeTrue();
    });
    expect(($this->stock)())->toBe('7.000');

    // Re-sent (e.g. the reply was lost): nothing is recorded twice.
    $again = ($this->sync)($sales, $batch)->assertOk();
    expect(collect($again->json('data.results'))->pluck('status')->all())->toBe(['duplicate', 'duplicate']);
    expect(($this->stock)())->toBe('7.000');

    // The next online sale continues the series.
    $online = $this->postJson('/api/v1/pos/sales', ['client_uuid' => (string) Str::uuid(),
        'items' => [['product_variant_id' => $this->coke, 'qty' => '1']], 'payments' => [['payment_method_id' => $this->cash, 'amount' => '20']]])->assertCreated();
    expect($online->json('data.invoice_no'))->toBe('000000003');
});

it('never drops an offline sale but flags what does not match', function () {
    $this->postJson('/api/v1/pos/sales', ['client_uuid' => (string) Str::uuid(),
        'items' => [['product_variant_id' => $this->coke, 'qty' => '1']], 'payments' => [['payment_method_id' => $this->cash, 'amount' => '20']]])->assertCreated(); // 000000001 online

    $result = ($this->sync)([
        ($this->offline)('000000001', '1', '18.00'),          // number taken + old price
        ($this->offline)('000000005', '20'),                   // gap (3-4 never arrived) + oversold (8 left)
    ])->assertOk()->json('data.results');

    expect($result[0])->toMatchArray(['status' => 'flagged', 'invoice_no' => '000000002'])
        ->and($result[0]['issues'])->toEqualCanonicalizing(['renumbered', 'price_changed'])
        ->and($result[1])->toMatchArray(['status' => 'flagged', 'invoice_no' => '000000005'])
        ->and($result[1]['issues'])->toEqualCanonicalizing(['invoice_gap', 'negative_stock']);
    expect(($this->stock)())->toBe('-12.000');

    // A manager reviews them; the cashier cannot.
    $flagged = $result[0]['sale_id'];
    $this->postJson("/api/v1/sales/{$flagged}/review-sync", ['notes' => 'Old price honoured'])->assertForbidden();
    ($this->as)($this->owner);
    $this->getJson('/api/v1/sales?sync_status=flagged')->assertOk()->assertJsonCount(2, 'data');
    $this->postJson("/api/v1/sales/{$flagged}/review-sync", ['notes' => 'Old price honoured'])->assertOk()->assertJsonPath('data.sync_status', 'reviewed');
    $this->getJson('/api/v1/sales?sync_status=flagged')->assertJsonCount(1, 'data');
});

it('records sales uploaded after the shift was closed, flagged, and rejects other shifts', function () {
    $sale = ($this->offline)('000000001');
    $this->postJson("/api/v1/cash-sessions/{$this->session}/close", ['counted_cash' => '1020'])->assertOk();

    $result = ($this->sync)([$sale])->assertOk()->json('data.results.0');
    expect($result['status'])->toBe('flagged')->and($result['issues'])->toBe(['shift_closed']);

    $other = $this->createUser($this->tenant, 'cashier');
    ($this->as)($other);
    $rejected = ($this->sync)([($this->offline)('000000009')])->assertOk()->json('data.results.0');
    expect($rejected['status'])->toBe('rejected');
});

<?php

use App\Models\CashRegister;
use App\Models\InventoryBalance;
use App\Models\PaymentMethod;
use App\Models\Sale;
use App\Models\Unit;
use App\Services\Inventory\InventoryVerifier;
use App\Support\Money\Money;
use Illuminate\Support\Str;

/*
| POS transaction integrity over a whole selling day: many sales (cash,
| e-wallet, split), a void, partial refunds, cash in / payouts, close and Z.
| The test keeps its own books from the API responses and checks that the
| sale lines, shift drawer, X/Z readings, reports, invoice series and stock
| all agree to the centavo.
*/

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'enterprise');
    $this->tenant->forceFill(['vat_registered' => true])->save();
    $units = $this->asTenant($this->tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    $this->methods = $this->asTenant($this->tenant, fn () => PaymentMethod::query()->pluck('id', 'type')->all());
    $this->terminal = $this->createTerminal($this->tenant, $this->main);
    $this->asTenant($this->tenant, fn () => CashRegister::query()->create(['branch_id' => $this->main->id, 'terminal_id' => $this->terminal->id, 'name' => 'Drawer', 'is_active' => true]));
    $this->actingAsApi($this->owner)->withHeader('X-Branch-Id', (string) $this->main->id);

    // VATable parts at awkward prices, plus a VAT-exempt item.
    $this->items = [
        createStockItem($units['pc'], ['name' => 'Engine Oil', 'variants' => [['selling_price' => '379.75', 'cost_price' => '290']]]),
        createStockItem($units['pc'], ['name' => 'Spark Plug', 'variants' => [['selling_price' => '183.33', 'cost_price' => '125']]]),
        createStockItem($units['pc'], ['name' => 'Gear Oil', 'variants' => [['selling_price' => '159.99', 'cost_price' => '115']]]),
        createStockItem($units['pc'], ['name' => 'Repair Manual', 'tax_class' => 'vat_exempt', 'variants' => [['selling_price' => '250', 'cost_price' => '150']]]),
    ];
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true,
        'items' => array_map(fn ($v) => ['product_variant_id' => $v, 'qty' => '500', 'unit_cost' => '100'], $this->items)])->assertCreated();
});

it('reconciles a full selling day to the centavo', function () {
    $session = $this->postJson('/api/v1/cash-sessions', ['client_uuid' => (string) Str::uuid(), 'terminal_id' => $this->terminal->id, 'opening_float' => '2000'])
        ->assertCreated()->json('data.id');

    mt_srand(20261117);
    $sales = [];
    $sold = array_fill_keys($this->items, 0);
    for ($n = 0; $n < 24; $n++) {
        $lines = [];
        foreach ((array) array_rand(array_flip($this->items), mt_rand(1, 3)) as $variant) {
            $qty = mt_rand(1, 4);
            $lines[] = ['product_variant_id' => $variant, 'qty' => (string) $qty];
        }
        $total = $this->postJson('/api/v1/pos/quote', ['items' => $lines])->assertOk()->json('data.totals.total');
        $payments = match ($n % 3) {
            0 => [['payment_method_id' => $this->methods['cash'], 'amount' => (string) Money::of($total)->plus('100')]], // change given
            1 => [['payment_method_id' => $this->methods['gcash'], 'amount' => $total, 'reference_no' => "G-{$n}"]],
            default => [ // split: cash part + card for the rest
                ['payment_method_id' => $this->methods['cash'], 'amount' => '100.00'],
                ['payment_method_id' => $this->methods['card'], 'amount' => (string) Money::of($total)->minus('100'), 'reference_no' => "C-{$n}"],
            ],
        };
        $uuid = (string) Str::uuid();
        $sale = $this->postJson('/api/v1/pos/sales', ['client_uuid' => $uuid, 'items' => $lines, 'payments' => $payments])->assertCreated()->json('data');
        // A network retry of the same checkout returns the same sale.
        $this->postJson('/api/v1/pos/sales', ['client_uuid' => $uuid, 'items' => $lines, 'payments' => $payments])->assertOk()->assertJsonPath('data.id', $sale['id']);

        // Each sale adds up: lines = total, VAT parts = total, payments = total.
        $t = $sale['totals'];
        expect($t['total'])->toBe($total)
            ->and((string) Money::sum(array_column($sale['items'], 'line_total')))->toBe($t['total'])
            ->and((string) Money::sum([$t['vatable_sales'], $t['vat_amount'], $t['vat_exempt_sales'] ?? '0', $t['zero_rated_sales'] ?? '0']))->toBe($t['total'])
            ->and((string) Money::sum(array_column($sale['payments'], 'amount')))->toBe($t['total']);
        $sales[] = $sale;
        foreach ($lines as $l) {
            $sold[$l['product_variant_id']] += (int) $l['qty'];
        }
    }

    // A void (same shift) and two partial refunds (one restocked, one damaged).
    $voided = $sales[5];
    $this->postJson("/api/v1/sales/{$voided['id']}/void", ['reason' => 'Wrong items'])->assertOk();
    foreach ($voided['items'] as $item) {
        $sold[$item['product_variant_id']] -= (int) $item['qty'];
    }
    $refunds = [];
    foreach ([[$sales[0], 'sellable'], [$sales[3], 'damaged']] as [$sale, $restock]) {
        $item = $sale['items'][0];
        $refund = $this->postJson("/api/v1/sales/{$sale['id']}/refunds", ['client_uuid' => (string) Str::uuid(), 'reason' => 'Returned',
            'items' => [['sale_item_id' => $item['id'], 'qty' => '1', 'restock' => $restock]]])->assertCreated()->json('data.refund');
        $refunds[] = $refund;
        if ($restock === 'sellable') {
            $sold[$item['product_variant_id']] -= 1;
        }
    }

    // Drawer movements.
    $move = fn (string $type, string $amount) => $this->postJson("/api/v1/cash-sessions/{$session}/movements", ['client_uuid' => (string) Str::uuid(), 'type' => $type, 'amount' => $amount, 'reason' => 'Test'])->assertOk();
    $move('cash_in', '500');
    $move('payout', '235.50');

    // Our own books.
    $valid = array_values(array_filter($sales, fn ($s) => $s['id'] !== $voided['id']));
    $refundTotal = Money::sum(array_map(fn ($r) => $r['total'], $refunds));
    $net = Money::sum(array_map(fn ($s) => $s['totals']['total'], $valid))->minus($refundTotal);
    $cashIn = Money::sum(array_merge(...array_map(fn ($s) => array_map(fn ($p) => $p['amount'], array_filter($s['payments'], fn ($p) => $p['type'] === 'cash')), $valid)));
    $cashRefunds = Money::sum(array_merge(...array_map(fn ($r) => array_map(fn ($p) => $p['amount'], array_filter($r['payments'] ?? [], fn ($p) => ($p['type'] ?? 'cash') === 'cash')), $refunds)));
    $expectedCash = Money::of('2000')->plus($cashIn)->minus($cashRefunds)->plus('500')->minus('235.50');

    // Shift drawer.
    $summary = $this->getJson("/api/v1/cash-sessions/{$session}")->assertOk()->json('data.summary');
    expect($summary)->toMatchArray(['cash_sales' => (string) $cashIn, 'expected_cash' => (string) $expectedCash, 'sales_count' => 23, 'cash_in' => '500.00', 'payouts' => '235.50']);
    expect((string) Money::sum(array_column($summary['payments_by_method'], 'amount')))->toBe((string) $net);

    // X reading = our books; closing with the exact count leaves no variance.
    $x = $this->postJson("/api/v1/cash-sessions/{$session}/x-reading")->assertCreated()->json('data');
    expect($x)->toMatchArray(['net_sales' => (string) $net, 'transaction_count' => 23, 'void_count' => 1, 'refund_count' => 2, 'refund_amount' => (string) $refundTotal]);
    $closed = $this->postJson("/api/v1/cash-sessions/{$session}/close", ['counted_cash' => (string) $expectedCash])->assertOk()->json('data');
    expect($closed['summary']['expected_cash'] ?? (string) $expectedCash)->toBe((string) $expectedCash);
    expect($closed['variance'] ?? '0.00')->toBe('0.00');

    // Z reading = X = sales report = VAT report.
    $z = $this->postJson('/api/v1/pos/z-readings', ['terminal_id' => $this->terminal->id])->assertCreated()->json('data');
    expect($z)->toMatchArray(['net_sales' => (string) $net, 'beginning_invoice_no' => '000000001', 'ending_invoice_no' => '000000024',
        'accumulated_grand_total_before' => '0.00', 'accumulated_grand_total_after' => (string) $net]);
    expect((string) Money::sum([$z['vatable_sales'], $z['vat_amount'], $z['vat_exempt_sales'], $z['zero_rated_sales'] ?? '0']))->toBe((string) $net);
    $today = now('Asia/Manila')->toDateString();
    $report = $this->getJson("/api/v1/reports/sales?from={$today}&to={$today}")->assertOk()->json('data.totals');
    expect($report)->toMatchArray(['net_sales' => (string) $net, 'vat' => $z['vat_amount'], 'transactions' => 23, 'returns' => (string) $refundTotal]);
    $tax = $this->getJson("/api/v1/reports/tax?from={$today}&to={$today}")->assertOk()->json('data.totals');
    expect($tax['vatable_sales'])->toBe($z['vatable_sales'])->and($tax['vat'])->toBe($z['vat_amount']);

    // Invoice series: gapless 1..24 including the voided sale, never reused.
    $numbers = $this->asTenant($this->tenant, fn () => Sale::query()->orderBy('invoice_no')->pluck('invoice_no')->all());
    expect($numbers)->toBe(array_map(fn ($i) => str_pad((string) $i, 9, '0', STR_PAD_LEFT), range(1, 24)));

    // Stock: opening 500 each minus what was really sold; ledger fully consistent.
    $this->asTenant($this->tenant, function () use ($sold) {
        foreach ($sold as $variant => $qty) {
            expect(InventoryBalance::query()->where('product_variant_id', $variant)->value('qty_on_hand'))->toBe(number_format(500 - $qty, 3, '.', ''));
        }
    });
    expect(app(InventoryVerifier::class)->verify($this->tenant->id))->toBe([]);
});

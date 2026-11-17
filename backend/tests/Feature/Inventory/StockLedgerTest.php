<?php

use App\Exceptions\DomainException;
use App\Models\InventoryBalance;
use App\Models\InventoryLayer;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Services\Inventory\StockLedger;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'business');
    $this->units = $this->asTenant($this->tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    $this->actingAsApi($this->owner);

    // Runs $callback(ledger, variant) in the tenant, inside a transaction.
    $this->ledger = function (int $variantId, callable $callback) {
        return app(TenantContext::class)->runAs($this->tenant, fn () => DB::transaction(
            fn () => $callback(app(StockLedger::class), ProductVariant::query()->with('product.baseUnit')->findOrFail($variantId))
        ));
    };
    $this->balance = fn (int $variantId) => $this->asTenant($this->tenant, fn () => InventoryBalance::query()
        ->where('branch_id', $this->main->id)->where('product_variant_id', $variantId)->first());
});

it('consumes cost layers first-in first-out and records the exact cost', function () {
    $id = createStockItem($this->units['pc']);
    $branch = $this->main->id;

    ($this->ledger)($id, function (StockLedger $ledger, ProductVariant $v) use ($branch) {
        $ledger->receive($branch, $v, '10', '10', 'opening');
        $ledger->receive($branch, $v, '10', '12', 'adjustment_in');
    });

    $out = ($this->ledger)($id, fn (StockLedger $ledger, ProductVariant $v) => $ledger->issue($branch, $v, '15', 'adjustment_out'));

    // 10 x 10.00 + 5 x 12.00 = 160.00
    expect($out->qty)->toBe('-15.000')
        ->and($out->total_cost)->toBe('-160.00')
        ->and($out->unit_cost)->toBe('10.6667')
        ->and($out->balance_after)->toBe('5.000');

    $balance = ($this->balance)($id);
    expect($balance->qty_on_hand)->toBe('5.000')->and($balance->total_cost)->toBe('60.00');

    $this->asTenant($this->tenant, function () use ($out) {
        expect($out->layers()->count())->toBe(2)
            ->and($out->layers()->orderBy('id')->pluck('qty')->all())->toBe(['10.000', '5.000']);
    });
});

it('uses the earliest expiry first for batch-tracked products', function () {
    $id = createStockItem($this->units['pc'], ['track_expiry' => true]);
    $branch = $this->main->id;

    ($this->ledger)($id, function (StockLedger $ledger, ProductVariant $v) use ($branch) {
        $ledger->receive($branch, $v, '5', '10', 'opening', ['batch_no' => 'LATE', 'expiry_date' => '2027-12-31']);
        $ledger->receive($branch, $v, '5', '11', 'adjustment_in', ['batch_no' => 'EARLY', 'expiry_date' => '2027-01-31']);
    });

    $out = ($this->ledger)($id, fn (StockLedger $ledger, ProductVariant $v) => $ledger->issue($branch, $v, '3', 'adjustment_out'));
    expect($out->total_cost)->toBe('-33.00');

    $remaining = $this->asTenant($this->tenant, fn () => InventoryLayer::query()->where('product_variant_id', $id)->pluck('qty_remaining', 'batch_no')->all());
    expect($remaining)->toBe(['LATE' => '5.000', 'EARLY' => '2.000']);
});

it('refuses to issue more than is on hand and writes nothing', function () {
    $id = createStockItem($this->units['pc']);
    $branch = $this->main->id;
    ($this->ledger)($id, fn (StockLedger $l, ProductVariant $v) => $l->receive($branch, $v, '4', '10', 'opening'));

    expect(fn () => ($this->ledger)($id, fn (StockLedger $l, ProductVariant $v) => $l->issue($branch, $v, '5', 'adjustment_out')))
        ->toThrow(DomainException::class, '4 available, 5 needed');

    expect(($this->balance)($id)->qty_on_hand)->toBe('4.000');
    $this->asTenant($this->tenant, fn () => expect(StockMovement::query()->where('product_variant_id', $id)->count())->toBe(1));
});

it('allows negative stock only when asked, and settles the deficit on the next receipt', function () {
    $id = createStockItem($this->units['pc'], ['allow_negative_stock' => true]);
    $branch = $this->main->id;

    ($this->ledger)($id, function (StockLedger $l, ProductVariant $v) use ($branch) {
        $l->receive($branch, $v, '2', '10', 'opening');
        $out = $l->issue($branch, $v, '5', 'sale', ['allow_negative' => true]);
        // 2 from the layer + 3 at the latest known cost (10.00)
        expect($out->total_cost)->toBe('-50.00')->and($out->balance_after)->toBe('-3.000');
    });
    expect(($this->balance)($id)->total_cost)->toBe('0.00');

    ($this->ledger)($id, fn (StockLedger $l, ProductVariant $v) => $l->receive($branch, $v, '10', '12', 'purchase_receipt'));

    $balance = ($this->balance)($id);
    $layer = $this->asTenant($this->tenant, fn () => InventoryLayer::query()->where('product_variant_id', $id)->latest('id')->first());
    expect($balance->qty_on_hand)->toBe('7.000')
        ->and($layer->qty_received)->toBe('10.000')
        ->and($layer->qty_remaining)->toBe('7.000')
        ->and($balance->total_cost)->toBe('84.00');
});

it('rejects fractions of whole units but keeps exact decimals for weighed items', function () {
    $id = createStockItem($this->units['pc']);
    $branch = $this->main->id;

    expect(fn () => ($this->ledger)($id, fn (StockLedger $l, ProductVariant $v) => $l->receive($branch, $v, '1.5', '10', 'opening')))
        ->toThrow(DomainException::class, 'whole Pieces');

    $kg = createStockItem($this->units['kg']);
    ($this->ledger)($kg, fn (StockLedger $l, ProductVariant $v) => $l->receive($branch, $v, '1.250', '80', 'opening'));
    expect(($this->balance)($kg)->qty_on_hand)->toBe('1.250')->and(($this->balance)($kg)->total_cost)->toBe('100.00');
    // (The "must run in a transaction" guard cannot be exercised here: every test already runs in one.)
});

it('keeps the ledger append-only and refuses items that do not track stock', function () {
    $id = createStockItem($this->units['pc']);
    $branch = $this->main->id;
    $movement = ($this->ledger)($id, fn (StockLedger $l, ProductVariant $v) => $l->receive($branch, $v, '1', '1', 'opening'));

    $this->asTenant($this->tenant, function () use ($movement) {
        expect(fn () => $movement->forceFill(['qty' => '99'])->save())->toThrow(LogicException::class, 'append-only');
        expect(fn () => $movement->delete())->toThrow(LogicException::class, 'append-only');
    });

    $untracked = createStockItem($this->units['pc'], ['track_inventory' => false]);
    expect(fn () => ($this->ledger)($untracked, fn (StockLedger $l, ProductVariant $v) => $l->receive($branch, $v, '1', '1', 'opening')))
        ->toThrow(DomainException::class, 'does not track stock');
});

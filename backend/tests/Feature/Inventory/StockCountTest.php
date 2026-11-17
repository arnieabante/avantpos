<?php

use App\Models\InventoryBalance;
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
    $this->actingAsApi($this->owner)->withHeader('X-Branch-Id', (string) $this->main->id);

    $this->a = createStockItem($this->units['pc']);
    $this->b = createStockItem($this->units['pc']);
    $this->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'post' => true, 'items' => [
        ['product_variant_id' => $this->a, 'qty' => '10', 'unit_cost' => '5'],
        ['product_variant_id' => $this->b, 'qty' => '4', 'unit_cost' => '20'],
    ]])->assertCreated();

    $this->onHand = fn (int $variantId) => $this->asTenant($this->tenant, fn () => InventoryBalance::query()
        ->where('branch_id', $this->main->id)->where('product_variant_id', $variantId)->value('qty_on_hand'));
    $this->itemIds = fn (int $countId) => collect($this->getJson("/api/v1/stock-counts/{$countId}/items")->json('data'))
        ->mapWithKeys(fn ($row) => [$row['variant']['id'] => $row['id']]);
});

it('posts count variances and leaves uncounted items alone', function () {
    $count = $this->postJson('/api/v1/stock-counts', ['notes' => 'Month-end'])->assertCreated();
    expect($count->json('data.status'))->toBe('counting')
        ->and($count->json('data.number'))->toStartWith('SC-')
        ->and($count->json('data.summary.items'))->toBe(2);
    $id = $count->json('data.id');
    $items = ($this->itemIds)($id);

    $this->putJson("/api/v1/stock-counts/{$id}/entries", ['entries' => [$items[$this->a] => '7']])->assertOk()
        ->assertJsonPath('data.summary.counted', 1)
        ->assertJsonPath('data.summary.variances', 1);

    $row = collect($this->getJson("/api/v1/stock-counts/{$id}/items?filter=variance")->json('data'))->first();
    expect($row['system_qty'])->toBe('10.000')->and($row['counted_qty'])->toBe('7.000')->and($row['variance_qty'])->toBe('-3.000');

    $this->postJson("/api/v1/stock-counts/{$id}/finalize")->assertOk()->assertJsonPath('data.status', 'finalized');

    expect(($this->onHand)($this->a))->toBe('7.000')->and(($this->onHand)($this->b))->toBe('4.000');
    $this->asTenant($this->tenant, fn () => expect(StockMovement::query()->where('type', 'count_variance')->value('total_cost'))->toBe('-15.00'));

    $this->putJson("/api/v1/stock-counts/{$id}/entries", ['entries' => [$items[$this->b] => '1']])->assertStatus(409);
});

it('measures the variance when the item is counted, so later sales are not counted as missing', function () {
    $id = $this->postJson('/api/v1/stock-counts')->json('data.id');
    $items = ($this->itemIds)($id);

    // A sale of 2 happens before the shelf is counted...
    $sell = fn (string $qty) => app(TenantContext::class)->runAs($this->tenant, fn () => DB::transaction(fn () => app(StockLedger::class)
        ->issue($this->main->id, ProductVariant::query()->with('product')->findOrFail($this->a), $qty, 'sale')));
    $sell('2');

    // ...so the shelf shows 8: no variance.
    $this->putJson("/api/v1/stock-counts/{$id}/entries", ['entries' => [$items[$this->a] => '8']])->assertOk()
        ->assertJsonPath('data.summary.variances', 0);

    // Another sale after counting, before finalizing: still no variance.
    $sell('1');
    $this->postJson("/api/v1/stock-counts/{$id}/finalize")->assertOk();
    expect(($this->onHand)($this->a))->toBe('7.000');
});

it('adds found stock at the current cost, counts per category and validates entries', function () {
    $id = $this->postJson('/api/v1/stock-counts')->json('data.id');
    $items = ($this->itemIds)($id);

    $this->putJson("/api/v1/stock-counts/{$id}/entries", ['entries' => [$items[$this->b] => '-1']])
        ->assertUnprocessable()->assertJsonValidationErrors("entries.{$items[$this->b]}");
    $this->putJson("/api/v1/stock-counts/{$id}/entries", ['entries' => [$items[$this->b] => '2.5']])
        ->assertUnprocessable()->assertJsonValidationErrors("entries.{$items[$this->b]}");
    $this->putJson("/api/v1/stock-counts/{$id}/entries", ['entries' => [999999 => '1']])->assertUnprocessable();

    $this->putJson("/api/v1/stock-counts/{$id}/entries", ['entries' => [$items[$this->b] => '6']])->assertOk();
    $this->postJson("/api/v1/stock-counts/{$id}/finalize")->assertOk();

    $balance = $this->asTenant($this->tenant, fn () => InventoryBalance::query()->where('product_variant_id', $this->b)->first());
    expect($balance->qty_on_hand)->toBe('6.000')->and($balance->total_cost)->toBe('120.00');

    $category = $this->postJson('/api/v1/product-categories', ['name' => 'Drinks'])->json('data.id');
    $this->postJson('/api/v1/stock-counts', ['category_id' => $category])
        ->assertUnprocessable()->assertJsonValidationErrors('category_id');
});

it('refuses to finalize an empty count and allows cancelling', function () {
    $id = $this->postJson('/api/v1/stock-counts')->json('data.id');
    $this->postJson("/api/v1/stock-counts/{$id}/finalize")->assertUnprocessable()->assertJsonPath('code', 'COUNT_EMPTY');
    $this->postJson("/api/v1/stock-counts/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
    $this->postJson("/api/v1/stock-counts/{$id}/finalize")->assertStatus(409);
});

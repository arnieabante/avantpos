<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Unit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    $this->units = $this->asTenant($this->tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    $this->actingAsApi($this->owner);
});

function productPayload(array $units, array $overrides = []): array
{
    return array_replace([
        'name' => 'Coke Mismo 290ml',
        'base_unit_id' => $units['pc'],
        'type' => 'standard',
        'tax_class' => 'vatable',
        'track_inventory' => true,
        'variants' => [[
            'sku' => 'COKE-290',
            'cost_price' => '14.5000',
            'selling_price' => '20',
            'wholesale_price' => '18.50',
            'reorder_level' => '24',
            'barcodes' => ['4801981118502'],
            'units' => [[
                'unit_id' => $units['box'],
                'conversion_factor' => '12',
                'selling_price' => '228',
                'barcodes' => ['14801981118509'],
            ]],
        ]],
    ], $overrides);
}

function plan(int $tenantId, string $code): void
{
    Subscription::withoutTenantScope()->where('tenant_id', $tenantId)
        ->update(['subscription_plan_id' => SubscriptionPlan::query()->where('code', $code)->value('id')]);
}

/** Record a fake stock movement so the variant "has history". */
function giveHistory(int $tenantId, int $branchId, int $variantId): void
{
    DB::table('stock_movements')->insert([
        'tenant_id' => $tenantId, 'branch_id' => $branchId, 'product_variant_id' => $variantId, 'type' => 'opening',
        'qty' => '10.000', 'unit_cost' => '10.0000', 'total_cost' => '100.00', 'balance_after' => '10.000', 'occurred_at' => now(),
    ]);
}

it('creates a product with a selling unit and barcodes, keeping decimals exact', function () {
    $response = $this->postJson('/api/v1/products', productPayload($this->units))->assertCreated();

    $variant = $response->json('data.variants.0');
    expect($variant['sku'])->toBe('COKE-290')
        ->and($variant['selling_price'])->toBe('20.00')
        ->and($variant['wholesale_price'])->toBe('18.50')
        ->and($variant['cost_price'])->toBe('14.5000')
        ->and($variant['reorder_level'])->toBe('24.000')
        ->and($variant['is_default'])->toBeTrue()
        ->and($variant['barcodes'])->toBe(['4801981118502'])
        ->and($variant['units'][0]['conversion_factor'])->toBe('12.0000')
        ->and($variant['units'][0]['barcodes'])->toBe(['14801981118509']);

    expect(AuditLog::query()->where('event', 'product.created')->exists())->toBeTrue();
});

it('generates a SKU when none is given and requires names for multiple variants', function () {
    $sku = $this->postJson('/api/v1/products', productPayload($this->units, ['name' => 'Chippy BBQ', 'variants' => [['selling_price' => '10']]]))
        ->assertCreated()->json('data.variants.0.sku');
    expect($sku)->toMatch('/^CHI-[A-Z0-9]{6}$/');

    $this->postJson('/api/v1/products', productPayload($this->units, [
        'name' => 'Shirt',
        'variants' => [['name' => 'Small', 'selling_price' => '199'], ['name' => '', 'selling_price' => '199'], ['name' => 'small', 'selling_price' => '199']],
    ]))->assertUnprocessable()->assertJsonValidationErrors(['variants.1.name', 'variants.2.name']);
});

it('keeps SKUs and barcodes unique within the business', function () {
    $this->postJson('/api/v1/products', productPayload($this->units))->assertCreated();

    $this->postJson('/api/v1/products', productPayload($this->units, ['name' => 'Copy']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['variants.0.sku', 'variants.0.barcodes.0', 'variants.0.units.0.barcodes.0']);

    // Another business may use the same codes.
    ['owner' => $other, 'tenant' => $otherTenant] = $this->createTenant();
    $otherUnits = $this->asTenant($otherTenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    $this->actingAsApi($other)->postJson('/api/v1/products', productPayload($otherUnits))->assertCreated();
});

it('rejects the base unit as a selling unit and duplicate codes in one product', function () {
    $payload = productPayload($this->units);
    $payload['variants'][0]['units'][] = ['unit_id' => $this->units['pc'], 'conversion_factor' => '1'];
    $payload['variants'][0]['units'][0]['barcodes'] = ['4801981118502'];

    $this->postJson('/api/v1/products', $payload)
        ->assertUnprocessable()->assertJsonValidationErrors(['variants.0.units.1.unit_id', 'variants.0.units.0.barcodes.0']);
});

it('updates variants: changes, adds and removes (only without history)', function () {
    $product = $this->postJson('/api/v1/products', productPayload($this->units, [
        'name' => 'Polo Shirt',
        'variants' => [
            ['name' => 'Small', 'sku' => 'POLO-S', 'selling_price' => '299'],
            ['name' => 'Large', 'sku' => 'POLO-L', 'selling_price' => '299'],
        ],
    ]))->assertCreated()->json('data');
    [$small, $large] = $product['variants'];

    giveHistory($this->tenant->id, $this->main->id, $small['id']);

    // Removing the variant with history is refused.
    $this->putJson("/api/v1/products/{$product['id']}", productPayload($this->units, [
        'name' => 'Polo Shirt',
        'variants' => [['id' => $large['id'], 'name' => 'Large', 'selling_price' => '299']],
    ]))->assertUnprocessable()->assertJsonPath('code', 'VARIANT_HAS_HISTORY');

    // Removing the one without history works, and its SKU can be reused right away.
    $this->putJson("/api/v1/products/{$product['id']}", productPayload($this->units, [
        'name' => 'Polo Shirt',
        'variants' => [
            ['id' => $small['id'], 'name' => 'Small', 'sku' => 'POLO-S', 'selling_price' => '299'],
            ['name' => 'XL', 'sku' => 'POLO-L', 'selling_price' => '349'],
        ],
    ]))->assertOk()->assertJsonCount(2, 'data.variants')->assertJsonPath('data.variants.1.sku', 'POLO-L');
});

it('locks type, base unit and tracking once there is history', function () {
    $product = $this->postJson('/api/v1/products', productPayload($this->units))->json('data');
    giveHistory($this->tenant->id, $this->main->id, $product['variants'][0]['id']);

    $payload = productPayload($this->units, ['base_unit_id' => $this->units['pack']]);
    $payload['variants'][0]['id'] = $product['variants'][0]['id'];

    $this->putJson("/api/v1/products/{$product['id']}", $payload)->assertUnprocessable()->assertJsonPath('code', 'PRODUCT_HAS_HISTORY');
});

it('needs prices.manage to change existing prices but not to set initial prices', function () {
    $manager = $this->createUser($this->tenant, 'inventory_manager'); // products.manage, no prices.manage

    $product = $this->actingAsApi($manager)->postJson('/api/v1/products', productPayload($this->units))->assertCreated()->json('data');
    $payload = productPayload($this->units);
    $payload['variants'][0]['id'] = $product['variants'][0]['id'];

    // Unchanged prices ("20" == "20.00"): fine.
    $this->putJson("/api/v1/products/{$product['id']}", $payload)->assertOk();

    $payload['variants'][0]['selling_price'] = '21';
    $this->putJson("/api/v1/products/{$product['id']}", $payload)->assertForbidden()->assertJsonPath('code', 'PRICE_CHANGE_FORBIDDEN');

    $payload['variants'][0]['selling_price'] = '20';
    $payload['variants'][0]['units'][0]['selling_price'] = '230';
    $this->putJson("/api/v1/products/{$product['id']}", $payload)->assertForbidden();

    // The owner may; the change is audited.
    $payload['variants'][0]['selling_price'] = '22.50';
    $this->actingAsApi($this->owner)->putJson("/api/v1/products/{$product['id']}", $payload)->assertOk()
        ->assertJsonPath('data.variants.0.selling_price', '22.50');

    $log = AuditLog::query()->where('event', 'product.price_changed')->latest('id')->first();
    expect($log->old_values['selling_price'])->toBe('20.00')->and($log->new_values['selling_price'])->toBe('22.50');
});

it('hides cost prices from users without products.view_cost and refuses them in input', function () {
    $product = $this->postJson('/api/v1/products', productPayload($this->units))->json('data');
    $cashier = $this->createUser($this->tenant, 'cashier');

    $variant = $this->actingAsApi($cashier)->getJson("/api/v1/products/{$product['id']}")->assertOk()->json('data.variants.0');
    expect($variant)->not->toHaveKey('cost_price')->and($variant['selling_price'])->toBe('20.00');

    // A role with products.manage but no products.view_cost.
    $role = $this->asTenant($this->tenant, function () {
        $role = Role::query()->create(['name' => 'Encoder', 'guard_name' => 'web', 'tenant_id' => $this->tenant->id, 'is_system' => false, 'is_locked' => false]);
        $role->syncPermissions(['products.view', 'products.manage']);

        return $role;
    });
    $encoder = $this->createUser($this->tenant, 'cashier');
    $this->asTenant($this->tenant, fn () => $encoder->syncRoles([$role]));

    $this->actingAsApi($encoder->fresh())->postJson('/api/v1/products', productPayload($this->units, ['name' => 'Other', 'variants' => [['selling_price' => '5', 'cost_price' => '1']]]))
        ->assertUnprocessable()->assertJsonValidationErrors(['variants.0.cost_price']);

    // Editing without sending cost keeps the stored cost.
    $payload = productPayload($this->units);
    unset($payload['variants'][0]['cost_price']);
    $payload['variants'][0]['id'] = $product['variants'][0]['id'];
    $this->putJson("/api/v1/products/{$product['id']}", $payload)->assertOk();
    $this->actingAsApi($this->owner)->getJson("/api/v1/products/{$product['id']}")->assertJsonPath('data.variants.0.cost_price', '14.5000');
});

it('enforces plan limits and features', function () {
    usePlan($this->tenant->id, 'test-limited');

    // Limited plan: no selling units, no expiry tracking.
    $this->postJson('/api/v1/products', productPayload($this->units))->assertStatus(402)->assertJsonPath('context.feature', 'unit_conversions');
    $simple = productPayload($this->units);
    $simple['variants'][0]['units'] = [];
    $this->postJson('/api/v1/products', [...$simple, 'track_expiry' => true])->assertStatus(402)->assertJsonPath('context.feature', 'batch_expiry');

    // 100-product limit.
    $this->asTenant($this->tenant, function () {
        foreach (range(1, 100) as $i) {
            Product::query()->create(['name' => "P{$i}", 'base_unit_id' => $this->units['pc'], 'type' => 'standard', 'tax_class' => 'vatable']);
        }
    });
    $this->postJson('/api/v1/products', $simple)->assertStatus(402)->assertJsonPath('code', 'PLAN_LIMIT_REACHED');
});

it('makes services stock-free and refuses selling units on them', function () {
    $this->postJson('/api/v1/products', productPayload($this->units, [
        'name' => 'Oil change', 'type' => 'service', 'track_inventory' => true, 'variants' => [['selling_price' => '350']],
    ]))->assertCreated()->assertJsonPath('data.track_inventory', false);

    $this->postJson('/api/v1/products', productPayload($this->units, ['name' => 'Bad service', 'type' => 'service']))
        ->assertUnprocessable()->assertJsonValidationErrors(['type']);
});

it('lists, searches by name/SKU/barcode and filters by category including subcategories', function () {
    $parent = $this->postJson('/api/v1/product-categories', ['name' => 'Drinks'])->json('data.id');
    $child = $this->postJson('/api/v1/product-categories', ['name' => 'Soda', 'parent_id' => $parent])->json('data.id');
    $this->postJson('/api/v1/products', productPayload($this->units, ['category_id' => $child]))->assertCreated();
    $this->postJson('/api/v1/products', productPayload($this->units, ['name' => 'Bread', 'variants' => [['name' => 'Loaf', 'selling_price' => '45'], ['name' => 'Half', 'selling_price' => '25']]]))->assertCreated();

    expect($this->getJson('/api/v1/products?search=4801981118502')->json('data.0.name'))->toBe('Coke Mismo 290ml')
        ->and($this->getJson('/api/v1/products?search=coke-2')->json('data.0.name'))->toBe('Coke Mismo 290ml')
        ->and($this->getJson("/api/v1/products?category_id={$parent}")->json('data'))->toHaveCount(1);

    $bread = $this->getJson('/api/v1/products?search=bread')->json('data.0');
    expect($bread['price_min'])->toBe('25.00')->and($bread['price_max'])->toBe('45.00')->and($bread['variants_count'])->toBe(2);
});

it('looks up by barcode, unit barcode and SKU with branch prices', function () {
    $product = $this->postJson('/api/v1/products', productPayload($this->units))->json('data');
    $variantId = $product['variants'][0]['id'];

    $this->getJson('/api/v1/products/lookup?code=4801981118502')->assertOk()
        ->assertJsonPath('data.matched_by', 'barcode')->assertJsonPath('data.unit', null)->assertJsonPath('data.price.selling_price', '20.00');

    $this->getJson('/api/v1/products/lookup?code=14801981118509')->assertOk()
        ->assertJsonPath('data.unit.abbreviation', 'box')->assertJsonPath('data.price.selling_price', '228.00')->assertJsonPath('data.price.source', 'unit');

    // Branch override: base price changes; a unit without its own price follows (× factor).
    $second = $this->asTenant($this->tenant, fn () => Branch::query()->create(['code' => 'B2', 'name' => 'Second', 'is_active' => true]));
    $this->putJson("/api/v1/product-variants/{$variantId}/branch-prices", ['prices' => [['branch_id' => $second->id, 'selling_price' => '21.25']]])
        ->assertOk()->assertJsonPath('data.branch_prices.0.selling_price', '21.25');

    $this->withHeader('X-Branch-Id', (string) $second->id)->getJson('/api/v1/products/lookup?code=coke-290')->assertOk()
        ->assertJsonPath('data.matched_by', 'sku')->assertJsonPath('data.price.selling_price', '21.25')->assertJsonPath('data.price.source', 'branch');

    $this->postJson("/api/v1/products/{$product['id']}/deactivate")->assertOk();
    $this->getJson('/api/v1/products/lookup?code=4801981118502')->assertNotFound()->assertJsonPath('code', 'PRODUCT_NOT_FOUND');
});

it('deletes products without history and only deactivates the others', function () {
    $fresh = $this->postJson('/api/v1/products', productPayload($this->units))->json('data');
    $this->deleteJson("/api/v1/products/{$fresh['id']}")->assertNoContent();
    // Its codes are free again.
    $used = $this->postJson('/api/v1/products', productPayload($this->units))->assertCreated()->json('data');

    giveHistory($this->tenant->id, $this->main->id, $used['variants'][0]['id']);
    $this->deleteJson("/api/v1/products/{$used['id']}")->assertUnprocessable()->assertJsonPath('code', 'PRODUCT_HAS_HISTORY');
});

it('uploads a product image', function () {
    Storage::fake('public');
    $product = $this->postJson('/api/v1/products', productPayload($this->units))->json('data');

    $url = $this->postJson("/api/v1/products/{$product['id']}/image", ['image' => UploadedFile::fake()->image('coke.jpg', 400, 400)])
        ->assertOk()->json('data.image_url');
    expect($url)->toContain("tenants/{$this->tenant->id}/products/");
});

it('needs prices.manage for branch prices and isolates tenants', function () {
    $product = $this->postJson('/api/v1/products', productPayload($this->units))->json('data');
    $manager = $this->createUser($this->tenant, 'inventory_manager');
    $this->actingAsApi($manager)->putJson("/api/v1/product-variants/{$product['variants'][0]['id']}/branch-prices", ['prices' => []])->assertForbidden();

    ['owner' => $intruder] = $this->createTenant();
    $this->actingAsApi($intruder);
    $this->getJson("/api/v1/products/{$product['id']}")->assertNotFound();
    $this->putJson("/api/v1/product-variants/{$product['variants'][0]['id']}/branch-prices", ['prices' => []])->assertNotFound();
    $this->getJson('/api/v1/products/lookup?code=4801981118502')->assertNotFound();
    expect($this->getJson('/api/v1/products')->json('data'))->toBe([]);
});

it('keeps selling units and their barcodes when a product is saved again', function () {
    $product = $this->postJson('/api/v1/products', productPayload($this->units))->json('data');
    $payload = productPayload($this->units);
    $payload['variants'][0]['id'] = $product['variants'][0]['id'];

    $saved = $this->putJson("/api/v1/products/{$product['id']}", $payload)->assertOk()->json('data.variants.0');

    expect($saved['units'])->toHaveCount(1)
        ->and($saved['units'][0]['id'])->toBe($product['variants'][0]['units'][0]['id'])
        ->and($saved['units'][0]['barcodes'])->toBe(['14801981118509'])
        ->and($saved['barcodes'])->toBe(['4801981118502']);

    // Removing the unit (no history) works.
    $payload['variants'][0]['units'] = [];
    $this->putJson("/api/v1/products/{$product['id']}", $payload)->assertOk()->assertJsonCount(0, 'data.variants.0.units');
});

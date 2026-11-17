<?php

use App\Models\Unit;

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner] = $this->createTenant();
    $this->actingAsApi($this->owner);
    $this->pc = $this->asTenant($this->tenant, fn () => Unit::query()->where('abbreviation', 'pc')->value('id'));
});

it('manages categories two levels deep', function () {
    $drinks = $this->postJson('/api/v1/product-categories', ['name' => 'Drinks', 'color' => '#696CFF'])->assertCreated()->json('data.id');
    $soda = $this->postJson('/api/v1/product-categories', ['name' => 'Softdrinks', 'parent_id' => $drinks])->assertCreated()->json('data.id');

    // A subcategory cannot be a parent (two levels only), and a parent cannot become a child.
    $this->postJson('/api/v1/product-categories', ['name' => 'Cola', 'parent_id' => $soda])
        ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
    $other = $this->postJson('/api/v1/product-categories', ['name' => 'Snacks'])->json('data.id');
    $this->putJson("/api/v1/product-categories/{$drinks}", ['name' => 'Drinks', 'parent_id' => $other])
        ->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

    // Same name allowed under a different parent, not twice under the same.
    $this->postJson('/api/v1/product-categories', ['name' => 'Softdrinks', 'parent_id' => $drinks])
        ->assertUnprocessable()->assertJsonValidationErrors(['name']);

    $list = collect($this->getJson('/api/v1/product-categories')->assertOk()->json('data'))->keyBy('name');
    expect($list['Drinks']['children_count'])->toBe(1)->and($list['Drinks']['color'])->toBe('#696CFF');

    $this->deleteJson("/api/v1/product-categories/{$drinks}")->assertUnprocessable()->assertJsonPath('code', 'CATEGORY_HAS_CHILDREN');
});

it('blocks deleting categories, brands and units used by products', function () {
    $cat = $this->postJson('/api/v1/product-categories', ['name' => 'Snacks'])->json('data.id');
    $brand = $this->postJson('/api/v1/brands', ['name' => 'Jack n Jill'])->assertCreated()->json('data.id');
    $unit = $this->postJson('/api/v1/units', ['name' => 'Sachet', 'abbreviation' => 'sct'])->assertCreated()->json('data.id');

    $this->postJson('/api/v1/products', [
        'name' => 'Chippy', 'category_id' => $cat, 'brand_id' => $brand, 'base_unit_id' => $unit,
        'type' => 'standard', 'tax_class' => 'vatable', 'variants' => [['selling_price' => '10']],
    ])->assertCreated();

    $this->deleteJson("/api/v1/product-categories/{$cat}")->assertUnprocessable()->assertJsonPath('code', 'CATEGORY_IN_USE');
    $this->deleteJson("/api/v1/brands/{$brand}")->assertUnprocessable()->assertJsonPath('code', 'BRAND_IN_USE');
    $this->deleteJson("/api/v1/units/{$unit}")->assertUnprocessable()->assertJsonPath('code', 'UNIT_IN_USE');
});

it('restores a deleted brand when it is created again', function () {
    $id = $this->postJson('/api/v1/brands', ['name' => 'Rebisco'])->json('data.id');
    $this->deleteJson("/api/v1/brands/{$id}")->assertNoContent();

    $this->postJson('/api/v1/brands', ['name' => 'Rebisco'])->assertCreated()->assertJsonPath('data.id', $id);
});

it('lists the default PH units', function () {
    expect(collect($this->getJson('/api/v1/units')->assertOk()->json('data'))->pluck('abbreviation'))
        ->toContain('pc', 'pack', 'box', 'kg', 'm');
});

it('lets cashiers read the catalog but not change it', function () {
    $cashier = $this->createUser($this->tenant, 'cashier');

    $this->actingAsApi($cashier)->getJson('/api/v1/units')->assertOk();
    $this->actingAsApi($cashier)->postJson('/api/v1/brands', ['name' => 'X'])->assertForbidden();
});

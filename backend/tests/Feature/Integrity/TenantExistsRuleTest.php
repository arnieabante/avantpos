<?php

use App\Models\Branch;
use App\Models\Unit;
use App\Rules\TenantExists;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/* The tenant-scoped, batched `exists` rule used for every client-sent id. */

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    ['tenant' => $this->other, 'branch' => $this->otherBranch] = $this->createTenant();
    $units = $this->asTenant($this->tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    $this->actingAsApi($this->owner)->withHeader('X-Branch-Id', (string) $this->main->id);
    $this->a = createStockItem($units['pc'], ['name' => 'Item A']);
    $this->b = createStockItem($units['pc'], ['name' => 'Item B']);
    $this->inactive = $this->asTenant($this->tenant, fn () => Branch::query()->create(['code' => 'OFF', 'name' => 'Closed', 'is_active' => false]));
    $this->validate = fn (array $data, array $rules) => $this->asTenant($this->tenant, fn () => Validator::make($data, $rules)->errors()->toArray());
});

it('checks every line of a list with one query and flags exactly the bad lines', function () {
    $foreign = DB::table('product_variants')->where('tenant_id', '!=', $this->tenant->id)->value('id') ?? 999999;
    $items = [['v' => $this->a], ['v' => $this->b], ['v' => $foreign], ['v' => $this->a], ['v' => 999999999], ['v' => ['x']], ['v' => '']];

    DB::enableQueryLog();
    $errors = ($this->validate)(['items' => $items], ['items.*.v' => ['required', TenantExists::on('product_variants')]]);
    $queries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'product_variants'))->count();
    DB::disableQueryLog();

    expect(array_keys($errors))->toEqualCanonicalizing(['items.2.v', 'items.4.v', 'items.5.v', 'items.6.v'])
        ->and($errors['items.2.v'][0])->toBe('The selected items.2.v is invalid.')
        ->and($queries)->toBe(1);
});

it('keeps chained conditions, soft deletes and single fields working', function () {
    $rules = ['branch_id' => [TenantExists::on('branches')->where('is_active', true)]];
    expect(($this->validate)(['branch_id' => $this->main->id], $rules))->toBe([])
        ->and(($this->validate)(['branch_id' => $this->inactive->id], $rules))->toHaveKey('branch_id')
        ->and(($this->validate)(['branch_id' => $this->otherBranch->id], $rules))->toHaveKey('branch_id');

    $this->asTenant($this->tenant, fn () => DB::table('product_variants')->where('id', $this->b)->update(['deleted_at' => now()]));
    expect(($this->validate)(['v' => $this->b], ['v' => [TenantExists::on('product_variants')]]))->toHaveKey('v')
        ->and(($this->validate)(['v' => $this->a], ['v' => [TenantExists::on('product_variants')]]))->toBe([]);

    expect(($this->validate)(['v' => $this->a], ['v' => [TenantExists::on('product_variants')->whereNot('id', $this->a)]]))->toHaveKey('v');
});

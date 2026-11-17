<?php

use App\Models\CashRegister;
use App\Models\PaymentMethod;
use App\Models\Unit;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Performance guards:
|  - list endpoints run a fixed number of queries whatever the number of rows
|    (no N+1: 3 records vs 15 records → same query count);
|  - every list, lookup and report stays under a query budget.
| (Lazy loading also throws outside production: Model::shouldBeStrict.)
*/

function countQueries(callable $request): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $request();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('runs the same number of queries for 3 or 15 rows on the main lists', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    ['tenant' => $tenant, 'owner' => $owner, 'branch' => $main] = $this->createTenant();
    usePlan($tenant->id, 'enterprise');
    $units = $this->asTenant($tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    $cash = $this->asTenant($tenant, fn () => PaymentMethod::query()->where('type', 'cash')->value('id'));
    $terminal = $this->createTerminal($tenant, $main);
    $this->asTenant($tenant, fn () => CashRegister::query()->create(['branch_id' => $main->id, 'terminal_id' => $terminal->id, 'name' => 'Drawer', 'is_active' => true]));
    $this->actingAsApi($owner)->withHeader('X-Branch-Id', (string) $main->id);
    $this->postJson('/api/v1/cash-sessions', ['client_uuid' => (string) Str::uuid(), 'terminal_id' => $terminal->id, 'opening_float' => '0'])->assertCreated();
    $supplier = $this->postJson('/api/v1/suppliers', ['name' => 'Supplier', 'vat_registered' => false, 'payment_terms_days' => 0])->json('data.id');

    $add = function (int $n) use ($units, $cash, $supplier) {
        foreach (range(1, $n) as $i) {
            $suffix = Str::random(6);
            $variant = createStockItem($units['pc'], ['name' => "Part {$suffix}", 'variants' => [['selling_price' => '100', 'cost_price' => '60', 'barcodes' => ['B'.$suffix]]]]);
            $this->postJson('/api/v1/goods-receipts', ['supplier_id' => $supplier, 'items' => [['product_variant_id' => $variant, 'qty' => '10', 'unit_cost' => '60']]])->assertCreated();
            $this->postJson('/api/v1/customers', ['name' => "Customer {$suffix}"])->assertCreated();
            $this->postJson('/api/v1/pos/sales', ['client_uuid' => (string) Str::uuid(), 'items' => [['product_variant_id' => $variant, 'qty' => '1']],
                'payments' => [['payment_method_id' => $cash, 'amount' => '100']]])->assertCreated();
        }
    };
    $lists = ['/api/v1/products', '/api/v1/sales', '/api/v1/customers', '/api/v1/inventory/stock', '/api/v1/inventory/movements',
        '/api/v1/goods-receipts', '/api/v1/pos/catalog', '/api/v1/reports/products?preset=today', '/api/v1/reports/sales?preset=today'];
    $measure = fn () => collect($lists)->mapWithKeys(fn ($uri) => [$uri => countQueries(fn () => $this->getJson($uri)->assertOk())])->all();

    $quote = function () {
        $lines = collect($this->getJson('/api/v1/pos/catalog')->json('data.items'))->map(fn ($i) => ['product_variant_id' => $i['product_variant_id'], 'qty' => '1'])->all();

        return countQueries(fn () => $this->postJson('/api/v1/pos/quote', ['items' => $lines])->assertOk());
    };

    $add(3);
    $few = $measure() + ['quote' => $quote()];
    $add(12);
    $many = $measure() + ['quote' => $quote()];

    expect($many)->toBe($few); // pricing a 15-line cart costs the same queries as a 3-line cart
});

it('keeps every list, lookup and report within a query budget', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $business = buildFullBusiness('PERF');
    $over = [];
    foreach (tenantApiRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }
        $path = routePathFor($route, $business['tenant']->id);
        if ($path === null || str_contains($route->uri(), 'attachments')) {
            continue;
        }
        $this->actingAsApi($business['owner'])->withHeader('X-Branch-Id', (string) $business['branch']->id);
        $queries = countQueries(fn () => $this->getJson($path));
        if ($queries > 40) {
            $over[] = "{$route->uri()}: {$queries} queries";
        }
        app('auth')->forgetGuards();
    }
    expect($over)->toBe([]);
});

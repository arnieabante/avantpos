<?php

use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
| Tenant isolation, swept over EVERY API route:
|
|  1. Every route that takes a record id refuses another business's record
|     (all methods: read, change, delete, actions) — 404/403, never 2xx.
|  2. List and report endpoints never show another business's data.
|  3. Ids of another business inside request bodies are rejected.
|
| Business A ("ALPHASECRET") has at least one record of every kind; business B
| makes the requests as its owner (all permissions, all features). A route
| parameter that A has no record for fails the test, so a new route cannot
| slip through unchecked.
*/

const ISOLATION_MARKER = 'ALPHASECRET';

/** Parameters that are not tenant records (public tokens, report keys, gateways). */
const NON_RECORD_PARAMS = ['id', 'hash', 'token', 'gateway', 'report'];

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class); // hundreds of requests in a row
    $this->a = buildFullBusiness(ISOLATION_MARKER);
    ['tenant' => $this->tenantB, 'owner' => $this->ownerB, 'branch' => $this->mainB] = $this->createTenant(['business_name' => 'Bravo Store']);
    usePlan($this->tenantB->id, 'enterprise');
    $this->asB = fn () => $this->actingAsApi($this->ownerB)->withHeader('X-Branch-Id', (string) $this->mainB->id);
    $this->idOfA = fn (string $table) => DB::table($table)->where('tenant_id', $this->a['tenant']->id)->orderByDesc('id')->value('id');
});

it('refuses every route that takes another business\'s record id', function () {
    $routes = tenantApiRoutes();
    expect(count($routes))->toBeGreaterThan(150);

    $missing = [];
    $leaks = [];
    $checked = 0;
    foreach ($routes as $route) {
        $params = $route->parameterNames();
        $recordParams = array_diff($params, NON_RECORD_PARAMS);
        if ($recordParams === []) {
            continue;
        }
        $uri = '/'.$route->uri();
        $tables = routeParamTables($route);
        foreach ($recordParams as $param) {
            $table = $tables[$param] ?? $tables[Str::camel($param)] ?? ['role' => 'roles'][$param] ?? null; // roles: plain id, looked up per tenant in the controller
            $id = $table ? ($this->idOfA)($table) : null;
            if (! $id) {
                $missing[] = "{$param} ({$route->uri()})";

                continue 2;
            }
            $uri = str_replace(['{'.$param.'}', '{'.$param.'?}'], (string) $id, $uri);
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            ($this->asB)();
            $response = $this->json($method, $uri, []);
            $checked++;
            $status = $response->status();
            if (! in_array($status, [403, 404], true)) {
                $leaks[] = "{$method} {$uri} → {$status}";
            }
            expect($response->getContent())->not->toContain(ISOLATION_MARKER);
            $this->app['auth']->forgetGuards();
        }
    }

    expect($missing)->toBe([], 'Business A has no record for these route parameters (extend buildFullBusiness)')
        ->and($leaks)->toBe([], 'Routes that did not refuse another business\'s record')
        ->and($checked)->toBeGreaterThan(120);
});

it('never shows another business\'s data in lists, lookups or reports', function () {
    $checked = 0;
    foreach (tenantApiRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true) || $route->parameterNames() !== []) {
            continue;
        }
        ($this->asB)();
        $response = $this->getJson('/'.$route->uri().'?search='.ISOLATION_MARKER.'&per_page=100');
        $checked++;
        expect($response->status())->toBeLessThan(500, "GET {$route->uri()} failed")
            ->and($response->getContent())->not->toContain(ISOLATION_MARKER, "GET {$route->uri()} shows another business's data");
        $this->app['auth']->forgetGuards();
    }

    // Every report, as data and as CSV.
    ($this->asB)();
    foreach ($this->getJson('/api/v1/reports')->assertOk()->json('data') as $report) {
        foreach (['', '&format=csv'] as $format) {
            $response = $this->get("/api/v1/reports/{$report['key']}?preset=this_year{$format}", ['Accept' => 'application/json']);
            $checked++;
            expect($response->status())->toBeLessThan(500, "report {$report['key']} failed")
                ->and($response->getContent())->not->toContain(ISOLATION_MARKER, "report {$report['key']} shows another business's data");
        }
    }
    expect($checked)->toBeGreaterThan(40);
});

it('rejects another business\'s ids inside request bodies', function () {
    $a = fn (string $table) => ($this->idOfA)($table);
    ($this->asB)();
    $cash = $this->getJson('/api/v1/payment-methods')->json('data.0.id');

    $attempts = [
        ['/api/v1/pos/quote', ['items' => [['product_variant_id' => $a('product_variants'), 'qty' => '1']]], 'items.0.product_variant_id'],
        ['/api/v1/stock-adjustments', ['reason_code' => 'opening', 'items' => [['product_variant_id' => $a('product_variants'), 'qty' => '1', 'unit_cost' => '1']]], 'items.0.product_variant_id'],
        ['/api/v1/stock-transfers', ['to_branch_id' => $a('branches'), 'items' => [['product_variant_id' => $a('product_variants'), 'qty' => '1']]], 'to_branch_id'],
        ['/api/v1/purchase-orders', ['supplier_id' => $a('suppliers'), 'items' => []], 'supplier_id'],
        ['/api/v1/goods-receipts', ['supplier_id' => $a('suppliers'), 'items' => []], 'supplier_id'],
        ['/api/v1/supplier-bills', ['goods_receipt_id' => $a('goods_receipts'), 'bill_date' => now()->toDateString()], 'goods_receipt_id'],
        ['/api/v1/customers', ['name' => 'X', 'customer_group_id' => $a('customer_groups')], 'customer_group_id'],
        ['/api/v1/products', ['name' => 'X', 'base_unit_id' => $a('units'), 'category_id' => $a('product_categories'), 'brand_id' => $a('brands'), 'type' => 'standard', 'tax_class' => 'vatable', 'variants' => [['selling_price' => '1']]], 'base_unit_id'],
        ['/api/v1/invitations', ['email' => 'x@example.com', 'role_id' => $a('roles'), 'branch_ids' => [$a('branches')]], 'role_id'],
        ['/api/v1/users', ['name' => 'X', 'email' => 'x@example.com', 'role_id' => $a('roles'), 'branch_ids' => [$this->mainB->id], 'password' => 'Secret-pass1', 'password_confirmation' => 'Secret-pass1'], 'role_id'],
        ['/api/v1/terminals', ['branch_id' => $a('branches'), 'code' => 'X1', 'name' => 'X'], 'branch_id'],
        ['/api/v1/expenses', ['branch_id' => $this->mainB->id, 'expense_category_id' => $a('expense_categories'), 'payment_method_id' => $cash, 'expense_date' => now()->toDateString(), 'amount' => '1'], 'expense_category_id'],
    ];
    foreach ($attempts as [$uri, $body, $field]) {
        $response = $this->postJson($uri, $body);
        expect($response->status())->toBe(422, "{$uri} accepted another business's id")
            ->and($response->json('errors'))->toHaveKey($field);
    }

    // Nothing was created in or taken from business A.
    expect(DB::table('stock_transfers')->where('to_branch_id', $a('branches'))->where('tenant_id', $this->tenantB->id)->exists())->toBeFalse();
});

it('does not let a user pick a branch of another business', function () {
    $ownerA = User::withoutTenantScope()->where('tenant_id', $this->a['tenant']->id)->where('id', $this->a['owner']->id)->firstOrFail();

    // A's token cannot pick B's branch.
    $this->actingAsApi($ownerA)->withHeader('X-Branch-Id', (string) $this->mainB->id)->getJson('/api/v1/products')
        ->assertForbidden()->assertJsonPath('code', 'BRANCH_FORBIDDEN');

});

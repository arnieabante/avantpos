<?php

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\ExpenseCategory;
use App\Models\PaymentMethod;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A complete business with at least one record of every kind the API exposes
 * by id, created through the API like a real user would. Text fields carry
 * $marker so other businesses' responses can be searched for leaks.
 *
 * Used by the tenant isolation, permission and performance sweeps.
 *
 * @return array{tenant: Tenant, owner: User, branch: Branch, second: Branch}
 */
function buildFullBusiness(string $marker): array
{
    $t = test();
    Storage::fake(config('avantpos.app.documents_disk', 'local'));
    Notification::fake();

    ['tenant' => $tenant, 'owner' => $owner, 'branch' => $main] = $t->createTenant(['business_name' => "{$marker} Motor Parts"]);
    usePlan($tenant->id, 'enterprise');
    $tenant->forceFill(['vat_registered' => true])->save();

    $second = $t->asTenant($tenant, fn () => Branch::query()->create(['code' => 'B2', 'name' => "{$marker} Branch Two", 'is_active' => true]));
    $terminal = $t->createTerminal($tenant, $main);
    $t->asTenant($tenant, fn () => CashRegister::query()->create(['branch_id' => $main->id, 'terminal_id' => $terminal->id, 'name' => "{$marker} Drawer", 'is_active' => true]));
    $units = $t->asTenant($tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    $methods = $t->asTenant($tenant, fn () => PaymentMethod::query()->pluck('id', 'type')->all());
    $cashier = $t->createUser($tenant, 'cashier', null, ['name' => "{$marker} Cashier"]);

    $t->actingAsApi($owner)->withHeader('X-Branch-Id', (string) $main->id);

    // Catalogue.
    $category = $t->postJson('/api/v1/product-categories', ['name' => "{$marker} Oils"])->assertCreated()->json('data.id');
    $brand = $t->postJson('/api/v1/brands', ['name' => "{$marker} Brand"])->assertCreated()->json('data.id');
    $variant = createStockItem($units['pc'], ['name' => "{$marker} Engine Oil", 'category_id' => $category, 'brand_id' => $brand,
        'variants' => [['selling_price' => '380', 'cost_price' => '290']]]);
    $t->postJson('/api/v1/stock-adjustments', ['reason_code' => 'opening', 'notes' => "{$marker} opening", 'post' => true, 'items' => [
        ['product_variant_id' => $variant, 'qty' => '50', 'unit_cost' => '290'],
    ]])->assertCreated();

    // Customers, discounts, settings.
    $group = $t->postJson('/api/v1/customer-groups', ['name' => "{$marker} Resellers", 'price_tier' => 'wholesale', 'discount_percent' => '5'])->assertCreated()->json('data.id');
    $t->postJson('/api/v1/customers', ['name' => "{$marker} Customer", 'phone' => '0917 555 0101', 'customer_group_id' => $group, 'allow_credit' => true, 'credit_limit' => '5000'])->assertCreated();
    $t->postJson('/api/v1/discount-presets', ['name' => "{$marker} Suki", 'type' => 'percent', 'value' => '5', 'scope' => 'transaction'])->assertCreated();
    $t->postJson('/api/v1/roles', ['name' => "{$marker} Clerk", 'permissions' => ['products.view']])->assertCreated();
    $t->postJson('/api/v1/invitations', ['email' => Str::lower($marker).'-invite@example.com', 'name' => "{$marker} Invitee", 'role_id' => $t->getJson('/api/v1/roles/assignable')->json('data.0.id'), 'branch_ids' => [$main->id]])->assertCreated();

    // Purchasing and payables.
    $supplier = $t->postJson('/api/v1/suppliers', ['name' => "{$marker} Supplier", 'vat_registered' => false, 'payment_terms_days' => 30])->assertCreated()->json('data.id');
    $order = $t->postJson('/api/v1/purchase-orders', ['supplier_id' => $supplier, 'notes' => "{$marker} order", 'items' => [
        ['product_variant_id' => $variant, 'qty' => '10', 'unit_cost' => '290'],
    ]])->assertCreated()->json('data');
    $receipt = $t->postJson('/api/v1/goods-receipts', ['supplier_id' => $supplier, 'supplier_invoice_no' => "{$marker}-SI", 'items' => [
        ['product_variant_id' => $variant, 'qty' => '10', 'unit_cost' => '290'],
    ]])->assertCreated()->json('data.id');
    $bill = $t->postJson('/api/v1/supplier-bills', ['goods_receipt_id' => $receipt, 'bill_date' => now('Asia/Manila')->toDateString()])->assertCreated()->json('data.id');
    $t->postJson('/api/v1/supplier-payments', ['supplier_id' => $supplier, 'paid_at' => now('Asia/Manila')->toDateString(), 'amount' => '100',
        'reference_no' => "{$marker}-PAY", 'allocations' => [['supplier_bill_id' => $bill, 'amount' => '100']]])->assertCreated();

    // Inventory documents.
    $t->postJson('/api/v1/stock-transfers', ['to_branch_id' => $second->id, 'notes' => "{$marker} transfer", 'items' => [['product_variant_id' => $variant, 'qty' => '2']]])->assertCreated();
    $t->postJson('/api/v1/stock-counts', ['notes' => "{$marker} count"])->assertCreated();

    // Selling: shift, sale, held cart, closed shift, Z reading.
    $session = $t->postJson('/api/v1/cash-sessions', ['client_uuid' => (string) Str::uuid(), 'terminal_id' => $terminal->id, 'opening_float' => '1000'])->assertCreated()->json('data.id');
    $t->postJson('/api/v1/pos/sales', ['client_uuid' => (string) Str::uuid(), 'items' => [['product_variant_id' => $variant, 'qty' => '2']],
        'payments' => [['payment_method_id' => $methods['cash'], 'amount' => '760']]])->assertCreated();
    $t->postJson('/api/v1/pos/held-carts', ['client_uuid' => (string) Str::uuid(), 'label' => "{$marker} held",
        'cart' => ['items' => [['product_variant_id' => $variant, 'qty' => '1']], 'preview' => ['total' => '380.00']]])->assertCreated();
    $t->postJson("/api/v1/cash-sessions/{$session}/close", ['counted_cash' => '1760'])->assertOk();
    $t->postJson('/api/v1/pos/z-readings', ['terminal_id' => $terminal->id])->assertCreated();

    // Expenses with a private attachment.
    $expenseCategory = $t->asTenant($tenant, fn () => ExpenseCategory::query()->value('id'));
    $expense = $t->postJson('/api/v1/expenses', ['branch_id' => $main->id, 'expense_category_id' => $expenseCategory, 'payment_method_id' => $methods['cash'],
        'expense_date' => now('Asia/Manila')->toDateString(), 'amount' => '500', 'vendor' => "{$marker} Vendor", 'submit' => false])->assertCreated()->json('data.id');
    $t->postJson("/api/v1/expenses/{$expense}/attachments", ['file' => UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf')])->assertCreated();

    // Support and billing.
    $t->postJson('/api/v1/support/tickets', ['subject' => "{$marker} printer", 'category' => 'technical', 'body' => "{$marker} prints blank"])->assertCreated();
    $t->postJson('/api/v1/billing/checkout', ['plan' => 'professional', 'billing_cycle' => 'monthly'])->assertCreated();

    expect($order)->toBeArray()->and($cashier)->toBeInstanceOf(User::class);
    app('auth')->forgetGuards();

    return ['tenant' => $tenant, 'owner' => $owner, 'branch' => $main, 'second' => $second];
}

/** Route sweeps: the table behind each record parameter of a route. */
function routeParamTables(RoutingRoute $route): array
{
    $tables = ['role' => 'roles']; // roles: plain id, looked up per business in the controller
    foreach ($route->signatureParameters(['subClass' => Model::class]) as $parameter) {
        $class = $parameter->getType()?->getName();
        if ($class && is_subclass_of($class, Model::class)) {
            $tables[$parameter->getName()] = (new $class)->getTable();
            $tables[Str::snake($parameter->getName())] = (new $class)->getTable();
        }
    }

    return $tables;
}

/** Route sweeps: the route URI with each record parameter replaced by a record id of the business (null if one is missing). */
function routePathFor(RoutingRoute $route, int $tenantId): ?string
{
    $tables = routeParamTables($route);
    $path = '/'.$route->uri();
    foreach ($route->parameterNames() as $param) {
        $value = match ($param) {
            'report' => 'sales',
            'gateway' => 'xendit',
            default => isset($tables[$param]) ? DB::table($tables[$param])->where('tenant_id', $tenantId)->orderByDesc('id')->value('id') : null,
        };
        if ($value === null) {
            return null;
        }
        $path = str_replace(['{'.$param.'}', '{'.$param.'?}'], (string) $value, $path);
    }

    return $path;
}

/** @return list<RoutingRoute> tenant API routes (not public auth, webhooks or the admin API) */
function tenantApiRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $r) => str_starts_with($r->uri(), 'api/v1/') && in_array('auth:sanctum', $r->gatherMiddleware(), true)
            && in_array('tenant', $r->gatherMiddleware(), true))
        ->values()->all();
}

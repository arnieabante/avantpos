<?php

use App\Models\PlatformAdmin;
use App\Models\SubscriptionInvoice;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
| Every platform admin page (GET route of /api/admin/v1) with real data from
| a business, requested the way production does: WITHOUT any tenant context
| left over from earlier requests. Admin pages read across businesses, so
| each tenant-scoped query or eager load must lift the scope deliberately.
*/

it('loads every admin page and record across businesses without a tenant context', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $business = buildFullBusiness('ADMINSWEEP');

    // A bank transfer waiting for verification (payment → invoice → plan).
    $this->actingAsApi($business['owner'])->withHeader('X-Branch-Id', (string) $business['branch']->id);
    $invoice = SubscriptionInvoice::withoutTenantScope()->where('tenant_id', $business['tenant']->id)->latest('id')->firstOrFail();
    $this->postJson("/api/v1/billing/invoices/{$invoice->id}/manual-payment", ['reference' => 'BPI-1', 'channel' => 'BPI', 'amount' => (string) $invoice->total, 'paid_on' => now()->toDateString()])->assertCreated();
    app('auth')->forgetGuards();

    $admin = PlatformAdmin::query()->create(['name' => 'Sweep', 'email' => 'sweep@avantpos.ph', 'password' => 'AdminSecret1', 'role' => 'super_admin', 'is_active' => true])->fresh();
    $ids = [
        'tenant' => $business['tenant']->id,
        'ticket' => DB::table('support_tickets')->where('tenant_id', $business['tenant']->id)->value('id'),
    ];

    $checked = [];
    $failed = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RoutingRoute $route */
        if (! str_starts_with($route->uri(), 'api/admin/v1/') || ! in_array('GET', $route->methods(), true) || str_contains($route->uri(), 'auth/')) {
            continue;
        }
        $path = '/'.preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) $ids[$m[1]], $route->uri());
        $filters = ['api/admin/v1/payments' => ['?status=pending', '?status=paid'], 'api/admin/v1/tickets' => ['?status=open'], 'api/admin/v1/tenants' => ['?due=overdue', '?status=trial']];
        foreach (['', ...($filters[$route->uri()] ?? [])] as $query) {
            $response = $this->actingAs($admin, 'platform')->getJson($path.$query);
            $checked[] = $path.$query;
            if ($response->status() !== 200) {
                $failed[] = "{$path}{$query} → {$response->status()} ".($response->json('message') ?? '');
            }
            app('auth')->forgetGuards();
        }
    }

    expect($failed)->toBe([])->and(count($checked))->toBeGreaterThanOrEqual(16);
});

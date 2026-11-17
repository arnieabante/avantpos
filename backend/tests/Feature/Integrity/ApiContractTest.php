<?php

use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
| API contract, swept over every route:
|  - every permission named anywhere (routes, backend, frontend) exists;
|  - protected routes answer 401 with the standard error envelope when signed out;
|  - tenant routes without a permission check are an explicit, reviewed list;
|  - a cashier is refused (403) on every route needing a permission they lack;
|  - POS devices cannot call user endpoints; errors use one envelope.
*/

/** Tenant routes any signed-in user may call (own account, support, address lookups, device ping). */
const ROUTES_WITHOUT_PERMISSION = [
    'GET api/v1/auth/me', 'POST api/v1/auth/email/verification-notification', 'PUT api/v1/auth/pin', 'POST api/v1/auth/override',
    'PUT api/v1/auth/profile', 'PUT api/v1/auth/password',
    'GET api/v1/support/tickets', 'POST api/v1/support/tickets', 'GET api/v1/support/tickets/{ticket}', 'POST api/v1/support/tickets/{ticket}/reply',
    'GET api/v1/ph/regions', 'GET api/v1/ph/provinces', 'GET api/v1/ph/cities', 'GET api/v1/ph/barangays',
    'GET api/v1/terminal/ping',
];

/** Routes reachable without signing in. */
const PUBLIC_ROUTE_PREFIXES = [
    'api/v1/auth/login', 'api/v1/auth/register', 'api/v1/auth/token', 'api/v1/auth/forgot-password', 'api/v1/auth/reset-password',
    'api/v1/auth/invitations/', 'api/v1/auth/email/verify/', 'api/v1/auth/logout', 'api/v1/billing/webhooks/', 'api/v1/auth/subdomain',
    'api/v1/plans', 'api/admin/v1/auth/login', 'api/v1/public/',
];

function routeKey(RoutingRoute $route): string
{
    return implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri();
}

/** @return list<string> permission names a route requires (any of) */
function routePermissions(RoutingRoute $route): array
{
    $names = [];
    foreach ($route->gatherMiddleware() as $m) {
        if (is_string($m) && str_starts_with($m, 'permission:')) {
            $names = [...$names, ...explode('|', Str::after($m, 'permission:'))];
        }
    }

    return array_values(array_unique($names));
}

it('only uses permission names that exist (routes, backend code and the React app)', function () {
    $known = collect(config('avantpos.permissions.groups'))->flatMap(fn ($g) => array_keys($g['permissions']))->all();
    expect($known)->toContain('pos.sell', 'business.manage');
    $used = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        foreach (routePermissions($route) as $p) {
            $used[$p][] = 'route '.$route->uri();
        }
    }
    $scan = function (string $dir, array $patterns) use (&$used) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! preg_match('/\.(php|ts|tsx)$/', $file->getFilename()) || str_contains($file->getPathname(), '.test.')) {
                continue;
            }
            $code = file_get_contents($file->getPathname());
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $code, $m);
                foreach ($m[1] as $list) {
                    preg_match_all("/['\"]([a-z_]+\.[a-z_]+)['\"]/", $list, $names);
                    foreach ($names[1] as $name) {
                        $used[$name][] = Str::after($file->getPathname(), base_path());
                    }
                }
            }
        }
    };
    $scan(app_path(), [
        "/(?:->can|->cannot|hasPermissionTo|hasAnyPermission|canAny)\(\s*(\[[^\]]*\]|'[^']+')/",
        "/permissions?' => (\[[^\]]*\]|'[^']+')/",
    ]);
    $frontend = base_path('../frontend/src');
    if (is_dir($frontend)) {
        $scan($frontend, [
            "/\b(?:can|canAny|hasPermission)\(\s*(\[[^\]]*\]|'[^']+')/",
            "/permissions?[=:]\s*\{?(\[[^\]]*\]|'[^']+')/",
        ]);
    }

    $unknown = array_diff_key($used, array_flip($known));
    // Names that look like permissions but are not (audit events / sale meta keys).
    unset($unknown['sale.approved_overrides']);
    expect(array_map(fn ($where) => array_values(array_unique($where)), $unknown))->toBe([]);
    expect(count($used))->toBeGreaterThan(40);
});

it('answers 401 with the error envelope on every protected route when signed out', function () {
    $checked = 0;
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/') || Str::startsWith($uri, PUBLIC_ROUTE_PREFIXES) || $uri === 'api/v1/auth/logout') {
            continue;
        }
        $path = '/'.preg_replace(['/\{report\}/', '/\{[^}]+\}/'], ['sales', '1'], $uri);
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $response = $this->json($method, $path);
            $checked++;
            expect($response->status())->toBe(401, "{$method} {$uri} is reachable signed out")
                ->and($response->json())->toMatchArray(['message' => 'Unauthenticated.', 'code' => 'UNAUTHENTICATED']);
        }
    }
    expect($checked)->toBeGreaterThan(250);
});

it('checks a permission on every tenant route except a reviewed list', function () {
    $open = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $r) => str_starts_with($r->uri(), 'api/v1/') && in_array('tenant', $r->gatherMiddleware(), true) && routePermissions($r) === [])
        ->map(fn ($r) => routeKey($r))->sort()->values()->all();

    expect($open)->toEqualCanonicalizing(ROUTES_WITHOUT_PERMISSION);
});

it('refuses a cashier on every route that needs a permission they do not have', function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    $business = buildFullBusiness('PERMSWEEP');
    $cashier = User::withoutTenantScope()->where('tenant_id', $business['tenant']->id)->where('name', 'PERMSWEEP Cashier')->firstOrFail();
    $held = $this->asTenant($business['tenant'], fn () => $cashier->getAllPermissions()->pluck('name')->all());
    expect($held)->toContain('pos.sell')->not->toContain('users.manage');

    $checked = 0;
    $allowed = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        $needs = routePermissions($route);
        if (! str_starts_with($route->uri(), 'api/v1/') || $needs === [] || array_intersect($needs, $held) !== []) {
            continue;
        }
        $path = routePathFor($route, $business['tenant']->id);
        expect($path)->not->toBeNull("no record for {$route->uri()}");
        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $response = $this->actingAsApi($cashier)->withHeader('X-Branch-Id', (string) $business['branch']->id)->json($method, $path, []);
            $checked++;
            if ($response->status() !== 403) {
                $allowed[] = "{$method} {$route->uri()} → {$response->status()}";
            }
            app('auth')->forgetGuards();
        }
    }

    expect($allowed)->toBe([])->and($checked)->toBeGreaterThan(100);
});

it('keeps POS device tokens off user endpoints', function () {
    ['tenant' => $tenant, 'branch' => $main] = $this->createTenant();
    $terminal = $this->createTerminal($tenant, $main);
    $this->actingAsApi($terminal, ['terminal:sync'])->getJson('/api/v1/terminal/ping')->assertOk();
    foreach (['/api/v1/products', '/api/v1/users', '/api/v1/sales', '/api/v1/auth/me'] as $uri) {
        $this->actingAsApi($terminal, ['terminal:sync'])->getJson($uri)->assertForbidden();
        app('auth')->forgetGuards();
    }
});

it('uses one error envelope for missing routes, validation and missing records', function () {
    ['owner' => $owner, 'branch' => $main] = $this->createTenant();
    $this->getJson('/api/v1/no-such-endpoint')->assertNotFound()->assertJson(['code' => 'NOT_FOUND']);

    $this->actingAsApi($owner)->withHeader('X-Branch-Id', (string) $main->id);
    $this->postJson('/api/v1/brands', [])->assertUnprocessable()
        ->assertJsonStructure(['message', 'code', 'errors' => ['name']])->assertJsonPath('code', 'VALIDATION_ERROR');
    $this->getJson('/api/v1/products/999999999')->assertNotFound()->assertExactJson(['message' => 'Resource not found.', 'code' => 'NOT_FOUND']);
});

it('lets owners and business administrators see all support tickets, other staff only their own', function () {
    ['tenant' => $tenant, 'owner' => $owner, 'branch' => $main] = $this->createTenant();
    $admin = $this->createUser($tenant, 'business_admin');
    $cashier = $this->createUser($tenant, 'cashier');
    $open = fn (User $user, string $subject) => $this->actingAsApi($user)->withHeader('X-Branch-Id', (string) $main->id)
        ->postJson('/api/v1/support/tickets', ['subject' => $subject, 'category' => 'technical', 'body' => 'Please help us with this problem.'])->assertCreated()->json('data.id');
    $cashierTicket = $open($cashier, 'Printer');
    $open($owner, 'Billing question');

    $subjects = fn (User $user) => collect($this->actingAsApi($user)->withHeader('X-Branch-Id', (string) $main->id)->getJson('/api/v1/support/tickets')->json('data'))->pluck('subject')->sort()->values()->all();
    expect($subjects($admin))->toBe(['Billing question', 'Printer'])
        ->and($subjects($owner))->toBe(['Billing question', 'Printer'])
        ->and($subjects($cashier))->toBe(['Printer']);
    $other = $this->createUser($tenant, 'cashier');
    $this->actingAsApi($other)->withHeader('X-Branch-Id', (string) $main->id)->getJson("/api/v1/support/tickets/{$cashierTicket}")->assertNotFound();
});

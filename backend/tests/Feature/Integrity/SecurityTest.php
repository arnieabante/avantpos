<?php

use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Support\Export\TableExporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
| Security checks: headers, brute-force limits, secrets never serialised,
| mass assignment, injection, uploads, CSV formula injection, separate
| platform-admin guard, deactivated users.
*/

beforeEach(function () {
    ['tenant' => $this->tenant, 'owner' => $this->owner, 'branch' => $this->main] = $this->createTenant();
    usePlan($this->tenant->id, 'enterprise');
    $this->as = fn (User $user) => $this->actingAsApi($user)->withHeader('X-Branch-Id', (string) $this->main->id);
});

it('sends security headers on API responses', function () {
    ($this->as)($this->owner);
    $response = $this->getJson('/api/v1/auth/me')->assertOk();
    foreach ([
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
    ] as $header => $value) {
        expect($response->headers->get($header))->toBe($value);
    }
    expect($response->headers->has('X-Powered-By'))->toBeFalse()
        ->and($response->headers->has('Strict-Transport-Security'))->toBeFalse(); // HTTP: no HSTS

    $this->getJson('https://localhost/api/v1/no-such-endpoint')->assertNotFound()
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('throttles password guessing per account and IP', function () {
    for ($i = 1; $i <= 5; $i++) {
        $this->postJson('/api/v1/auth/token', ['email' => $this->owner->email, 'password' => 'wrong-'.$i, 'device_name' => 't'])->assertUnprocessable();
    }
    $this->postJson('/api/v1/auth/token', ['email' => $this->owner->email, 'password' => 'wrong-6', 'device_name' => 't'])
        ->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    // Even the right password waits.
    $this->postJson('/api/v1/auth/token', ['email' => $this->owner->email, 'password' => TestCase::PASSWORD, 'device_name' => 't'])->assertStatus(429);
});

it('throttles supervisor PIN guessing', function () {
    $manager = $this->createUser($this->tenant, 'branch_manager');
    $this->asTenant($this->tenant, fn () => $manager->setPin('4321'));
    $cashier = $this->createUser($this->tenant, 'cashier');
    ($this->as)($cashier);
    $statuses = [];
    for ($i = 0; $i < 11; $i++) {
        $statuses[] = $this->postJson('/api/v1/auth/override', ['supervisor_id' => $manager->id, 'pin' => '000'.($i % 10), 'permission' => 'sales.void'])->status();
    }
    // Wrong PINs are refused, and guessing is blocked within 11 tries.
    expect(array_diff($statuses, [403, 429]))->toBe([])->and($statuses)->toContain(429);
    $this->postJson('/api/v1/auth/override', ['supervisor_id' => $manager->id, 'pin' => '4321', 'permission' => 'sales.void'])->assertStatus(429);
});

it('never puts password hashes, PIN hashes, tokens or 2FA secrets in responses', function () {
    $cashier = $this->createUser($this->tenant, 'cashier');
    $this->asTenant($this->tenant, fn () => $cashier->setPin('1357'));
    ($this->as)($this->owner);
    $bodies = [
        $this->getJson('/api/v1/auth/me')->getContent(),
        $this->getJson('/api/v1/users')->getContent(),
        $this->getJson("/api/v1/users/{$cashier->id}")->getContent(),
        $this->postJson('/api/v1/auth/token', ['email' => $cashier->email, 'password' => TestCase::PASSWORD, 'device_name' => 'phone'])->getContent(),
    ];
    $hashes = User::withoutTenantScope()->whereKey([$cashier->id, $this->owner->id])->get(['password', 'pin_hash'])->flatMap(fn ($u) => [$u->password, $u->pin_hash])->filter();
    foreach ($bodies as $body) {
        expect($body)->not->toContain('"password"')->not->toContain('pin_hash')->not->toContain('remember_token')->not->toContain('two_factor_secret');
        foreach ($hashes as $hash) {
            expect($body)->not->toContain($hash);
        }
    }
});

it('ignores tenant_id and other protected fields sent by the client', function () {
    ['tenant' => $other] = $this->createTenant();
    $units = $this->asTenant($this->tenant, fn () => Unit::query()->pluck('id', 'abbreviation')->all());
    ($this->as)($this->owner);
    $id = $this->postJson('/api/v1/products', ['name' => 'Chain Lube', 'tenant_id' => $other->id, 'base_unit_id' => $units['pc'],
        'type' => 'standard', 'tax_class' => 'vatable', 'variants' => [['selling_price' => '595']]])->assertCreated()->json('data.id');
    expect(Product::withoutTenantScope()->find($id)->tenant_id)->toBe($this->tenant->id);

    $cashier = $this->createUser($this->tenant, 'cashier');
    $this->putJson("/api/v1/users/{$cashier->id}", ['name' => 'Renamed', 'tenant_id' => $other->id, 'status' => 'inactive', 'password' => 'Hacked-123', 'email' => 'x@evil.test'])->assertOk();
    $fresh = User::withoutTenantScope()->findOrFail($cashier->id);
    expect($fresh->name)->toBe('Renamed')->and($fresh->tenant_id)->toBe($this->tenant->id)->and($fresh->status->value)->toBe('active')
        ->and($fresh->email)->toBe($cashier->email)->and(Hash::check('Hacked-123', $fresh->password))->toBeFalse();
});

it('treats search input as data, not SQL', function () {
    ($this->as)($this->owner);
    foreach (["' OR 1=1 --", '%', '_', '"; DROP TABLE products; --', '\\', "') UNION SELECT password FROM users --"] as $term) {
        foreach (['/api/v1/products', '/api/v1/customers', '/api/v1/users', '/api/v1/sales', '/api/v1/suppliers'] as $uri) {
            $response = $this->getJson($uri.'?search='.urlencode($term))->assertOk();
            expect($response->json('meta.pagination.total') ?? count($response->json('data')))->toBeLessThanOrEqual(1);
        }
    }
    expect(Product::withoutTenantScope()->count())->toBeGreaterThanOrEqual(0); // table still there
});

it('accepts only real raster images as the business logo', function () {
    Storage::fake('public');
    Storage::fake(config('filesystems.default'));
    ($this->as)($this->owner);
    $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    $php = UploadedFile::fake()->createWithContent('logo.png', '<?php system($_GET["c"]); ?>');
    $this->postJson('/api/v1/business/logo', ['logo' => $svg])->assertUnprocessable();
    $this->postJson('/api/v1/business/logo', ['logo' => $php])->assertUnprocessable();
    $this->postJson('/api/v1/business/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 200)])->assertSuccessful();
});

it('neutralises spreadsheet formulas in CSV exports but keeps numbers', function () {
    $csv = app(TableExporter::class)->csv(
        [['key' => 'name', 'label' => 'Product', 'type' => 'text'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
        [['name' => '=HYPERLINK("http://evil.test","Click")', 'amount' => '-12.50'], ['name' => '@SUM(A1)', 'amount' => '5'], ['name' => '+63 917', 'amount' => '0']],
        ['-Report title'],
    );
    expect($csv)->toContain("'=HYPERLINK")->toContain("'@SUM(A1)")->toContain("'+63 917")->toContain("'-Report title")
        ->toContain(',-12.50')->not->toContain(',=HYPERLINK');
});

it('keeps the platform admin API on its own guard', function () {
    ($this->as)($this->owner);
    $this->getJson('/api/admin/v1/dashboard')->assertUnauthorized();
    $this->getJson('/api/admin/v1/tenants')->assertUnauthorized();
});

it('locks out deactivated users immediately', function () {
    $cashier = $this->createUser($this->tenant, 'cashier');
    $token = $cashier->createToken('phone')->plainTextToken;
    ($this->as)($this->owner);
    $this->postJson("/api/v1/users/{$cashier->id}/deactivate")->assertOk();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    $this->postJson('/api/v1/auth/token', ['email' => $cashier->email, 'password' => TestCase::PASSWORD, 'device_name' => 't'])->assertForbidden()->assertJsonPath('code', 'USER_INACTIVE');
});

it('ships production-safe defaults', function () {
    expect(config('session.http_only'))->toBeTrue()
        ->and(config('session.same_site'))->toBeIn(['lax', 'strict'])
        ->and(config('cors.supports_credentials'))->toBeTrue()
        ->and(config('cors.allowed_origins'))->not->toContain('*');
    $example = file_get_contents(base_path('.env.example'));
    expect($example)->toContain('APP_DEBUG=')->toContain('SESSION_SECURE_COOKIE');
});

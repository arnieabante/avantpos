<?php

namespace Tests;

use App\Actions\Tenancy\RegisterTenant;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\Terminal;
use App\Models\User;
use App\Tenancy\BranchContext;
use App\Tenancy\TenantContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\SubscriptionPlanSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * Tests run against MySQL 8 (avantpos_test), the same engine as production.
 *
 * The schema is migrated ONCE per run with `migrate` (not migrate:fresh —
 * DDL is slow on Windows) and every test is wrapped in a transaction that
 * is rolled back. After EDITING an existing migration, rebuild the test DB:
 *
 *   php artisan migrate:fresh --env=testing --database=mysql
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    private static bool $schemaReady = false;

    public const PASSWORD = 'Secret123';

    public function createApplication()
    {
        $app = parent::createApplication();

        if (! self::$schemaReady) {
            Artisan::call('migrate', ['--force' => true]);

            // Global reference data persisted outside the per-test transactions.
            if (! DB::table('subscription_plans')->exists()) {
                Artisan::call('db:seed', ['--class' => SubscriptionPlanSeeder::class, '--force' => true]);
            }
            Artisan::call('db:seed', ['--class' => PermissionSeeder::class, '--force' => true]);

            self::$schemaReady = true;
        }

        return $app;
    }

    /**
     * Register a complete tenant (owner, main branch, roles, trial) through
     * the real registration action.
     *
     * @param  array<string, mixed>  $overrides
     * @return array{tenant: Tenant, owner: User, branch: Branch}
     */
    public function createTenant(array $overrides = []): array
    {
        $suffix = Str::lower(Str::random(6));

        Event::fakeFor(function () use ($overrides, $suffix, &$result) {
            $result = app(RegisterTenant::class)->handle($overrides + [
                'business_name' => "Test Store {$suffix}",
                'business_type' => 'sari_sari',
                'owner_name' => "Owner {$suffix}",
                'email' => "owner-{$suffix}@example.com",
                'phone' => '+639171234567',
                'password' => self::PASSWORD,
            ]);
        }, [Registered::class]);

        $result['user']->forceFill(['email_verified_at' => now()])->save();

        $branch = $this->asTenant($result['tenant'], fn () => Branch::query()->where('is_main', true)->firstOrFail());

        return ['tenant' => $result['tenant'], 'owner' => $result['user'], 'branch' => $branch];
    }

    /**
     * Create a verified user with a system role inside a tenant.
     *
     * @param  list<int>|null  $branchIds  null = main branch
     */
    public function createUser(Tenant $tenant, string $roleKey, ?array $branchIds = null, array $attributes = []): User
    {
        return $this->asTenant($tenant, function () use ($roleKey, $branchIds, $attributes) {
            $user = User::query()->create($attributes + [
                'name' => 'User '.Str::random(5),
                'email' => Str::lower(Str::random(10)).'@example.com',
                'password' => self::PASSWORD,
                'status' => 'active',
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->assignRole(Role::forCurrentTenant()->where('key', $roleKey)->firstOrFail());
            $user->syncBranches($branchIds ?? [Branch::query()->where('is_main', true)->value('id')]);

            return $user->refresh();
        });
    }

    public function createTerminal(Tenant $tenant, ?Branch $branch = null): Terminal
    {
        return $this->asTenant($tenant, fn () => Terminal::query()->create([
            'branch_id' => $branch?->id ?? Branch::query()->where('is_main', true)->value('id'),
            'code' => 'POS-'.Str::upper(Str::random(4)),
            'name' => 'Counter',
            'status' => 'active',
        ]));
    }

    /**
     * @template T
     *
     * @param  callable(Tenant): T  $callback
     * @return T
     */
    /**
     * Each simulated HTTP request starts like a real one: per-request state
     * (tenant / branch context, bound with scoped()) left over from an earlier
     * request or from test setup is dropped. Without this, a request could run
     * with a tenant it would never have in production (and hide bugs, e.g. a
     * platform admin page querying tenant data without lifting the scope).
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        // Reset the state of the existing instances (controllers and services
        // resolved by earlier requests keep references to them).
        app(TenantContext::class)->forget();
        app(BranchContext::class)->forget();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    public function asTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($tenant, $callback(...));
    }

    /**
     * Authenticate API calls as a tenant user (or device) via Sanctum.
     */
    public function actingAsApi(User|Terminal $actor, array $abilities = ['*']): static
    {
        Sanctum::actingAs($actor, $abilities);

        return $this;
    }
}

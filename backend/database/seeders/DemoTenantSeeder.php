<?php

namespace Database\Seeders;

use App\Actions\Tenancy\RegisterTenant;
use App\Enums\UserStatus;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Role;
use App\Models\Terminal;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * LOCAL DEVELOPMENT ONLY: two fictional businesses with owner, manager and
 * cashier accounts, a second branch and POS terminals.
 *
 * Password for every demo account: DEMO_PASSWORD (default "Password123").
 * Demo PIN for managers/cashiers: 2468.
 */
class DemoTenantSeeder extends Seeder
{
    public function run(RegisterTenant $register, TenantContext $context): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoTenantSeeder must never run in production.');
        }

        if (User::withoutTenantScope()->where('email', 'owner@motoshop.test')->exists()) {
            $this->command?->info('Demo tenants already exist — skipped.');

            return;
        }

        $password = env('DEMO_PASSWORD', 'Password123');

        $businesses = [
            [
                'business_name' => 'TRD Motor Parts', 'business_type' => 'motor_shop', 'subdomain' => 'trdmotorparts',
                'vat_registered' => true, 'owner_name' => 'Demo Motoshop Owner', 'email' => 'owner@motoshop.test',
                'phone' => '+639171234567', 'domain' => 'motoshop.test', 'second_branch' => 'Lapu-Lapu Branch',
            ],
            [
                'business_name' => 'Tindahan ni Aling Nena', 'business_type' => 'sari_sari', 'subdomain' => null,
                'vat_registered' => false, 'owner_name' => 'Demo Sari-Sari Owner', 'email' => 'owner@sarisari.test',
                'phone' => '+639181234567', 'domain' => 'sarisari.test', 'second_branch' => null,
            ],
        ];

        // Don't queue verification e-mails for demo accounts.
        Event::fake([Registered::class]);

        foreach ($businesses as $b) {
            ['tenant' => $tenant, 'user' => $owner] = $register->handle($b + ['password' => $password]);
            $owner->forceFill(['email_verified_at' => now()])->save();

            $context->runAs($tenant, function () use ($b, $owner, $password) {
                $owner->setPin('2468');
                $main = Branch::query()->where('is_main', true)->firstOrFail();
                $branches = [$main];

                if ($b['second_branch']) {
                    $branches[] = Branch::query()->create(['code' => 'BR2', 'name' => $b['second_branch'], 'is_active' => true]);
                }

                foreach ($branches as $i => $branch) {
                    $terminal = Terminal::query()->create([
                        'branch_id' => $branch->id,
                        'code' => 'POS-'.($i + 1),
                        'name' => 'Counter '.($i + 1),
                        'status' => 'active',
                    ]);
                    CashRegister::query()->create([
                        'branch_id' => $branch->id,
                        'terminal_id' => $terminal->id,
                        'name' => 'Drawer '.($i + 1),
                    ]);
                }

                $this->makeUser('Demo Branch Manager', "manager@{$b['domain']}", 'branch_manager', [$main->id], $password);
                $this->makeUser('Demo Cashier', "cashier@{$b['domain']}", 'cashier', [$main->id], $password);
            });

            $this->command?->info("Demo tenant: {$b['business_name']} (owner@{$b['domain']}, manager@{$b['domain']}, cashier@{$b['domain']})");
        }

        $this->call(DemoCatalogSeeder::class);

        $this->command?->warn("Demo password: {$password}   Demo PIN: 2468");
    }

    /**
     * @param  list<int>  $branchIds
     */
    private function makeUser(string $name, string $email, string $roleKey, array $branchIds, string $password): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'status' => UserStatus::Active,
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole(Role::forCurrentTenant()->where('key', $roleKey)->firstOrFail());
        $user->syncBranches($branchIds);
        $user->setPin('2468');

        return $user;
    }
}

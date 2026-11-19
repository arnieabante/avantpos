<?php

use App\Models\PlatformAdmin;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\BillingService;
use App\Services\Inventory\InventoryVerifier;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Subscription lifecycle: renewal invoices and reminders, trials ending,
| past due → expired, scheduled downgrades and cancellations.
| Safe to run more than once a day (each step is done once).
*/
Artisan::command('billing:run', function (BillingService $billing) {
    $counts = $billing->runLifecycle();
    $this->info(collect($counts)->map(fn ($n, $k) => "{$k}: {$n}")->implode(', '));
})->purpose('Run the daily subscription billing cycle');

Schedule::command('billing:run')->dailyAt('01:00')->timezone('Asia/Manila')->withoutOverlapping();

/*
| Stock ledger consistency check (read-only). Exit code 1 and an error log
| entry when anything is inconsistent. Runs nightly; also safe on demand.
*/
Artisan::command('inventory:verify {--tenant= : Only this business (tenant id)}', function (InventoryVerifier $verifier) {
    $tenant = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
    $issues = $verifier->verify($tenant);
    if ($issues === []) {
        $this->info('Inventory is consistent: ledger, running balances, FIFO layers and stock value all agree.');

        return 0;
    }
    $this->table(['Check', 'Tenant', 'Branch', 'Variant', 'Detail'], array_map(fn ($i) => array_values($i), array_slice($issues, 0, 200)));
    $this->error(count($issues).' inconsistency(ies) found.');
    Log::error('inventory:verify found inconsistencies', ['count' => count($issues), 'first' => array_slice($issues, 0, 20)]);

    return 1;
})->purpose('Check that stock balances, movements and FIFO cost layers agree');

Schedule::command('inventory:verify')->dailyAt('02:30')->timezone('Asia/Manila')->withoutOverlapping();

/*
| Create a platform admin, or reset an existing one's password (e.g. a
| locked-out super admin). Prints a generated password unless --password is given.
*/
Artisan::command('avantpos:admin {email} {--name=} {--role=super_admin : super_admin, billing or support} {--password=} {--reset-2fa : Also turn off two-factor sign-in (lost phone)}', function () {
    $role = (string) $this->option('role');
    if (! in_array($role, [PlatformAdmin::ROLE_SUPER_ADMIN, PlatformAdmin::ROLE_BILLING, PlatformAdmin::ROLE_SUPPORT], true)) {
        $this->error('Role must be super_admin, billing or support.');

        return 1;
    }
    $email = Str::lower((string) $this->argument('email'));
    $password = (string) ($this->option('password') ?: Str::password(16, symbols: false));
    $admin = PlatformAdmin::query()->firstWhere('email', $email);
    if ($admin) {
        $admin->forceFill(['password' => $password, 'is_active' => true, 'remember_token' => null, 'must_change_password' => true])->save();
        if ($this->option('reset-2fa')) {
            $admin->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
            $this->info('Two-factor sign-in turned off; set it up again after signing in.');
        }
        $this->info("Password reset for {$admin->email} ({$admin->role}); the account is active.");
    } else {
        $admin = PlatformAdmin::query()->create(['name' => $this->option('name') ?: Str::before($email, '@'), 'email' => $email,
            'password' => $password, 'role' => $role, 'is_active' => true]);
        $admin->forceFill(['must_change_password' => true])->save();
        $this->info("Platform admin created: {$admin->email} ({$role}).");
    }
    app(AuditLogger::class)->log('platform.admin_cli', $admin, new: ['email' => $admin->email, 'role' => $admin->role]);
    if (! $this->option('password')) {
        $this->warn("Password (shown once): {$password}");
    }

    return 0;
})->purpose('Create a platform admin or reset one\'s password');

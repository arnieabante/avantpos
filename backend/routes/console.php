<?php

use App\Services\Billing\BillingService;
use App\Services\Inventory\InventoryVerifier;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

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

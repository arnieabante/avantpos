<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * NOTE: never use WithoutModelEvents here — tenant-owned models rely on
 * model events to receive their tenant_id (BelongsToTenant).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Reference & platform data (safe for every environment, idempotent).
        $this->call([
            PermissionSeeder::class,
            SubscriptionPlanSeeder::class,
            PsgcSeeder::class,
            PlatformAdminSeeder::class,
        ]);

        // Demo businesses for local development only.
        if (app()->environment('local')) {
            $this->call(DemoTenantSeeder::class);
        }
    }
}

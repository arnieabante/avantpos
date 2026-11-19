<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * `php artisan db:seed` (every environment, safe to run again):
 *  - reference & platform data: permissions, plans, PH addresses, first platform admin;
 *  - the launch businesses in database/seeders/data/businesses.php (users and setup, no products).
 *
 * The old fictional demo stores are only created on request (local only):
 *   php artisan db:seed --class=DemoTenantSeeder
 *
 * NOTE: never use WithoutModelEvents here — tenant-owned models rely on
 * model events to receive their tenant_id (BelongsToTenant).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            SubscriptionPlanSeeder::class,
            PsgcSeeder::class,
            PlatformAdminSeeder::class,
            BusinessSeeder::class,
        ]);
    }
}

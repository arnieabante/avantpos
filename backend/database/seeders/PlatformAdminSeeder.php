<?php

namespace Database\Seeders;

use App\Models\PlatformAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the first SaaS super administrator if none exists.
 *
 * Credentials come from PLATFORM_ADMIN_EMAIL / PLATFORM_ADMIN_PASSWORD.
 * Outside production, a random password is generated and printed once when
 * the variable is not set. In production both variables are required.
 */
class PlatformAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (PlatformAdmin::query()->exists()) {
            return;
        }

        $email = env('PLATFORM_ADMIN_EMAIL');
        $password = env('PLATFORM_ADMIN_PASSWORD');

        if (app()->isProduction() && (! $email || ! $password)) {
            $this->command?->error('Set PLATFORM_ADMIN_EMAIL and PLATFORM_ADMIN_PASSWORD to create the first platform admin.');

            return;
        }

        $email = strtolower($email ?: 'admin@avantpos.test');
        $generated = ! $password;
        $password = $password ?: Str::password(16, symbols: false);

        PlatformAdmin::query()->create([
            'name' => 'AvantPOS Super Admin',
            'email' => $email,
            'password' => $password,
            'role' => PlatformAdmin::ROLE_SUPER_ADMIN,
            'is_active' => true,
        ]);

        $this->command?->info("Platform admin created: {$email}");
        if ($generated) {
            $this->command?->warn("Generated password (shown once): {$password}");
        }
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Basic now has the same features as Starter (purchasing & supplier bills,
 * expenses, batches & expiry dates, custom roles); the plans differ only in
 * their limits (branches, users, terminals, products).
 */
return new class extends Migration
{
    private const FEATURES = ['purchasing', 'expenses', 'batch_expiry', 'custom_roles'];

    public function up(): void
    {
        $this->set(true);
    }

    public function down(): void
    {
        $this->set(false);
    }

    private function set(bool $enabled): void
    {
        $basic = DB::table('subscription_plans')->where('code', 'basic')->value('id');
        if (! $basic) {
            return;
        }
        foreach (self::FEATURES as $feature) {
            DB::table('plan_features')->updateOrInsert(
                ['subscription_plan_id' => $basic, 'feature_key' => $feature],
                ['enabled' => $enabled, 'updated_at' => now()],
            );
        }
    }
};

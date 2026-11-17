<?php

namespace Database\Seeders;

use App\Models\PlanFeature;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * Creates the default plans if missing. Existing plans are NOT overwritten
 * (prices/limits are managed by the SaaS admin); only missing feature rows
 * are added (disabled by default for existing plans).
 */
class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $config = config('avantpos.plans');
        $order = 0;

        foreach ($config['plans'] as $code => $definition) {
            $order++;

            $plan = SubscriptionPlan::query()->firstOrCreate(['code' => $code], [
                'name' => $definition['name'],
                'monthly_price' => $definition['monthly_price'],
                'annual_price' => $definition['annual_price'],
                'trial_days' => $definition['trial_days'],
                'grace_days' => $definition['grace_days'],
                'max_branches' => $definition['max_branches'],
                'max_users' => $definition['max_users'],
                'max_terminals' => $definition['max_terminals'],
                'max_products' => $definition['max_products'],
                'is_public' => true,
                'is_active' => true,
                'sort_order' => $order,
            ]);

            $isNew = $plan->wasRecentlyCreated;

            foreach ($config['feature_keys'] as $feature) {
                PlanFeature::query()->firstOrCreate(
                    ['subscription_plan_id' => $plan->getKey(), 'feature_key' => $feature],
                    ['enabled' => $isNew ? (bool) ($definition['features'][$feature] ?? false) : false],
                );
            }
        }

        $this->command?->info('Subscription plans ready: '.implode(', ', array_keys($config['plans'])));
    }
}

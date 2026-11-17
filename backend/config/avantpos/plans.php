<?php

/*
|--------------------------------------------------------------------------
| Default subscription plans
|--------------------------------------------------------------------------
|
| Seed values only. After seeding, plans are managed by the SaaS
| administrator in the platform admin module; the seeder never overwrites
| an existing plan's prices or limits.
|
| Prices are in PHP (VAT-inclusive). `null` limit = unlimited.
| There is no free plan: new businesses get a trial (config avantpos.app.trial).
|
*/

$features = [
    'pos', 'inventory', 'customers', 'receipts_thermal', 'offline_pos',     // core
    'multi_branch', 'stock_transfers', 'purchasing', 'expenses',            // growth
    'loyalty', 'credit_sales', 'batch_expiry', 'unit_conversions',
    'advanced_reports', 'report_exports', 'custom_roles', 'custom_subdomain',
    'audit_logs', 'api_access', 'priority_support',
];

$on = fn (array $keys) => array_fill_keys($keys, true) + array_fill_keys($features, false);

$core = ['pos', 'inventory', 'customers', 'receipts_thermal', 'offline_pos'];

// Basic and Starter: same features, different limits.
$starterFeatures = [...$core, 'unit_conversions', 'report_exports', 'purchasing', 'expenses', 'batch_expiry', 'custom_roles'];

return [

    'feature_keys' => $features,

    'plans' => [
        'basic' => [
            'name' => 'Basic',
            'monthly_price' => '499.00', 'annual_price' => '4990.00',
            'trial_days' => 14, 'grace_days' => 7,
            'max_branches' => 1, 'max_users' => 3, 'max_terminals' => 1, 'max_products' => 1000,
            // Same features as Starter; Basic differs only in its limits.
            'features' => $on($starterFeatures),
        ],
        'starter' => [
            'name' => 'Starter',
            'monthly_price' => '999.00', 'annual_price' => '9990.00',
            'trial_days' => 14, 'grace_days' => 7,
            'max_branches' => 1, 'max_users' => 5, 'max_terminals' => 2, 'max_products' => 5000,
            'features' => $on($starterFeatures),
        ],
        'professional' => [
            'name' => 'Professional',
            'monthly_price' => '1999.00', 'annual_price' => '19990.00',
            'trial_days' => 14, 'grace_days' => 7,
            'max_branches' => 3, 'max_users' => 15, 'max_terminals' => 6, 'max_products' => null,
            'features' => $on([...$core, 'unit_conversions', 'report_exports', 'purchasing', 'expenses',
                'batch_expiry', 'custom_roles', 'multi_branch', 'stock_transfers', 'loyalty',
                'credit_sales', 'advanced_reports', 'custom_subdomain', 'audit_logs']),
        ],
        'business' => [
            'name' => 'Business',
            'monthly_price' => '3999.00', 'annual_price' => '39990.00',
            'trial_days' => 14, 'grace_days' => 14,
            'max_branches' => 10, 'max_users' => 50, 'max_terminals' => 25, 'max_products' => null,
            'features' => $on(array_diff($features, ['api_access'])),
        ],
        'enterprise' => [
            'name' => 'Enterprise',
            'monthly_price' => '7999.00', 'annual_price' => '79990.00',
            'trial_days' => 30, 'grace_days' => 30,
            'max_branches' => null, 'max_users' => null, 'max_terminals' => null, 'max_products' => null,
            'features' => $on($features),
        ],
    ],

];

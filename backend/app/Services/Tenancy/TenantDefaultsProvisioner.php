<?php

namespace App\Services\Tenancy;

use App\Models\ExpenseCategory;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Seeds sensible Philippine defaults for a new tenant: payment methods,
 * tax rates, units of measure, statutory discounts and receipt templates.
 * Everything is editable later in Business Settings.
 */
class TenantDefaultsProvisioner
{
    public function provision(Tenant $tenant): void
    {
        $now = now();
        $base = ['tenant_id' => $tenant->getKey(), 'created_at' => $now, 'updated_at' => $now];
        $defaults = config('avantpos.app.defaults');

        DB::table('payment_methods')->insert(array_map(fn (array $row) => $base + $row, [
            ['type' => 'cash', 'name' => 'Cash', 'requires_reference' => false, 'sort_order' => 1],
            ['type' => 'gcash', 'name' => 'GCash', 'requires_reference' => true, 'sort_order' => 2],
            ['type' => 'maya', 'name' => 'Maya', 'requires_reference' => true, 'sort_order' => 3],
            ['type' => 'bank_transfer', 'name' => 'Bank Transfer', 'requires_reference' => true, 'sort_order' => 4],
            ['type' => 'card', 'name' => 'Debit/Credit Card', 'requires_reference' => true, 'sort_order' => 5],
        ]));

        DB::table('tax_rates')->insert([
            $base + ['name' => 'VAT', 'type' => 'vat', 'rate' => $defaults['vat_rate'], 'is_default' => $tenant->vat_registered],
            $base + ['name' => 'Percentage Tax', 'type' => 'percentage_tax', 'rate' => $defaults['percentage_tax_rate'], 'is_default' => ! $tenant->vat_registered],
        ]);

        DB::table('units')->insert(array_map(fn (array $row) => $base + $row, [
            ['name' => 'Piece', 'abbreviation' => 'pc', 'allows_decimal' => false],
            ['name' => 'Pack', 'abbreviation' => 'pack', 'allows_decimal' => false],
            ['name' => 'Box', 'abbreviation' => 'box', 'allows_decimal' => false],
            ['name' => 'Dozen', 'abbreviation' => 'doz', 'allows_decimal' => false],
            ['name' => 'Kilogram', 'abbreviation' => 'kg', 'allows_decimal' => true],
            ['name' => 'Gram', 'abbreviation' => 'g', 'allows_decimal' => true],
            ['name' => 'Liter', 'abbreviation' => 'L', 'allows_decimal' => true],
            ['name' => 'Milliliter', 'abbreviation' => 'mL', 'allows_decimal' => true],
            ['name' => 'Meter', 'abbreviation' => 'm', 'allows_decimal' => true],
            ['name' => 'Set', 'abbreviation' => 'set', 'allows_decimal' => false],
        ]));

        DB::table('discount_presets')->insert([
            $base + ['name' => 'Senior Citizen (20%)', 'type' => 'percent', 'value' => '20.0000', 'scope' => 'line', 'statutory' => 'senior', 'requires_permission' => false],
            $base + ['name' => 'PWD (20%)', 'type' => 'percent', 'value' => '20.0000', 'scope' => 'line', 'statutory' => 'pwd', 'requires_permission' => false],
        ]);

        DB::table('expense_categories')->insert(array_map(fn (string $name) => $base + ['name' => $name, 'is_active' => true], ExpenseCategory::DEFAULTS));

        DB::table('receipt_templates')->insert([
            $base + ['name' => 'Thermal 80mm', 'paper' => '80mm', 'footer_text' => 'Thank you for your purchase!', 'show_logo' => true, 'is_default' => true],
            $base + ['name' => 'Thermal 58mm', 'paper' => '58mm', 'footer_text' => 'Thank you!', 'show_logo' => false, 'is_default' => false],
            $base + ['name' => 'A4 Invoice', 'paper' => 'a4', 'footer_text' => null, 'show_logo' => true, 'is_default' => false],
        ]);
    }
}

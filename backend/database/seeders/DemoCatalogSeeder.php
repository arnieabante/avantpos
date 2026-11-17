<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Brand;
use App\Models\ProductCategory;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\Catalog\ProductService;
use App\Services\Inventory\StockAdjustmentService;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * LOCAL DEVELOPMENT / DEMO ONLY: a motorcycle parts catalogue for the demo
 * motor shop (TRD Motor Parts): oils and fluids, parts, tires, batteries,
 * accessories and shop services, with opening stock in every branch.
 *
 * Everything goes through the real services (product rules, FIFO cost
 * layers, stock ledger, document numbers), exactly as if typed in the app.
 * Barcodes are in-store codes (EAN-13 prefix 200), not manufacturer codes.
 */
class DemoCatalogSeeder extends Seeder
{
    private const OWNER_EMAIL = 'owner@motoshop.test';

    public function run(TenantContext $context, ProductService $products, StockAdjustmentService $adjustments): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoCatalogSeeder must never run in production.');
        }

        $owner = User::withoutTenantScope()->where('email', self::OWNER_EMAIL)->first();
        if (! $owner) {
            $this->command?->warn('Demo motor shop not found — catalogue skipped.');

            return;
        }
        $tenant = Tenant::query()->findOrFail($owner->tenant_id);

        $context->runAs($tenant, function () use ($products, $adjustments, $owner) {
            $units = Unit::query()->pluck('id', 'abbreviation');
            $brandIds = [];
            $categoryIds = [];
            $sequence = 0;
            $stock = []; // variant id => [main qty, other branch qty, unit cost]

            foreach ($this->catalogue() as $category => $items) {
                $categoryIds[$category] ??= ProductCategory::query()->create(['name' => $category, 'is_active' => true, 'sort_order' => count($categoryIds) + 1])->id;

                foreach ($items as $item) {
                    $brandId = null;
                    if ($item['brand'] ?? null) {
                        $brandId = $brandIds[$item['brand']] ??= Brand::query()->create(['name' => $item['brand']])->id;
                    }
                    $service = ($item['type'] ?? 'standard') === 'service';

                    $variants = [];
                    foreach ($item['variants'] ?? [['name' => null]] as $v) {
                        $sequence++;
                        $variants[] = [
                            'name' => $v['name'],
                            'attributes' => isset($v['size']) ? ['Size' => $v['size']] : null,
                            'cost_price' => $item['cost'],
                            'selling_price' => $item['price'],
                            'reorder_level' => $service ? '0' : (string) ($item['reorder'] ?? 5),
                            'barcodes' => $service ? [] : [$this->barcode($sequence)],
                            'units' => array_map(fn (array $u) => [
                                'unit_id' => $units[$u['unit']],
                                'conversion_factor' => (string) $u['factor'],
                                'selling_price' => $u['price'],
                                'is_sellable' => true,
                                'is_purchasable' => true,
                                'barcodes' => [],
                            ], $item['packs'] ?? []),
                        ];
                    }

                    $product = $products->create([
                        'name' => $item['name'],
                        'description' => $item['description'] ?? null,
                        'category_id' => $categoryIds[$category],
                        'brand_id' => $brandId,
                        'base_unit_id' => $units[$item['unit'] ?? 'pc'],
                        'type' => $service ? 'service' : 'standard',
                        'tax_class' => 'vatable',
                        'sc_pwd_eligible' => false,
                        'track_inventory' => ! $service,
                        'track_expiry' => false,
                        'allow_negative_stock' => false,
                        'variants' => $variants,
                    ]);

                    if (! $service) {
                        foreach ($product->variants()->get() as $variant) {
                            $stock[$variant->id] = [$item['stock'][0], $item['stock'][1], $item['cost']];
                        }
                    }
                }
            }

            // Opening stock: one posted adjustment per branch.
            $branches = Branch::query()->where('is_active', true)->orderByDesc('is_main')->orderBy('id')->get();
            foreach ($branches as $i => $branch) {
                $lines = [];
                foreach ($stock as $variantId => [$main, $other, $cost]) {
                    $qty = $i === 0 ? $main : $other;
                    if ($qty > 0) {
                        $lines[] = ['product_variant_id' => $variantId, 'qty' => (string) $qty, 'unit_cost' => $cost];
                    }
                }
                if ($lines !== []) {
                    $adjustments->create($branch, ['reason_code' => 'opening', 'notes' => 'Opening stock (demo data)', 'items' => $lines], $owner, post: true);
                }
            }

            $this->command?->info(sprintf('Demo catalogue: %d stocked items, %d categories, %d brands, opening stock in %d branch(es).',
                count($stock), count($categoryIds), count($brandIds), $branches->count()));
        });
    }

    /** In-store EAN-13 (prefix 200) with a valid check digit. */
    private function barcode(int $n): string
    {
        $code = '200'.str_pad((string) $n, 9, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach (str_split($code) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return $code.((10 - $sum % 10) % 10);
    }

    /**
     * Category => items. Prices are VAT-inclusive selling prices; cost is the
     * supplier cost per base unit. stock = [main branch, other branches].
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function catalogue(): array
    {
        return [
            'Engine Oil' => [
                ['name' => 'Motul 3000 Plus 4T 10W-40 1L', 'brand' => 'Motul', 'price' => '380.00', 'cost' => '290.00', 'reorder' => 12, 'stock' => [48, 24],
                    'packs' => [['unit' => 'box', 'factor' => 12, 'price' => '4380.00']]],
                ['name' => 'Motul 5100 4T 10W-40 Semi-Synthetic 1L', 'brand' => 'Motul', 'price' => '520.00', 'cost' => '400.00', 'reorder' => 6, 'stock' => [24, 12]],
                ['name' => 'Motul 7100 4T 10W-40 Full Synthetic 1L', 'brand' => 'Motul', 'price' => '780.00', 'cost' => '610.00', 'reorder' => 4, 'stock' => [12, 6]],
                ['name' => 'Shell Advance AX7 10W-40 Semi-Synthetic 1L', 'brand' => 'Shell', 'price' => '410.00', 'cost' => '315.00', 'reorder' => 10, 'stock' => [36, 18],
                    'packs' => [['unit' => 'box', 'factor' => 12, 'price' => '4740.00']]],
                ['name' => 'Shell Advance AX5 20W-40 0.8L', 'brand' => 'Shell', 'price' => '260.00', 'cost' => '195.00', 'reorder' => 12, 'stock' => [40, 20]],
                ['name' => 'Castrol Power1 4T 10W-40 1L', 'brand' => 'Castrol', 'price' => '430.00', 'cost' => '330.00', 'reorder' => 8, 'stock' => [24, 12]],
                ['name' => 'Yamalube 4T 20W-40 0.8L', 'brand' => 'Yamalube', 'price' => '250.00', 'cost' => '190.00', 'reorder' => 12, 'stock' => [36, 24]],
                ['name' => 'Yamalube Scooter Oil 10W-40 0.8L', 'brand' => 'Yamalube', 'price' => '285.00', 'cost' => '215.00', 'reorder' => 10, 'stock' => [30, 15]],
                ['name' => 'Petron Sprint 4T Rider 20W-40 1L', 'brand' => 'Petron', 'price' => '230.00', 'cost' => '170.00', 'reorder' => 12, 'stock' => [48, 24]],
                ['name' => 'Honda 4T Scooter Oil 10W-30 0.8L', 'brand' => 'Honda Genuine', 'price' => '240.00', 'cost' => '180.00', 'reorder' => 10, 'stock' => [6, 4]], // low stock
            ],
            'Gear Oil' => [
                ['name' => 'Motul Scooter Gear Plus 80W-90 120mL', 'brand' => 'Motul', 'price' => '160.00', 'cost' => '115.00', 'reorder' => 12, 'stock' => [40, 20]],
                ['name' => 'Yamalube Gear Oil 100mL', 'brand' => 'Yamalube', 'price' => '110.00', 'cost' => '78.00', 'reorder' => 12, 'stock' => [48, 24]],
                ['name' => 'Shell Advance Scooter Gear Oil 120mL', 'brand' => 'Shell', 'price' => '135.00', 'cost' => '98.00', 'reorder' => 12, 'stock' => [36, 18]],
                ['name' => 'Honda Transmission Oil 120mL', 'brand' => 'Honda Genuine', 'price' => '125.00', 'cost' => '90.00', 'reorder' => 12, 'stock' => [30, 12]],
            ],
            'Fluids & Chemicals' => [
                ['name' => 'Prestone Brake Fluid DOT 3 300mL', 'brand' => 'Prestone', 'price' => '180.00', 'cost' => '130.00', 'reorder' => 6, 'stock' => [20, 10]],
                ['name' => 'Motul Motocool Expert Coolant 1L', 'brand' => 'Motul', 'price' => '420.00', 'cost' => '320.00', 'reorder' => 4, 'stock' => [12, 6]],
                ['name' => 'Motul C2 Chain Lube Road 400mL', 'brand' => 'Motul', 'price' => '595.00', 'cost' => '460.00', 'reorder' => 4, 'stock' => [10, 5]],
                ['name' => 'WD-40 Multi-Use Spray 191mL', 'brand' => 'WD-40', 'price' => '245.00', 'cost' => '185.00', 'reorder' => 6, 'stock' => [18, 8]],
                ['name' => 'Motul Carbu Clean 300mL', 'brand' => 'Motul', 'price' => '350.00', 'cost' => '265.00', 'reorder' => 4, 'stock' => [12, 6]],
            ],
            'Spark Plugs' => [
                ['name' => 'NGK Spark Plug CPR8EA-9', 'brand' => 'NGK', 'price' => '180.00', 'cost' => '125.00', 'reorder' => 20, 'stock' => [60, 30],
                    'packs' => [['unit' => 'box', 'factor' => 10, 'price' => '1650.00']]],
                ['name' => 'NGK Spark Plug C7HSA', 'brand' => 'NGK', 'price' => '150.00', 'cost' => '100.00', 'reorder' => 20, 'stock' => [60, 30],
                    'packs' => [['unit' => 'box', 'factor' => 10, 'price' => '1380.00']]],
                ['name' => 'NGK Spark Plug CR7HSA', 'brand' => 'NGK', 'price' => '165.00', 'cost' => '112.00', 'reorder' => 20, 'stock' => [50, 20],
                    'packs' => [['unit' => 'box', 'factor' => 10, 'price' => '1520.00']]],
                ['name' => 'Denso Iridium Power Spark Plug IU24', 'brand' => 'Denso', 'price' => '650.00', 'cost' => '480.00', 'reorder' => 4, 'stock' => [10, 4]],
            ],
            'Filters' => [
                ['name' => 'Oil Filter — Yamaha NMAX / Aerox 155', 'brand' => 'Yamaha Genuine', 'price' => '220.00', 'cost' => '150.00', 'reorder' => 8, 'stock' => [24, 12]],
                ['name' => 'Air Filter — Honda Click 125i / 150i', 'brand' => 'Honda Genuine', 'price' => '380.00', 'cost' => '270.00', 'reorder' => 6, 'stock' => [15, 8]],
                ['name' => 'Air Filter — Yamaha Mio i 125', 'brand' => 'Yamaha Genuine', 'price' => '320.00', 'cost' => '225.00', 'reorder' => 6, 'stock' => [15, 8]],
                ['name' => 'Air Filter — Suzuki Raider R150 Fi', 'brand' => 'Suzuki Genuine', 'price' => '350.00', 'cost' => '245.00', 'reorder' => 4, 'stock' => [3, 2]], // low stock
            ],
            'Brakes' => [
                ['name' => 'Disc Brake Pads Front — Honda Click 125i', 'brand' => 'Elig', 'price' => '350.00', 'cost' => '240.00', 'reorder' => 6, 'stock' => [20, 10]],
                ['name' => 'Disc Brake Pads Front — Yamaha NMAX 155', 'brand' => 'Elig', 'price' => '420.00', 'cost' => '290.00', 'reorder' => 6, 'stock' => [18, 8]],
                ['name' => 'Brake Shoe Rear — Yamaha Mio', 'brand' => 'Elig', 'price' => '280.00', 'cost' => '190.00', 'reorder' => 6, 'stock' => [20, 10]],
                ['name' => 'Brake Shoe Rear — Honda Wave 125', 'brand' => 'Elig', 'price' => '260.00', 'cost' => '175.00', 'reorder' => 6, 'stock' => [16, 8]],
            ],
            'Drive & CVT Parts' => [
                ['name' => 'RK Chain & Sprocket Set 428 — Raider 150', 'brand' => 'RK', 'unit' => 'set', 'price' => '1850.00', 'cost' => '1350.00', 'reorder' => 3, 'stock' => [8, 4]],
                ['name' => 'DID Chain 428H 120L', 'brand' => 'DID', 'price' => '950.00', 'cost' => '700.00', 'reorder' => 3, 'stock' => [10, 4]],
                ['name' => 'Drive Belt — Honda Click 125i', 'brand' => 'Bando', 'price' => '780.00', 'cost' => '560.00', 'reorder' => 4, 'stock' => [12, 6]],
                ['name' => 'Drive Belt — Yamaha NMAX 155', 'brand' => 'Bando', 'price' => '950.00', 'cost' => '690.00', 'reorder' => 4, 'stock' => [10, 4]],
                ['name' => 'CVT Flyball / Roller Set — Mio (9g)', 'brand' => 'JVT', 'unit' => 'set', 'price' => '350.00', 'cost' => '230.00', 'reorder' => 5, 'stock' => [15, 6]],
                ['name' => 'Clutch Spring Set 1000 RPM — Mio', 'brand' => 'JVT', 'unit' => 'set', 'price' => '450.00', 'cost' => '300.00', 'reorder' => 4, 'stock' => [10, 4]],
            ],
            'Tires & Tubes' => [
                ['name' => 'IRC NR73 Tire 70/90-17 (Tube Type)', 'brand' => 'IRC', 'price' => '1150.00', 'cost' => '850.00', 'reorder' => 4, 'stock' => [12, 6]],
                ['name' => 'IRC NR73 Tire 80/90-17 (Tube Type)', 'brand' => 'IRC', 'price' => '1290.00', 'cost' => '960.00', 'reorder' => 4, 'stock' => [10, 6]],
                ['name' => 'Michelin Pilot Street 80/90-14 Tubeless', 'brand' => 'Michelin', 'price' => '1750.00', 'cost' => '1320.00', 'reorder' => 3, 'stock' => [8, 4]],
                ['name' => 'Pirelli Diablo Rosso Scooter 110/70-13 Tubeless', 'brand' => 'Pirelli', 'price' => '3250.00', 'cost' => '2550.00', 'reorder' => 2, 'stock' => [6, 2]],
                ['name' => 'IRC Inner Tube 2.50-17', 'brand' => 'IRC', 'price' => '260.00', 'cost' => '180.00', 'reorder' => 8, 'stock' => [24, 12]],
            ],
            'Batteries' => [
                ['name' => 'Motolite MF5L-B Maintenance-Free Battery', 'brand' => 'Motolite', 'price' => '1150.00', 'cost' => '860.00', 'reorder' => 3, 'stock' => [8, 4]],
                ['name' => 'Yuasa YTZ5S Maintenance-Free Battery', 'brand' => 'Yuasa', 'price' => '1950.00', 'cost' => '1480.00', 'reorder' => 2, 'stock' => [6, 3]],
                ['name' => 'Outlast YTX7L-BS Battery', 'brand' => 'Outlast', 'price' => '1450.00', 'cost' => '1050.00', 'reorder' => 2, 'stock' => [1, 0]], // low stock
            ],
            'Electrical & Lights' => [
                ['name' => 'LED Headlight Bulb H4 (Hi/Lo)', 'brand' => null, 'price' => '650.00', 'cost' => '420.00', 'reorder' => 4, 'stock' => [12, 6]],
                ['name' => 'Signal Light Bulb 12V 10W', 'brand' => null, 'price' => '35.00', 'cost' => '18.00', 'reorder' => 20, 'stock' => [100, 50],
                    'packs' => [['unit' => 'box', 'factor' => 10, 'price' => '300.00']]],
                ['name' => 'Bosch EC6 Disc Horn 12V', 'brand' => 'Bosch', 'price' => '450.00', 'cost' => '320.00', 'reorder' => 3, 'stock' => [8, 4]],
                ['name' => 'Blade Fuse Assortment (10 pcs)', 'brand' => null, 'unit' => 'pack', 'price' => '120.00', 'cost' => '70.00', 'reorder' => 5, 'stock' => [15, 8]],
            ],
            'Riding Gear & Accessories' => [
                ['name' => 'Spyder Recon Full-Face Helmet', 'brand' => 'Spyder', 'price' => '3450.00', 'cost' => '2600.00', 'reorder' => 1, 'stock' => [3, 1],
                    'variants' => [['name' => 'Medium', 'size' => 'M'], ['name' => 'Large', 'size' => 'L'], ['name' => 'X-Large', 'size' => 'XL']]],
                ['name' => 'Riding Gloves Half-Finger', 'brand' => null, 'price' => '350.00', 'cost' => '210.00', 'reorder' => 3, 'stock' => [8, 4],
                    'variants' => [['name' => 'Medium', 'size' => 'M'], ['name' => 'Large', 'size' => 'L'], ['name' => 'X-Large', 'size' => 'XL']]],
                ['name' => 'Motorcycle Phone Holder (Handlebar Mount)', 'brand' => null, 'price' => '450.00', 'cost' => '280.00', 'reorder' => 4, 'stock' => [12, 6]],
                ['name' => 'Side Mirror Set (Universal)', 'brand' => null, 'unit' => 'set', 'price' => '380.00', 'cost' => '240.00', 'reorder' => 4, 'stock' => [10, 5]],
                ['name' => 'Rain Suit Jacket & Pants', 'brand' => null, 'unit' => 'set', 'price' => '650.00', 'cost' => '420.00', 'reorder' => 4, 'stock' => [12, 6]],
                ['name' => 'Disc Lock with Alarm', 'brand' => null, 'price' => '890.00', 'cost' => '600.00', 'reorder' => 3, 'stock' => [8, 4]],
                ['name' => 'Seat Cover (Universal Scooter)', 'brand' => null, 'price' => '250.00', 'cost' => '150.00', 'reorder' => 5, 'stock' => [15, 8]],
            ],
            'Shop Services' => [
                ['name' => 'Change Oil Service (Labor)', 'type' => 'service', 'price' => '100.00', 'cost' => '0.00'],
                ['name' => 'Tire Mounting & Balancing', 'type' => 'service', 'price' => '150.00', 'cost' => '0.00'],
                ['name' => 'CVT Cleaning', 'type' => 'service', 'price' => '350.00', 'cost' => '0.00'],
                ['name' => 'Brake Cleaning & Adjustment', 'type' => 'service', 'price' => '200.00', 'cost' => '0.00'],
            ],
        ];
    }
}

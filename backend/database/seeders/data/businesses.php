<?php

/*
|--------------------------------------------------------------------------
| Launch businesses (BusinessSeeder)
|--------------------------------------------------------------------------
|
| The businesses created by `php artisan db:seed`, with their users and setup
| data (no products: those are entered in the app). Safe to run again: a
| business whose subdomain already exists is skipped.
|
| BEFORE RUNNING ON THE SERVER, edit the users' e-mail addresses below to
| real ones (sign-in, password resets). In production the seeder refuses
| *.test addresses. Passwords: env BUSINESS_SEED_PASSWORD for everyone, or
| leave it empty in production to get a random password per user printed
| once in the console. Locally they default to DEMO_PASSWORD (Password123).
|
| Plan: the business starts a trial of that plan (the plan's trial days);
| afterwards it pays through Settings → Subscription, or a platform admin
| uses Businesses → Enable access.
|
*/

return [

    [
        'name' => 'TRD Motorshop',
        'subdomain' => 'trd-motorshop',
        'business_type' => 'motor_shop',
        'vat_registered' => true,
        'phone' => '+639171234567',
        'plan' => 'basic',                 // 1 branch, 3 users, 1 POS terminal
        'branch' => 'Main Branch',
        'terminal' => 'Counter 1',
        'users' => [
            ['role' => 'owner', 'name' => 'TRD Owner', 'email' => 'owner@trd-motorshop.avantpos.co'],
            ['role' => 'branch_manager', 'name' => 'TRD Branch Manager', 'email' => 'manager@trd-motorshop.avantpos.co'],
            ['role' => 'cashier', 'name' => 'TRD Cashier', 'email' => 'cashier@trd-motorshop.avantpos.co'],
        ],
        'categories' => [
            'Engine Oil', 'Gear Oil', 'Fluids & Chemicals', 'Spark Plugs', 'Filters', 'Brakes', 'Drive & CVT Parts',
            'Tires & Tubes', 'Batteries', 'Electrical & Lights', 'Riding Gear & Accessories', 'Shop Services',
        ],
        'brands' => [
            'Motul', 'Shell', 'Castrol', 'Yamalube', 'Petron', 'Honda Genuine', 'Yamaha Genuine', 'Suzuki Genuine',
            'Prestone', 'WD-40', 'NGK', 'Denso', 'Elig', 'RK', 'DID', 'Bando', 'JVT', 'IRC', 'Michelin', 'Pirelli',
            'Motolite', 'Yuasa', 'Outlast', 'Bosch', 'Spyder',
        ],
        'units' => [],
        'suppliers' => [
            ['name' => 'Golden Rule Motor Supplier', 'phone' => '+639171234567', 'vat_registered' => false, 'payment_terms_days' => 0],
        ],
        'customer_groups' => [],
        'discounts' => [],
    ],

    [
        'name' => "Dayan's Ukay-Ukay & Apparel",
        'subdomain' => 'dayans-apparel',
        'business_type' => 'apparel',
        'vat_registered' => false,
        'phone' => null,
        'plan' => 'professional',          // up to 3 branches, 15 users, 6 POS terminals
        'branch' => 'Main Branch',
        'terminal' => 'Counter 1',
        'users' => [
            ['role' => 'owner', 'name' => "Dayan's Owner", 'email' => 'owner@dayans-apparel.avantpos.co'],
            ['role' => 'branch_manager', 'name' => "Dayan's Store Manager", 'email' => 'manager@dayans-apparel.avantpos.co'],
            ['role' => 'cashier', 'name' => "Dayan's Cashier", 'email' => 'cashier@dayans-apparel.avantpos.co'],
        ],
        'categories' => [
            "Women's Tops", 'Dresses', "Women's Pants & Jeans", 'Skirts & Shorts', "Men's Shirts & Polos", "Men's Pants & Shorts",
            'Jackets & Hoodies', 'Sleepwear & Loungewear', "Kids' Wear", 'Bags', 'Shoes', 'Accessories', 'Bales (Wholesale)',
        ],
        'brands' => [
            'Assorted / No Brand', 'Uniqlo', 'H&M', 'Zara', 'Mango', 'Forever 21', 'GAP', 'Old Navy', "Levi's", 'Nike',
            'Adidas', 'Champion', 'Tommy Hilfiger', 'Ralph Lauren', 'Hollister', 'Abercrombie & Fitch', 'Guess', 'Esprit',
        ],
        'units' => [
            ['name' => 'Bale', 'abbreviation' => 'bale', 'allows_decimal' => false],
        ],
        'suppliers' => [],
        'customer_groups' => [
            ['name' => 'Resellers', 'price_tier' => 'wholesale', 'discount_percent' => '0'],
        ],
        'discounts' => [
            ['name' => 'Ukay Sale 10%', 'type' => 'percent', 'value' => '10', 'scope' => 'transaction'],
            ['name' => 'Suki 5%', 'type' => 'percent', 'value' => '5', 'scope' => 'transaction'],
        ],
    ],

];

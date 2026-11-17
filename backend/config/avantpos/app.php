<?php

return [

    /*
    | Public URL of the React SPA. Used to build links in e-mails
    | (verification, password reset, invitations).
    */
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),

    /*
    | Business defaults applied to new tenants (Philippine market).
    */
    'defaults' => [
        'timezone' => 'Asia/Manila',
        'currency' => 'PHP',
        'locale' => 'en_PH',
        'vat_rate' => '12.0000',
        'percentage_tax_rate' => '3.0000',
        'date_format' => 'M d, Y',
        'time_format' => 'h:i A',
    ],

    /*
    | Disk for uploaded images (logos, product photos). Must be publicly
    | readable: "public" locally (run `php artisan storage:link`), S3 in production.
    */
    'media_disk' => env('AVANTPOS_MEDIA_DISK', 'public'),
    // Private documents (expense receipts, supplier bills): never publicly reachable.
    'documents_disk' => env('AVANTPOS_DOCUMENTS_DISK', 'local'),

    /*
    | Trial given to newly registered businesses.
    */
    'trial' => [
        'plan_code' => env('AVANTPOS_TRIAL_PLAN', 'professional'),
        'days' => (int) env('AVANTPOS_TRIAL_DAYS', 14),
    ],

    /*
    | User invitations.
    */
    'invitation_ttl_hours' => 72,

    /*
    | Supervisor PIN overrides at the POS.
    */
    'override' => [
        'token_ttl_seconds' => 120,
        'max_attempts' => 5,
        'lockout_seconds' => 300,
    ],

    /*
    | POS selling rules.
    |  max_discount_percent  manual / preset discounts above this share of the line
    |                        (or sale) need "pos.discount_override_limit" or a supervisor PIN
    |  sc_pwd_percent        statutory Senior Citizen (RA 9994) / PWD (RA 10754) discount
    */
    'pos' => [
        'max_discount_percent' => (string) env('POS_MAX_DISCOUNT_PERCENT', '20'),
        'sc_pwd_percent' => '20',
    ],

    /*
    | Default zero-padding of document numbers (invoice series, PO numbers...).
    */
    'document_number_padding' => 9,

];

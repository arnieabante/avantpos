<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Base domain
    |--------------------------------------------------------------------------
    |
    | Tenants may be served from "{subdomain}.{base_domain}". The subdomain is
    | only a routing/branding hint: the tenant of a request is ALWAYS taken
    | from the authenticated actor, and a mismatching subdomain is rejected.
    |
    */

    'base_domain' => env('AVANTPOS_BASE_DOMAIN', 'avantpos.ph'),

    /*
    | The main application host (e.g. app.avantpos.ph). Users of tenants
    | without a subdomain log in here.
    */
    'app_host' => env('AVANTPOS_APP_HOST', 'app.avantpos.ph'),

    /*
    | Subdomains that can never be claimed by a tenant.
    */
    'reserved_subdomains' => [
        'app', 'api', 'admin', 'www', 'mail', 'smtp', 'imap', 'pop', 'ftp',
        'help', 'support', 'status', 'docs', 'blog', 'billing', 'pay',
        'payments', 'static', 'assets', 'cdn', 'media', 'files', 'dev',
        'staging', 'test', 'demo', 'beta', 'dashboard', 'portal', 'login',
        'auth', 'account', 'accounts', 'avantpos', 'pos', 'ns1', 'ns2',
    ],

    /*
    | Subdomain format: 3-30 chars, lowercase letters, digits and inner hyphens.
    */
    'subdomain_pattern' => '/^(?!-)[a-z0-9-]{3,30}(?<!-)$/',

];

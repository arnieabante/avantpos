<?php

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
|
| The SPA (app.avantpos.ph and tenant subdomains *.avantpos.ph) calls the
| API (api.avantpos.ph) with credentials (Sanctum session cookie), so
| origins must be listed explicitly — "*" is not allowed with credentials.
|
| CORS_ALLOWED_ORIGINS          comma separated exact origins
| CORS_ALLOWED_ORIGIN_PATTERNS  comma separated regexes (tenant subdomains)
|
*/

$list = fn (string $key, string $default = '') => array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env($key, $default))
)));

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $list('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://127.0.0.1:5173'),

    'allowed_origins_patterns' => $list('CORS_ALLOWED_ORIGIN_PATTERNS'),

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => true,

];

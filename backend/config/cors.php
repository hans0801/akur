<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Produksi: web dan API satu origin (Caddy), daftar ini untuk pengembangan lokal.
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('AKUR_WEB_ORIGINS'))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-AKUR-CSRF', 'X-Request-Id', 'Idempotency-Key'],

    'exposed_headers' => ['X-Request-Id'],

    'max_age' => 600,

    'supports_credentials' => true,

];

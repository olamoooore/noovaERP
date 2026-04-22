<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Restrict CORS to known frontend origins. The admin panel and tenant ERP
    | frontend are the only allowed origins. Wildcard (*) is intentionally
    | NOT used — it would allow any website to make credentialed requests.
    |
    | Add origins via the CORS_ALLOWED_ORIGINS env variable (comma-separated)
    | for environment-specific overrides without changing this file.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173,http://localhost:5174'))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'X-Tenant-ID',
        'X-Feature-Gate-Fresh',
        'Accept',
    ],

    'exposed_headers' => [],

    'max_age' => 86400, // 24 hours — browsers cache preflight responses

    'supports_credentials' => true,

];

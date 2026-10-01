<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default entitlements for unconfigured tenants
    |--------------------------------------------------------------------------
    |
    | The platform fails closed: a tenant with no entitlement rows only gets
    | core features. Demo/intern environments where every module should be
    | available out of the box can set ENTITLEMENTS_DEFAULT_ALLOW=true — an
    | empty `tenant_entitlements` then resolves as "all modules entitled".
    | Explicit entitlement rows (including explicit exclusions) always win.
    */
    'entitlements' => [
        'default_allow_when_unconfigured' => env('ENTITLEMENTS_DEFAULT_ALLOW', false),
    ],


    /*
    |--------------------------------------------------------------------------
    | Platform API rate limiting
    |--------------------------------------------------------------------------
    |
    | Requests per minute allowed by PlatformRateLimitMiddleware. Authenticated
    | traffic is counted per user id (so multiple users behind one office NAT
    | do not share a bucket); unauthenticated traffic is counted per IP.
    |
    */

    'rate_limit' => [
        // Default bumped from 300 → 600 (2026-09-24) after the SPA-polling
        // 429 incident. The cheap polling endpoints (features /
        // notifications / dashboard) are also added to PlatformRateLimitMiddleware
        // SKIP_PATHS so they no longer share the global bucket.
        'authenticated' => (int) env('PLATFORM_RATE_LIMIT_AUTHENTICATED', 600),
        'guest' => (int) env('PLATFORM_RATE_LIMIT_GUEST', 120),
    ],

];

<?php

return [

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
        'authenticated' => (int) env('PLATFORM_RATE_LIMIT_AUTHENTICATED', 300),
        'guest' => (int) env('PLATFORM_RATE_LIMIT_GUEST', 120),
    ],

];

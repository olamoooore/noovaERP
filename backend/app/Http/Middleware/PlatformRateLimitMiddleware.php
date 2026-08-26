<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PlatformRateLimitMiddleware
{
    /**
     * Maximum requests per minute per IP address
     */
    private const RATE_LIMIT_PER_MINUTE = 120;

    /**
     * Skip rate limiting for these paths.
     *
     * Per Pillar 2 Part 2 §2.2: the original `/admin/api/` substring
     * never matched the real admin path `/api/v1/admin/...` (the
     * admin/API substring order never occurs), making the exemption
     * dead code — the admin API was always rate-limited, contrary
     * to the intent. The new path matches the actual `v1/admin/`
     * prefix used by `routes/api.php:152` (Route::prefix('v1/admin')).
     */
    private const SKIP_PATHS = [
        '/api/v1/webhooks/',
        '/api/v1/health',
        '/api/v1/auth/',
        '/v1/admin/',
    ];

    /**
     * Window for rate limiting in seconds (1 minute)
     */
    private const WINDOW_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        // Skip rate limiting for admin API and other exempt paths
        foreach (self::SKIP_PATHS as $skipPath) {
            if (str_contains($request->getPathInfo(), $skipPath)) {
                return $next($request);
            }
        }

        $clientIp = $request->ip();
        $key = "rate_limit:platform:{$clientIp}";

        // Use atomic increment with TTL on first request
        $attempts = Cache::get($key, 0);
        $attempts = Cache::increment($key);

        // Set TTL only on first request (when value becomes 1)
        if ($attempts === 1) {
            // For Redis: use put with TTL. For file: increment already sets no expiry but file cache handles cleanup
            Cache::put($key, $attempts, self::WINDOW_SECONDS);
        }

        if ($attempts > self::RATE_LIMIT_PER_MINUTE) {
            Log::warning('Platform API rate limit exceeded', [
                'ip' => $clientIp,
                'path' => $request->getPathInfo(),
                'method' => $request->method(),
                'attempts' => $attempts,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'error' => 'Too many requests',
                'message' => 'Rate limit exceeded. Please try again later.',
                'retry_after' => self::WINDOW_SECONDS,
            ], 429);
        }

        return $next($request);
    }
}

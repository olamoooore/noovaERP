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
     * Skip rate limiting for these paths
     */
    private const SKIP_PATHS = [
        '/api/v1/webhooks/',
        '/api/v1/health',
        '/admin/api/',
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

        $attempts = Cache::increment($key);

        if ($attempts === 1) {
            Cache::expire($key, self::WINDOW_SECONDS);
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

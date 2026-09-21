<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class PlatformRateLimitMiddleware
{
    /**
     * Maximum requests per minute per IP address.
     */
    private const RATE_LIMIT_PER_MINUTE = 120;

    /**
     * Window for rate limiting in seconds (1 minute).
     */
    private const WINDOW_SECONDS = 60;

    /**
     * Paths exempt from platform rate limiting.
     *
     * Per Pillar 2 Part 2 §2.2: the original `/admin/api/` substring never
     * matched the real admin path `/api/v1/admin/...`, making the exemption
     * dead code. `/v1/admin/` matches the actual prefix in routes/api.php.
     *
     * `/api/v1/features` and `/api/v1/dashboard` are deliberately NOT exempt:
     * they are normal tenant endpoints. The fix for their burst behaviour is
     * the canonical RateLimiter below (which always carries a TTL), not an
     * exemption.
     */
    private const SKIP_PATHS = [
        '/api/v1/webhooks/',
        '/api/v1/health',
        '/api/v1/auth/',
        '/v1/admin/',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        foreach (self::SKIP_PATHS as $skipPath) {
            if (str_contains($request->getPathInfo(), $skipPath)) {
                return $next($request);
            }
        }

        // Use the canonical Laravel RateLimiter. Unlike the previous
        // hand-rolled Cache::increment() + conditional Cache::put(),
        // RateLimiter::attempt() sets the decay window TTL atomically and
        // reliably on EVERY cache driver — including the `file` driver, where
        // Cache::increment() silently drops the expiration and would otherwise
        // leave the counter stuck above the limit FOREVER (permanent 429).
        //
        // This was the root cause of the "sidebar shows only 3 items and the
        // page keeps refetching" report: a transient burst tripped the limit,
        // the file-cache TTL was lost, and /api/v1/features returned 429
        // indefinitely, which the frontend treated as "no features enabled".
        $key = 'rate_limit:platform:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT_PER_MINUTE)) {
            $seconds = RateLimiter::availableIn($key);

            Log::warning('Platform API rate limit exceeded', [
                'ip' => $request->ip(),
                'path' => $request->getPathInfo(),
                'method' => $request->method(),
                'retry_after' => $seconds,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'error' => 'Too many requests',
                'message' => 'Rate limit exceeded. Please try again later.',
                'retry_after' => $seconds,
            ], 429)->withHeaders([
                'Retry-After' => $seconds,
                'X-RateLimit-Limit' => self::RATE_LIMIT_PER_MINUTE,
                'X-RateLimit-Remaining' => 0,
            ]);
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);

        return $next($request)->withHeaders([
            'X-RateLimit-Limit' => self::RATE_LIMIT_PER_MINUTE,
            'X-RateLimit-Remaining' => max(0, self::RATE_LIMIT_PER_MINUTE - RateLimiter::attempts($key)),
        ]);
    }
}

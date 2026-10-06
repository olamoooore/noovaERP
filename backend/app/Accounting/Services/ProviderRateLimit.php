<?php

namespace App\Accounting\Services;

use Illuminate\Support\Facades\Cache;

/**
 * #666 (EI4) AC1 — respect the e-invoicing provider's own throttling answer.
 *
 * No published provider quota is encoded here (none exists in this
 * repository). Instead, when a provider replies HTTP 429 Too Many Requests,
 * the provider plugin records it here and the status sweep stops calling that
 * provider for the current tenant/country until the response's Retry-After
 * has passed. Without a usable Retry-After, polling pauses until the next
 * scheduled sweep (the sweep runs every ten minutes).
 */
class ProviderRateLimit
{
    /** Pause used when the 429 carries no usable Retry-After. */
    private const DEFAULT_PAUSE_SECONDS = 300;

    /** Upper bound on a provider-supplied Retry-After. */
    private const MAX_PAUSE_SECONDS = 3600;

    /**
     * Record a 429 for this tenant/country. $retryAfter is the raw
     * Retry-After header: delay-seconds or an HTTP date (RFC 9110 §10.2.3).
     */
    public static function throttle(string $countryCode, ?string $retryAfter = null): void
    {
        $seconds = self::parseRetryAfter($retryAfter) ?? self::DEFAULT_PAUSE_SECONDS;
        $seconds = max(1, min($seconds, self::MAX_PAUSE_SECONDS));

        Cache::put(self::key($countryCode), now()->addSeconds($seconds)->getTimestamp(), $seconds);
    }

    public static function isThrottled(string $countryCode): bool
    {
        $until = Cache::get(self::key($countryCode));

        return is_int($until) && $until > now()->getTimestamp();
    }

    private static function parseRetryAfter(?string $retryAfter): ?int
    {
        $retryAfter = trim((string) $retryAfter);
        if ($retryAfter === '') {
            return null;
        }

        if (ctype_digit($retryAfter)) {
            return (int) $retryAfter;
        }

        $timestamp = strtotime($retryAfter);

        return $timestamp === false ? null : max(0, $timestamp - now()->getTimestamp());
    }

    private static function key(string $countryCode): string
    {
        $tenantId = function_exists('tenant') && tenant() ? (string) tenant('id') : 'central';

        return 'compliance:provider-throttled:'.$tenantId.':'.strtoupper($countryCode);
    }
}

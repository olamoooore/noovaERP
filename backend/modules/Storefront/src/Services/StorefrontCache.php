<?php

namespace Modules\Storefront\Services;

use Illuminate\Support\Facades\Cache;
use Modules\Storefront\Shortcodes\Context;

/**
 * Tenant-safe fragment cache (Appendix B §B3.3/§B3.8, NFR/FR-BLD-08).
 *
 * The platform cache driver is the file store (no tags), so invalidation uses
 * a per-tenant CACHE VERSION embedded in every fragment key: bumping the
 * version atomically orphans all stale fragments for that tenant while new
 * renders repopulate. Version bumps are driven by catalog/price/stock/
 * settings model events (see StorefrontServiceProvider listeners).
 *
 * Key shape guarantees isolation:
 *   storefront:frag:{tenantId}:v{cacheVersion}:pv{pageVersionId}:{fn}:{attrsHash}:{defVersion}:{locale}:{currency}
 * — personal data NEVER enters Tier A keys (Tier B is never cached at all).
 */
final class StorefrontCache
{
    public function versionKey(string $tenantId): string
    {
        return "storefront:cv:{$tenantId}";
    }

    public function currentVersion(string $tenantId): int
    {
        return (int) Cache::get($this->versionKey($tenantId), 1);
    }

    public function bumpVersion(?string $tenantId): void
    {
        if ($tenantId === null || $tenantId === '') {
            return;
        }

        Cache::put($this->versionKey($tenantId), $this->currentVersion($tenantId) + 1, now()->addDay());
    }

    /**
     * @param  array<string,mixed>  $normalizedAttributes
     */
    public function fragmentKey(
        Context $context,
        string $functionKey,
        array $normalizedAttributes,
        string $definitionVersion,
        string $querySalt = '',
    ): string {
        ksort($normalizedAttributes);

        return sprintf(
            'storefront:frag:%s:v%d:pv%d:%s:%s:%s:%s:%s:%s',
            $context->tenantId,
            $this->currentVersion($context->tenantId),
            $context->pageVersionId ?? 0,
            str_replace('.', '_', $functionKey),
            sha1(json_encode($normalizedAttributes, JSON_UNESCAPED_UNICODE)),
            $definitionVersion,
            $context->locale,
            $context->settings()->currency_code ?? 'base',
            $querySalt,
        );
    }

    public function get(string $key): ?string
    {
        $value = Cache::get($key);

        return is_string($value) ? $value : null;
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        Cache::put($key, $value, $ttlSeconds);
    }

    /**
     * A-27 "cache hit ratio reported": per-tenant fragment-cache counters.
     * Counters live in the same cache as the fragments they measure, so a
     * cache flush resets the window — acceptable for an ops signal.
     */
    public function noteHit(string $tenantId): void
    {
        $this->note($tenantId, 'hits');
    }

    public function noteMiss(string $tenantId): void
    {
        $this->note($tenantId, 'misses');
    }

    private function note(string $tenantId, string $bucket): void
    {
        if ($tenantId === '') {
            return;
        }

        $key = "storefront:stats:{$tenantId}:{$bucket}";

        try {
            Cache::has($key) ? Cache::increment($key) : Cache::put($key, 1, now()->addDays(7));
        } catch (\Throwable) {
            // metrics must never break a render
        }
    }

    /**
     * @return array{hits: int, misses: int, ratio: float|null}
     */
    public function stats(string $tenantId): array
    {
        $hits = (int) Cache::get("storefront:stats:{$tenantId}:hits", 0);
        $misses = (int) Cache::get("storefront:stats:{$tenantId}:misses", 0);
        $total = $hits + $misses;

        return [
            'hits' => $hits,
            'misses' => $misses,
            'ratio' => $total > 0 ? round($hits / $total, 4) : null,
        ];
    }
}

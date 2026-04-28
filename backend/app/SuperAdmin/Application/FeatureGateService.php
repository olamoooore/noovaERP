<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use App\SuperAdmin\Domain\TenantEntitlement;
use App\Services\SearchPathGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class FeatureGateService
{
    /**
     * Cache TTL in seconds — 5 minutes by default.
     * Invalidated immediately when entitlements are rebuilt.
     */
    private const TTL = 300;

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Return all entitled feature keys for a tenant.
     * Result is cached; pass $fresh = true to bypass cache.
     *
     * Returns array of:
     *   [ 'key' => string, 'source' => string, 'name' => string ]
     */
    public function all(string $tenantId, bool $fresh = false): array
    {
        if ($fresh) {
            $this->invalidate($tenantId);
        }

        return Cache::remember(
            $this->cacheKey($tenantId),
            self::TTL,
            fn () => $this->resolveFromDb($tenantId),
        );
    }

    /**
     * Check a single feature key.
     *
     * Returns:
     *   [ 'key' => string, 'is_entitled' => bool, 'source' => string|null, 'cached_at' => string ]
     */
    public function check(string $tenantId, string $key, bool $fresh = false): array
    {
        $all = $this->all($tenantId, $fresh);

        $match = collect($all)->firstWhere('key', $key);

        return [
            'key'         => $key,
            'is_entitled' => $match !== null,
            'source'      => $match['source'] ?? null,
            'name'        => $match['name']   ?? null,
            'cached_at'   => now()->toIso8601String(),
        ];
    }

    /**
     * Batch-check multiple feature keys in one call.
     *
     * Returns array keyed by feature key:
     *   [ 'payroll' => ['is_entitled' => true, 'source' => 'tier_included'], ... ]
     */
    public function checkMany(string $tenantId, array $keys, bool $fresh = false): array
    {
        $all    = collect($this->all($tenantId, $fresh))->keyBy('key');
        $result = [];

        foreach ($keys as $key) {
            $match          = $all->get($key);
            $result[$key]   = [
                'key'         => $key,
                'is_entitled' => $match !== null,
                'source'      => $match['source'] ?? null,
                'name'        => $match['name']   ?? null,
            ];
        }

        return $result;
    }

    /**
     * Invalidate the cache for a specific tenant (called after entitlement rebuild).
     */
    public function invalidate(string $tenantId): void
    {
        Cache::forget($this->cacheKey($tenantId));
    }

    /**
     * Invalidate ALL tenants (used after a global rebuild).
     * Uses Cache tags if supported (Redis); falls back to a stored key list.
     */
    public function invalidateAll(): void
    {
        // When using Redis with tags
        if (config('cache.default') === 'redis') {
            Cache::tags(['feature_gate'])->flush();
            return;
        }

        // Fallback: iterate known tenant IDs from DB (use central connection)
        $tenantIds = TenantEntitlement::on('public')->distinct()->pluck('tenant_id');
        foreach ($tenantIds as $tenantId) {
            $this->invalidate($tenantId);
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function cacheKey(string $tenantId): string
    {
        return "feature_gate:{$tenantId}";
    }

    private function resolveFromDb(string $tenantId): array
    {
        $previousSchema = $this->ensureCentralSchema();

        try {
            return TenantEntitlement::where('tenant_id', $tenantId)
                ->where('is_entitled', true)
                ->with('catalogItem:id,key,name')
                ->get()
                ->map(fn ($e) => [
                    'key'    => $e->catalogItem?->key ?? '',
                    'name'   => $e->catalogItem?->name ?? '',
                    'source' => $e->source,
                ])
                ->filter(fn ($e) => $e['key'] !== '')
                ->values()
                ->all();
        } finally {
            $this->restoreSchema($previousSchema);
        }
    }

    private function ensureCentralSchema(): ?string
    {
        if (!function_exists('tenant') || !tenant()) {
            return null;
        }

        $row = DB::selectOne('SHOW search_path');
        $previous = $row->search_path ?? null;

        SearchPathGuard::setSearchPath('public', 'Accessing central feature gate data');
        return $previous;
    }

    private function restoreSchema(?string $previous): void
    {
        if ($previous === null) {
            return;
        }

        SearchPathGuard::resetSearchPath();
    }
}

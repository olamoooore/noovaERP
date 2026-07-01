<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        return Cache::tags(['feature_gate', $tenantId])->remember(
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
            $match        = $all->get($key);
            $result[$key] = [
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
        Cache::tags(['feature_gate', $tenantId])->forget($this->cacheKey($tenantId));
    }

    /**
     * Invalidate ALL tenants (used after a global rebuild).
     * Uses Cache tags if supported (Redis); falls back to a stored key list.
     */
    public function invalidateAll(): void
    {
        // When using Redis with tags
        if (config('cache.default') === 'redis') {
            Cache::tags('feature_gate')->flush();
            return;
        }

        // Fallback: iterate known tenant IDs from the central schema.
        try {
            $tenantIds = DB::table('public.tenant_entitlements')
                ->distinct()
                ->pluck('tenant_id');
        } catch (\Throwable $e) {
            Log::warning('FeatureGateService: invalidateAll skipped (entitlements store unavailable)', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

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
        try {
            return DB::table('public.tenant_entitlements as tenant_entitlements')
                ->join('public.catalog_items as catalog_items', 'catalog_items.id', '=', 'tenant_entitlements.catalog_item_id')
                ->where('tenant_entitlements.tenant_id', $tenantId)
                ->where('tenant_entitlements.is_entitled', true)
                ->whereNotNull('catalog_items.key')
                ->get([
                    'catalog_items.key as key',
                    'catalog_items.name as name',
                    'tenant_entitlements.source as source',
                ])
                ->map(fn ($row) => [
                    'key' => (string) $row->key,
                    'name' => (string) $row->name,
                    'source' => (string) $row->source,
                ])
                ->all();
        } catch (\Throwable $e) {
            Log::warning('FeatureGateService: entitlement resolution failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return $this->resolveFromLegacyTenantFeatures($tenantId);
        }
    }

    private function resolveFromLegacyTenantFeatures(string $tenantId): array
    {
        $tenant = Tenant::find($tenantId);
        if (!$tenant) {
            return [];
        }

        $schema = $tenant->getSchemaName();
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $schema)) {
            Log::warning('FeatureGateService: Invalid tenant schema name for legacy fallback', [
                'tenant_id' => $tenantId,
                'schema' => $schema,
            ]);

            return [];
        }

        try {
            $sql = <<<SQL
                select f.key as key, f.name as name
                from "{$schema}".tenant_features tf
                inner join public.features f on f.id = tf.feature_id
                where tf.is_enabled = true
                  and f.key is not null
            SQL;

            return collect(DB::select($sql))
                ->map(fn ($row) => [
                    'key' => (string) $row->key,
                    'name' => (string) $row->name,
                    'source' => 'legacy_tenant_features',
                ])
                ->all();
        } catch (\Throwable $e) {
            Log::warning('FeatureGateService: legacy tenant_features fallback failed', [
                'tenant_id' => $tenantId,
                'schema' => $schema,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}

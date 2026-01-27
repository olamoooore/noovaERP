<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class FeatureFlagController
{
    protected string $cachePrefix = 'feature_flag:';
    protected int $cacheTtl = 3600; // 1 hour
    protected string $tableName = 'tenant_feature_flags';

    /**
     * Check if a tenant has access to a specific feature
     */
    public function hasFeature(string $tenantId, string $feature): bool
    {
        $cacheKey = $this->cachePrefix . $tenantId . ':' . $feature;
        
        return Cache::remember($cacheKey, $this->cacheTtl, function () use ($tenantId, $feature) {
            return $this->checkFeatureInDatabase($tenantId, $feature);
        });
    }

    /**
     * Enable a feature for a tenant
     */
    public function enableFeature(string $tenantId, string $feature): void
    {
        $this->validateTenantId($tenantId);
        $this->validateFeature($feature);

        try {
            DB::table($this->tableName)->updateOrInsert(
                ['tenant_id' => $tenantId, 'feature' => $feature],
                [
                    'enabled' => true, 
                    'updated_at' => now(),
                    'created_at' => now()
                ]
            );

            $this->clearFeatureCache($tenantId, $feature);
            
            Log::info("Feature enabled for tenant", [
                'tenant_id' => $tenantId,
                'feature' => $feature
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to enable feature for tenant", [
                'tenant_id' => $tenantId,
                'feature' => $feature,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Disable a feature for a tenant
     */
    public function disableFeature(string $tenantId, string $feature): void
    {
        $this->validateTenantId($tenantId);
        $this->validateFeature($feature);

        try {
            DB::table($this->tableName)->updateOrInsert(
                ['tenant_id' => $tenantId, 'feature' => $feature],
                [
                    'enabled' => false, 
                    'updated_at' => now(),
                    'created_at' => now()
                ]
            );

            $this->clearFeatureCache($tenantId, $feature);
            
            Log::info("Feature disabled for tenant", [
                'tenant_id' => $tenantId,
                'feature' => $feature
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to disable feature for tenant", [
                'tenant_id' => $tenantId,
                'feature' => $feature,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Seed default feature flags for a tenant
     */
    public function seedDefaultFlags(string $tenantId = null): void
    {
        $defaultFlags = config('event_management.feature_flags', []);
        
        if (empty($defaultFlags)) {
            Log::warning("No default feature flags configured");
            return;
        }
        
        foreach ($defaultFlags as $feature => $config) {
            $enabled = $config['enabled'] ?? false;
            
            if ($tenantId) {
                $this->setFeatureFlag($tenantId, $feature, $enabled);
            } else {
                // Seed for all existing tenants if no specific tenant provided
                $this->seedForAllTenants($feature, $enabled);
            }
        }
        
        Log::info("Default feature flags seeded", [
            'tenant_id' => $tenantId ?? 'all',
            'flags_count' => count($defaultFlags)
        ]);
    }

    /**
     * Get all feature flags for a tenant
     */
    public function getTenantFeatures(string $tenantId): array
    {
        $this->validateTenantId($tenantId);
        
        $flags = DB::table($this->tableName)
            ->where('tenant_id', $tenantId)
            ->get(['feature', 'enabled'])
            ->keyBy('feature')
            ->map(fn($flag) => (bool) $flag->enabled)
            ->toArray();

        // Merge with default configuration for features not explicitly set
        $defaultFlags = config('event_management.feature_flags', []);
        foreach ($defaultFlags as $feature => $config) {
            if (!isset($flags[$feature])) {
                $flags[$feature] = $config['enabled'] ?? false;
            }
        }

        return $flags;
    }

    /**
     * Check if event management feature is enabled for tenant
     */
    public function isEventManagementEnabled(string $tenantId): bool
    {
        return $this->hasFeature($tenantId, 'event_management');
    }

    /**
     * Bulk enable features for a tenant
     */
    public function bulkEnableFeatures(string $tenantId, array $features): void
    {
        $this->validateTenantId($tenantId);
        
        foreach ($features as $feature) {
            $this->enableFeature($tenantId, $feature);
        }
    }

    /**
     * Bulk disable features for a tenant
     */
    public function bulkDisableFeatures(string $tenantId, array $features): void
    {
        $this->validateTenantId($tenantId);
        
        foreach ($features as $feature) {
            $this->disableFeature($tenantId, $feature);
        }
    }

    /**
     * Remove all feature flags for a tenant
     */
    public function removeAllTenantFeatures(string $tenantId): void
    {
        $this->validateTenantId($tenantId);
        
        DB::table($this->tableName)
            ->where('tenant_id', $tenantId)
            ->delete();
            
        // Clear all cached features for this tenant
        $this->clearAllTenantCache($tenantId);
        
        Log::info("All feature flags removed for tenant", ['tenant_id' => $tenantId]);
    }

    /**
     * Check feature flag in database
     */
    protected function checkFeatureInDatabase(string $tenantId, string $feature): bool
    {
        $flag = DB::table($this->tableName)
            ->where('tenant_id', $tenantId)
            ->where('feature', $feature)
            ->first();

        if (!$flag) {
            // If no record exists, check default configuration
            return config("event_management.feature_flags.{$feature}.enabled", false);
        }

        return (bool) $flag->enabled;
    }

    /**
     * Set feature flag for a tenant
     */
    protected function setFeatureFlag(string $tenantId, string $feature, bool $enabled): void
    {
        DB::table($this->tableName)->updateOrInsert(
            ['tenant_id' => $tenantId, 'feature' => $feature],
            [
                'enabled' => $enabled,
                'created_at' => now(),
                'updated_at' => now()
            ]
        );
    }

    /**
     * Seed feature flag for all tenants
     */
    protected function seedForAllTenants(string $feature, bool $enabled): void
    {
        // This would typically get tenant IDs from a tenants table
        // For now, we'll just create a default entry
        $this->setFeatureFlag('default', $feature, $enabled);
    }

    /**
     * Clear feature cache for a tenant
     */
    protected function clearFeatureCache(string $tenantId, string $feature): void
    {
        $cacheKey = $this->cachePrefix . $tenantId . ':' . $feature;
        Cache::forget($cacheKey);
    }

    /**
     * Clear all cached features for a tenant
     */
    protected function clearAllTenantCache(string $tenantId): void
    {
        // Since we can't easily iterate over cache keys, we'll use cache tags if available
        // For now, we'll just clear the common features
        $commonFeatures = array_keys(config('event_management.feature_flags', []));
        
        foreach ($commonFeatures as $feature) {
            $this->clearFeatureCache($tenantId, $feature);
        }
    }

    /**
     * Validate tenant ID
     */
    protected function validateTenantId(string $tenantId): void
    {
        if (empty(trim($tenantId))) {
            throw new InvalidArgumentException('Tenant ID cannot be empty');
        }
        
        if (strlen($tenantId) > 255) {
            throw new InvalidArgumentException('Tenant ID cannot exceed 255 characters');
        }
    }

    /**
     * Validate feature name
     */
    protected function validateFeature(string $feature): void
    {
        if (empty(trim($feature))) {
            throw new InvalidArgumentException('Feature name cannot be empty');
        }
        
        if (strlen($feature) > 100) {
            throw new InvalidArgumentException('Feature name cannot exceed 100 characters');
        }
        
        if (!preg_match('/^[a-z_]+$/', $feature)) {
            throw new InvalidArgumentException('Feature name must contain only lowercase letters and underscores');
        }
    }
}
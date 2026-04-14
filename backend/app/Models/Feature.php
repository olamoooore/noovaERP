<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Feature extends Model
{
    protected $fillable = [
        'key',
        'name',
        'description',
        'is_core',
        'price',
    ];

    protected $casts = [
        'is_core' => 'bool',
        'price' => 'decimal:2',
    ];

    public function tenantFeatures(): HasMany
    {
        return $this->hasMany(TenantFeature::class);
    }

    /**
     * Check if this feature is enabled for the current tenant.
     *
     * @deprecated Use FeatureGateService::check($tenantId, $key) which reads the canonical
     *             `tenant_entitlements` snapshot. This method is kept as a legacy fallback
     *             for non-tenant contexts (CLI, tests) and will be retired in Phase 3.
     *
     * NOTE: This method is called within a tenant schema context (after search_path is set),
     *       so `tenant_features` rows are already scoped to the active tenant's schema.
     *       It is NOT safe to call this method from a central (non-tenant) context.
     */
    public function isEnabledForTenant(): bool
    {
        // Order by `id DESC` to get the most recently created row.
        // Within a tenant schema this is safe; avoid calling this from central context.
        $tf = $this->tenantFeatures()->latest('id')->first();

        if (!$tf) {
            return false;
        }

        if (!$tf->is_enabled) {
            return false;
        }

        if ($tf->expires_at && $tf->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}

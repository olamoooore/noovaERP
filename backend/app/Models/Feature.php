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
     * @deprecated Retired. Use FeatureGateService::check($tenantId, $key).
     *
     * This method previously read from the legacy `tenant_features` table which is not
     * the canonical runtime access-control store. All runtime entitlement decisions must
     * go through FeatureGateService → tenant_entitlements.
     *
     * @throws \LogicException always — calling this method is a programming error.
     */
    public function isEnabledForTenant(): never
    {
        throw new \LogicException(
            'isEnabledForTenant() is retired. Use FeatureGateService::check($tenantId, $key) '.
            'which reads the canonical tenant_entitlements snapshot.'
        );
    }
}

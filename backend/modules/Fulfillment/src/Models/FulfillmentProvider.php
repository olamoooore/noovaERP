<?php

namespace Modules\Fulfillment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FulfillmentProvider extends Model
{
    use HasUuids;

    protected $table = 'fulfillment_providers';

    protected $fillable = [
        'tenant_id',
        'carrier_code',
        'name',
        'is_active',
        'is_default',
        'config',
        'settings',
        'sort_order',
    ];

    protected $casts = [
        'config' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function shippingMethods(): HasMany
    {
        return $this->hasMany(ShippingMethod::class, 'provider_id');
    }

    public function shippingRules(): HasMany
    {
        return $this->hasMany(ShippingRule::class, 'provider_id');
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class, 'provider_id');
    }

    public function shippingRates(): HasMany
    {
        return $this->hasMany(ShippingRate::class, 'provider_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    public function getDecryptedConfig(): array
    {
        $config = $this->config ?? [];

        foreach ($config as $key => $value) {
            if (in_array($key, ['api_key', 'api_secret', 'password', 'token'])) {
                $config[$key] = $value ? decrypt($value) : null;
            }
        }

        return $config;
    }
}

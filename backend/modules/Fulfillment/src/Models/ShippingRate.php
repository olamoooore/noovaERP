<?php

namespace Modules\Fulfillment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingRate extends Model
{
    use HasUuids;

    protected $table = 'shipping_rates';

    protected $fillable = [
        'tenant_id',
        'provider_id',
        'shipping_method_id',
        'service_code',
        'base_rate',
        'rate_per_kg',
        'flat_rate',
        'destination_type',
        'destination_value',
        'min_weight',
        'max_weight',
        'is_active',
    ];

    protected $casts = [
        'base_rate' => 'decimal:2',
        'rate_per_kg' => 'decimal:2',
        'flat_rate' => 'decimal:2',
        'min_weight' => 'decimal:2',
        'max_weight' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(FulfillmentProvider::class, 'provider_id');
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }

    public function calculateRate(float $weight): float
    {
        if ($this->flat_rate !== null) {
            return (float) $this->flat_rate;
        }

        $rate = (float) $this->base_rate;
        $rate += $weight * (float) $this->rate_per_kg;

        return round($rate, 2);
    }

    public function matchesDestination(string $countryCode, ?string $postalCode = null): bool
    {
        if ($this->destination_type === 'country') {
            return $this->destination_value === $countryCode;
        }

        if ($this->destination_type === 'zone') {
            return true;
        }

        return true;
    }

    public function matchesWeight(float $weight): bool
    {
        if ($this->min_weight !== null && $weight < $this->min_weight) {
            return false;
        }

        if ($this->max_weight !== null && $weight > $this->max_weight) {
            return false;
        }

        return true;
    }
}
<?php

namespace Modules\Fulfillment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShippingMethod extends Model
{
    use HasUuids;

    protected $table = 'shipping_methods';

    protected $fillable = [
        'tenant_id',
        'provider_id',
        'name',
        'service_code',
        'description',
        'transit_days',
        'is_active',
        'base_rate',
        'rate_per_kg',
    ];

    protected $casts = [
        'transit_days' => 'array',
        'is_active' => 'boolean',
        'base_rate' => 'decimal:2',
        'rate_per_kg' => 'decimal:2',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(FulfillmentProvider::class, 'provider_id');
    }

    public function calculateRate(float $weight): float
    {
        return $this->base_rate + ($weight * $this->rate_per_kg);
    }
}
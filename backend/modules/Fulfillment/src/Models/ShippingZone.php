<?php

namespace Modules\Fulfillment\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ShippingZone extends Model
{
    use HasUuids;

    protected $table = 'shipping_zones';

    protected $fillable = [
        'tenant_id',
        'name',
        'country_codes',
        'postal_code_patterns',
        'is_active',
    ];

    protected $casts = [
        'country_codes' => 'array',
        'postal_code_patterns' => 'array',
        'is_active' => 'boolean',
    ];

    public function matchesDestination(string $countryCode, string $postalCode): bool
    {
        if (! in_array($countryCode, $this->country_codes ?? [])) {
            return false;
        }

        $patterns = $this->postal_code_patterns ?? [];

        if (empty($patterns)) {
            return true;
        }

        foreach ($patterns as $pattern) {
            if (preg_match('/'.$pattern.'/', $postalCode)) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace Modules\Loyalty\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyProgram extends Model
{
    protected $table = 'loyalty_programs';

    protected $fillable = [
        'name',
        'description',
        'points_per_currency',
        'currency_per_point',
        'min_redemption_points',
        'max_redemption_percent',
        'points_expiry_months',
        'is_active',
    ];

    protected $casts = [
        'points_per_currency' => 'decimal:2',
        'currency_per_point' => 'decimal:2',
        'max_redemption_percent' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function tiers(): HasMany
    {
        return $this->hasMany(LoyaltyTier::class, 'loyalty_program_id');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(LoyaltyReward::class, 'loyalty_program_id');
    }
}

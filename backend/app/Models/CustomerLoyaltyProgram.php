<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerLoyaltyProgram extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'points_per_currency',
        'points_value',
        'min_points_redeem',
        'max_points_redeem_per_order',
        'is_active',
    ];

    protected $casts = [
        'points_per_currency' => 'decimal:4',
        'points_value' => 'decimal:4',
        'min_points_redeem' => 'integer',
        'max_points_redeem_per_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function balances(): HasMany
    {
        return $this->hasMany(CustomerLoyaltyBalance::class, 'program_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CustomerLoyaltyTransaction::class, 'program_id');
    }

    /**
     * Calculate points for a given amount
     */
    public function calculatePoints(float $amount): int
    {
        return (int) floor($amount * $this->points_per_currency);
    }

    /**
     * Calculate discount value for given points
     */
    public function calculatePointsValue(int $points): float
    {
        return $points * $this->points_value;
    }
}

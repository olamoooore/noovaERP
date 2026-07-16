<?php

namespace Modules\Loyalty\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerLoyalty extends Model
{
    protected $table = 'customer_loyalty';

    protected $fillable = [
        'customer_id',
        'loyalty_program_id',
        'loyalty_tier_id',
        'current_points',
        'lifetime_points',
        'points_earned',
        'points_redeemed',
        'points_expired',
        'tier_upgraded_at',
        'joined_at',
    ];

    protected $casts = [
        'tier_upgraded_at' => 'datetime',
        'joined_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_program_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class, 'loyalty_tier_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class, 'customer_loyalty_id');
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(LoyaltyRedemption::class, 'customer_loyalty_id');
    }
}

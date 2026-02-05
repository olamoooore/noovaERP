<?php

namespace Modules\Loyalty\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyTier extends Model
{
    protected $table = 'loyalty_tiers';

    protected $fillable = [
        'loyalty_program_id',
        'name',
        'min_points',
        'max_points',
        'earning_multiplier',
        'discount_percent',
        'benefits',
        'color',
        'sort_order',
    ];

    protected $casts = [
        'benefits' => 'json',
        'earning_multiplier' => 'decimal:2',
        'discount_percent' => 'decimal:2',
    ];

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_program_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(CustomerLoyalty::class, 'loyalty_tier_id');
    }
}

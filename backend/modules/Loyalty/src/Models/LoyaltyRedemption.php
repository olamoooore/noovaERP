<?php

namespace Modules\Loyalty\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyRedemption extends Model
{
    protected $table = 'loyalty_redemptions';

    protected $fillable = [
        'customer_loyalty_id',
        'loyalty_reward_id',
        'loyalty_transaction_id',
        'points_used',
        'discount_amount',
        'invoice_id',
        'status',
        'redeemed_at',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'redeemed_at' => 'datetime',
    ];

    public function customerLoyalty(): BelongsTo
    {
        return $this->belongsTo(CustomerLoyalty::class, 'customer_loyalty_id');
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyReward::class, 'loyalty_reward_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTransaction::class, 'loyalty_transaction_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Invoice::class, 'invoice_id');
    }
}

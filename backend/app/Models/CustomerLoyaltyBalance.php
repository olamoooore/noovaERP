<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerLoyaltyBalance extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'program_id',
        'points_balance',
        'points_earned_total',
        'points_redeemed_total',
        'last_activity_at',
    ];

    protected $casts = [
        'points_balance' => 'integer',
        'points_earned_total' => 'integer',
        'points_redeemed_total' => 'integer',
        'last_activity_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(CustomerLoyaltyProgram::class, 'program_id');
    }
}

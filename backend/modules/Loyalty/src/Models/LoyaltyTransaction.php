<?php

namespace Modules\Loyalty\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyTransaction extends Model
{
    protected $table = 'loyalty_transactions';

    protected $fillable = [
        'customer_loyalty_id',
        'type',
        'points',
        'balance_after',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'description',
        'expires_at',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public $timestamps = false;

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (! $model->created_at) {
                $model->created_at = now();
            }
        });
    }

    public function customerLoyalty(): BelongsTo
    {
        return $this->belongsTo(CustomerLoyalty::class, 'customer_loyalty_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function reference()
    {
        return $this->morphTo('reference');
    }
}

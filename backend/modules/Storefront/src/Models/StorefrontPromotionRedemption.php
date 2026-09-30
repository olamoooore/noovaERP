<?php

namespace Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontPromotionRedemption extends Model
{
    protected $table = 'storefront_promotion_redemptions';

    protected $fillable = ['promotion_id', 'order_id', 'account_id', 'amount'];

    protected $casts = ['amount' => 'decimal:2'];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(StorefrontPromotion::class, 'promotion_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(StorefrontOrder::class, 'order_id');
    }
}

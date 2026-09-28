<?php

namespace Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontOrderPaymentAttempt extends Model
{
    protected $table = 'storefront_order_payment_attempts';

    protected $fillable = ['order_id', 'attempt_no', 'gateway', 'transaction_ref', 'status', 'error'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(StorefrontOrder::class, 'order_id');
    }
}

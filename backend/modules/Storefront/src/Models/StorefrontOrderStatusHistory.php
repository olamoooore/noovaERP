<?php

namespace Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontOrderStatusHistory extends Model
{
    protected $table = 'storefront_order_status_history';

    protected $fillable = ['order_id', 'from_status', 'to_status', 'actor_type', 'actor_id', 'note'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(StorefrontOrder::class, 'order_id');
    }
}

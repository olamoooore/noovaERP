<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderStatusHistory extends Model
{
    protected $table = 'sales_order_status_history';

    public $timestamps = true;

    protected $fillable = [
        'sales_order_id',
        'from_status',
        'to_status',
        'actor_id',
        'actor_role',
        'source',
        'reason',
        'idempotency_key',
        'transitioned_at',
    ];

    protected $casts = [
        'transitioned_at' => 'datetime',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'actor_id');
    }
}

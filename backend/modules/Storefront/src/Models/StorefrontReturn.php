<?php

namespace Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontReturn extends Model
{
    public const STATUSES = ['requested', 'approved', 'rejected', 'received', 'refunded', 'closed'];

    protected $table = 'storefront_returns';

    protected $fillable = [
        'order_id', 'account_id', 'status', 'reason', 'notes',
        'refund_amount', 'line_items', 'reviewed_by', 'reviewed_at',
        'received_at', 'refunded_at',
    ];

    protected $casts = [
        'refund_amount' => 'decimal:2',
        'line_items' => 'array',
        'reviewed_at' => 'datetime',
        'received_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(StorefrontOrder::class, 'order_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(StorefrontAccount::class, 'account_id');
    }
}

<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryOrderReturnLine extends Model
{
    protected $fillable = [
        'delivery_order_return_id',
        'delivery_order_line_id',
        'product_id',
        'quantity',
        'reason',
        'lot_number',
        'batch_number',
        'expiry_date',
        'product_batch_id',
        'product_serial_id',
        'serial_numbers',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'expiry_date' => 'date',
        'serial_numbers' => 'array',
    ];

    public function deliveryOrderReturn(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrderReturn::class);
    }

    public function deliveryOrderLine(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrderLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Product::class);
    }
}

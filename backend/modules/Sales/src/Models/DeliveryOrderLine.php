<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryOrderLine extends Model
{
    protected $fillable = [
        'delivery_order_id',
        'sales_order_line_id',
        'product_id',
        'model_number',
        'description',
        'patient_name',
        'quantity_ordered',
        'quantity_delivered',
        'unit_price',
        'line_total',
        'unit',
        'lot_number',
        'batch_number',
        'expiry_date',
    ];

    protected $casts = [
        'quantity_ordered' => 'decimal:4',
        'quantity_delivered' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'line_total' => 'decimal:2',
        'expiry_date' => 'date',
    ];

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Product::class);
    }

    public function getRemainingQuantityAttribute(): float
    {
        return (float) $this->quantity_ordered - (float) $this->quantity_delivered;
    }
}

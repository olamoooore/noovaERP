<?php

namespace Modules\Fulfillment\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentLine extends Model
{
    protected $table = 'shipment_lines';

    protected $fillable = [
        'shipment_id',
        'order_line_id',
        'product_id',
        'sku',
        'name',
        'quantity',
        'weight',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'weight' => 'decimal:2',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Product::class, 'product_id');
    }
}

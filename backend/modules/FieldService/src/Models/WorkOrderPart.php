<?php

namespace Modules\FieldService\Models;

use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderPart extends Model
{
    protected $table = 'fs_work_order_parts';

    protected $fillable = [
        'work_order_id',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'lot_number',
        'serial_number',
        'warehouse_id',
        'stock_deducted',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'stock_deducted' => 'boolean',
    ];

    protected $appends = ['line_total'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function getLineTotalAttribute(): float
    {
        return (float) $this->quantity * (float) $this->unit_price;
    }
}

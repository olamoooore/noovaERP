<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #152: records which blanket-order lines a release sales order
 * consumed, so cancelling the order can restore the committed quantity.
 */
class SalesBlanketOrderRelease extends Model
{
    protected $table = 'sales_blanket_order_releases';

    protected $fillable = [
        'sales_order_id',
        'blanket_order_line_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function blanketOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesBlanketOrderLine::class, 'blanket_order_line_id');
    }
}

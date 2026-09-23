<?php

namespace Modules\Sales\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #152: committed quantity + locked price per product on a blanket order.
 */
class SalesBlanketOrderLine extends Model
{
    protected $table = 'sales_blanket_order_lines';

    protected $fillable = [
        'blanket_order_id',
        'product_id',
        'description',
        'committed_qty',
        'released_qty',
        'unit_price',
        'unit',
    ];

    protected $casts = [
        'committed_qty' => 'decimal:4',
        'released_qty' => 'decimal:4',
        'unit_price' => 'decimal:2',
    ];

    public function blanketOrder(): BelongsTo
    {
        return $this->belongsTo(SalesBlanketOrder::class, 'blanket_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function remainingQty(): float
    {
        return round((float) $this->committed_qty - (float) $this->released_qty, 4);
    }
}

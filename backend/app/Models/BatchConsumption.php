<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #131: ledger of which batch layer each stock movement consumed.
 */
class BatchConsumption extends Model
{
    protected $table = 'batch_consumptions';

    protected $fillable = [
        'stock_movement_id',
        'batch_id',
        'product_id',
        'warehouse_id',
        'quantity',
        'unit_cost',
        'reference_type',
        'reference_id',
        'idempotency_key',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'decimal:2',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

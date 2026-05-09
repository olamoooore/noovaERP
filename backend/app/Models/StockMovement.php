<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\StockAdjustmentReason;

class StockMovement extends Model
{
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'type',
        'quantity',
        'old_quantity',
        'reference',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'adjustment_reason_id',
        'notes',
        'batch_number',
        'expiry_date',
        'balance_after',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'old_quantity' => 'integer',
        'balance_after' => 'integer',
        'expiry_date' => 'date',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function adjustmentReason(): BelongsTo
    {
        return $this->belongsTo(StockAdjustmentReason::class);
    }

    /**
     * Scope for filtering by movement type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for filtering by warehouse
     */
    public function scopeInWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    /**
     * Check if this is an incoming movement
     */
    public function isIncoming(): bool
    {
        return in_array($this->type, ['in', 'purchase', 'transfer_in', 'return']);
    }

    /**
     * Check if this is an outgoing movement
     */
    public function isOutgoing(): bool
    {
        return in_array($this->type, ['out', 'sale', 'transfer_out']);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GoodsReceiptLine — individual line item on a goods receipt note.
 *
 * Carries the quantity variance data: how much was ordered vs received vs
 * accepted vs rejected vs put away. Links to the originating PO line.
 */
class GoodsReceiptLine extends Model
{
    protected $fillable = [
        'goods_receipt_id',
        'purchase_order_item_id',
        'product_id',
        'quantity_ordered',
        'quantity_received',
        'quantity_accepted',
        'quantity_rejected',
        'quantity_putaway',
        'notes',
    ];

    protected $casts = [
        'quantity_ordered' => 'decimal:4',
        'quantity_received' => 'decimal:4',
        'quantity_accepted' => 'decimal:4',
        'quantity_rejected' => 'decimal:4',
        'quantity_putaway' => 'decimal:4',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(\Modules\Inventory\Models\PurchaseOrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    // ── Variance helpers ────────────────────────────────────────────────────

    public function getOverReceiptAttribute(): float
    {
        return max(0, (float) $this->quantity_received - (float) $this->quantity_ordered);
    }

    public function getShortfallAttribute(): float
    {
        return max(0, (float) $this->quantity_ordered - (float) $this->quantity_received);
    }

    public function isFullyReceived(): bool
    {
        return (float) $this->quantity_received >= (float) $this->quantity_ordered;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Models\PurchaseOrder;

/**
 * GoodsReceipt — formal goods receipt note (GRN) document.
 *
 * Replaces the previous pattern of updating PO status on receive.
 * A GRN is the authoritative record of what was physically received,
 * when, by whom, and into which warehouse. It links to the originating
 * purchase order and carries quantity variance data (ordered vs received
 * vs accepted vs rejected).
 */
class GoodsReceipt extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'grn_number',
        'purchase_order_id',
        'warehouse_id',
        'received_by',
        'received_date',
        'carrier_reference',
        'status',
        'notes',
    ];

    protected $casts = [
        'received_date' => 'date',
    ];

    // ── Relationships ────────────────────────────────────────────────────────

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    // ── Status helpers ───────────────────────────────────────────────────────

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    // ── Lifecycle ────────────────────────────────────────────────────────────

    public function confirm(): self
    {
        if ($this->status !== 'draft') {
            throw new \InvalidArgumentException("Cannot confirm GRN with status '{$this->status}'. Must be 'draft'.");
        }

        $this->status = 'confirmed';
        $this->save();

        return $this;
    }

    public function cancel(): self
    {
        if ($this->status === 'confirmed') {
            throw new \InvalidArgumentException('Cannot cancel a confirmed GRN. Create a return instead.');
        }

        $this->status = 'cancelled';
        $this->save();

        return $this;
    }
}

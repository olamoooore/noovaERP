<?php

namespace Modules\Inventory\Models;

use App\Models\Product;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * StockReservation — first-class lifecycle document for stock holds.
 *
 * Replaces the bare reserved_quantity counter on stock_levels with an
 * auditable record that tracks who reserved what, for which order,
 * in which warehouse, and what happened to it.
 *
 * Lifecycle:
 *   open → allocated → fulfilled
 *        ↓              ↓
 *      released       released
 *        ↓
 *      expired (by TTL job)
 *        ↓
 *      cancelled (manual void)
 */
class StockReservation extends Model
{
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'quantity',
        'quantity_fulfilled',
        'status',
        'source_document_type',
        'source_document_id',
        'source_document_line_id',
        'expires_at',
        'created_by',
        'released_by',
        'released_at',
        'notes',
    ];

    protected $casts = [
        // Issue #250: keep fractional base quantities (see StockLevel).
        'quantity' => 'decimal:4',
        // #457 (DO5): fractional base-UOM deliveries must not truncate.
        'quantity_fulfilled' => 'decimal:4',
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
    ];

    // ── Relationships ─────────────────────────────────────────────────────────

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

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['open', 'allocated']);
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'open')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }

    // ── State helpers ─────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return in_array($this->status, ['open', 'allocated']);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast() && $this->isActive();
    }

    /** Quantity that remains outstanding (not yet fulfilled). */
    public function getRemainingQuantityAttribute(): int
    {
        return max(0, $this->quantity - $this->quantity_fulfilled);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ProductBatch Model
 * Batch/Lot tracking with expiry dates for FIFO/FEFO inventory management
 */
class ProductBatch extends Model
{
    use HasFactory;

    /**
     * Issue #131: realigned to the real product_batches schema (lot_number,
     * manufacturing_date, received_date, quantity_reserved,
     * supplier_batch_number). The previous list advertised columns that do not
     * exist (product_variant_id, manufacture_date, reserved_quantity,
     * supplier_batch_ref), so those writes were silently dropped.
     */
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'batch_number',
        'lot_number',
        'manufacturing_date',
        'expiry_date',
        'received_date',
        'supplier_batch_number',
        'supplier_id',
        'quantity',
        'quantity_reserved',
        'cost',
        'status',
        'notes',
    ];

    protected $casts = [
        'manufacturing_date' => 'date',
        'expiry_date' => 'date',
        'received_date' => 'date',
        'quantity' => 'integer',
        'quantity_reserved' => 'integer',
        'cost' => 'decimal:2',
    ];

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_QUARANTINE = 'quarantine';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_RECALLED = 'recalled';

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function serials(): HasMany
    {
        return $this->hasMany(ProductSerial::class, 'batch_id');
    }

    /**
     * Get available quantity (total - reserved)
     */
    public function getAvailableQuantityAttribute(): int
    {
        return (int) $this->quantity - (int) $this->quantity_reserved;
    }

    /**
     * Check if batch is expired
     */
    public function isExpired(): bool
    {
        if (! $this->expiry_date) {
            return false;
        }

        return $this->expiry_date->isPast();
    }

    /**
     * Check if batch is expiring soon (within given days)
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        if (! $this->expiry_date) {
            return false;
        }

        return $this->expiry_date->lte(now()->addDays($days));
    }

    /**
     * Get days until expiry
     */
    public function getDaysUntilExpiryAttribute(): ?int
    {
        if (! $this->expiry_date) {
            return null;
        }

        return now()->diffInDays($this->expiry_date, false);
    }

    /**
     * Generate unique batch number
     */
    public static function generateBatchNumber(int $productId): string
    {
        $date = now()->format('Ymd');
        $random = strtoupper(substr(uniqid(), -4));

        return "LOT-{$productId}-{$date}-{$random}";
    }

    /**
     * Reserve quantity from this batch
     */
    public function reserve(int $quantity): bool
    {
        if ($quantity > $this->available_quantity) {
            return false;
        }

        $this->quantity_reserved = (int) $this->quantity_reserved + $quantity;
        $this->save();

        return true;
    }

    /**
     * Release reserved quantity
     */
    public function release(int $quantity): bool
    {
        $toRelease = min($quantity, (int) $this->quantity_reserved);
        $this->quantity_reserved = (int) $this->quantity_reserved - $toRelease;
        $this->save();

        return true;
    }
}

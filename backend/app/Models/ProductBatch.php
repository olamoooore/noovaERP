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

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'warehouse_id',
        'batch_number',
        'manufacture_date',
        'expiry_date',
        'quantity',
        'reserved_quantity',
        'cost',
        'supplier_id',
        'supplier_batch_ref',
        'status',
        'notes',
    ];

    protected $casts = [
        'manufacture_date' => 'date',
        'expiry_date' => 'date',
        'quantity' => 'integer',
        'reserved_quantity' => 'integer',
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

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
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
        return $this->quantity - $this->reserved_quantity;
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

        $this->reserved_quantity += $quantity;
        $this->save();

        return true;
    }

    /**
     * Release reserved quantity
     */
    public function release(int $quantity): bool
    {
        $toRelease = min($quantity, $this->reserved_quantity);
        $this->reserved_quantity -= $toRelease;
        $this->save();

        return true;
    }
}

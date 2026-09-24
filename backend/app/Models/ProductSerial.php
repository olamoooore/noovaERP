<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductSerial Model
 * Individual serial number tracking for high-value items
 */
class ProductSerial extends Model
{
    use HasFactory;

    /**
     * Issue #131: realigned to the real product_serials schema (sold_at,
     * warranty_start_date/warranty_end_date, sale_reference, mac_address,
     * imei, manufacturing_date, supplier_id). The previous list advertised
     * purchase_date / warranty_expiry, which do not exist, so marking a serial
     * sold silently wrote nothing.
     */
    protected $fillable = [
        'product_id',
        'product_variant_id',
        'warehouse_id',
        'batch_id',
        'serial_number',
        'mac_address',
        'imei',
        'manufacturing_date',
        'warranty_start_date',
        'warranty_end_date',
        'supplier_id',
        'status',
        'customer_id',
        'sold_at',
        'sale_reference',
        'cost',
        'notes',
    ];

    protected $casts = [
        'manufacturing_date' => 'date',
        'warranty_start_date' => 'date',
        'warranty_end_date' => 'date',
        'sold_at' => 'datetime',
        'cost' => 'decimal:2',
    ];

    /**
     * Issue #131: realigned to the real product_serials_status_check constraint
     * (available / reserved / sold / returned / defective / scrapped). The
     * previous 'in_stock' and 'warranty_repair' values were rejected by the
     * database check constraint.
     */
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_RESERVED = 'reserved';

    /** Backwards-compatible alias used by existing stock receiving flows. */
    public const STATUS_IN_STOCK = self::STATUS_AVAILABLE;

    public const STATUS_SOLD = 'sold';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_DEFECTIVE = 'defective';

    public const STATUS_SCRAPPED = 'scrapped';

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

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'batch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Check if warranty is still valid
     */
    public function isUnderWarranty(): bool
    {
        if (! $this->warranty_end_date) {
            return false;
        }

        return $this->warranty_end_date->isFuture();
    }

    /**
     * Get days remaining in warranty
     */
    public function getWarrantyDaysRemainingAttribute(): ?int
    {
        if (! $this->warranty_end_date) {
            return null;
        }
        if ($this->warranty_end_date->isPast()) {
            return 0;
        }

        return now()->diffInDays($this->warranty_end_date);
    }

    /**
     * Mark as sold to customer
     */
    public function markSold(?int $customerId, \Carbon\Carbon|string|null $soldAt = null, ?string $saleReference = null): void
    {
        $soldAt = $soldAt ? \Carbon\Carbon::parse($soldAt) : now();

        $this->status = self::STATUS_SOLD;
        $this->customer_id = $customerId;
        $this->sold_at = $soldAt;
        $this->sale_reference = $saleReference ?? $this->sale_reference;
        $this->warranty_start_date = $soldAt->toDateString();

        // Set warranty end if the product carries a warranty term.
        $warrantyMonths = $this->product?->warranty_months;
        if ($warrantyMonths) {
            $this->warranty_end_date = $soldAt->copy()->addMonths((int) $warrantyMonths)->toDateString();
        }

        $this->save();
    }

    /**
     * Mark as returned
     */
    public function markReturned(bool $returnToStock = true): void
    {
        $this->status = $returnToStock ? self::STATUS_AVAILABLE : self::STATUS_RETURNED;
        $this->save();
    }
}

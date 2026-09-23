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

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'warehouse_id',
        'batch_id',
        'serial_number',
        'status',
        'customer_id',
        'manufacturing_date',
        'warranty_start_date',
        'warranty_end_date',
        'supplier_id',
        'cost',
        'sold_at',
        'sale_reference',
        'notes',
    ];

    protected $casts = [
        'manufacturing_date' => 'date',
        'warranty_start_date' => 'date',
        'warranty_end_date' => 'date',
        'sold_at' => 'datetime',
        'cost' => 'decimal:2',
    ];

    public const STATUS_IN_STOCK = 'available';

    public const STATUS_SOLD = 'sold';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_WARRANTY_REPAIR = 'warranty_repair';

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
        $this->status = self::STATUS_SOLD;
        $this->customer_id = $customerId;
        $this->sold_at = $soldAt ? \Carbon\Carbon::parse($soldAt) : now();
        $this->sale_reference = $saleReference;

        // Set warranty expiry if product has warranty
        if ($this->product->warranty_months) {
            $this->warranty_start_date = $this->sold_at->toDateString();
            $this->warranty_end_date = $this->sold_at->copy()->addMonths($this->product->warranty_months)->toDateString();
        }

        $this->save();
    }

    /**
     * Mark as returned
     */
    public function markReturned(bool $returnToStock = true): void
    {
        $this->status = $returnToStock ? self::STATUS_IN_STOCK : self::STATUS_RETURNED;
        $this->save();
    }
}

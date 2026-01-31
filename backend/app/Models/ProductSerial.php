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
        'purchase_date',
        'warranty_expiry',
        'cost',
        'notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'warranty_expiry' => 'date',
        'cost' => 'decimal:2',
    ];

    public const STATUS_IN_STOCK = 'in_stock';
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
        if (!$this->warranty_expiry) {
            return false;
        }
        return $this->warranty_expiry->isFuture();
    }

    /**
     * Get days remaining in warranty
     */
    public function getWarrantyDaysRemainingAttribute(): ?int
    {
        if (!$this->warranty_expiry) {
            return null;
        }
        if ($this->warranty_expiry->isPast()) {
            return 0;
        }
        return now()->diffInDays($this->warranty_expiry);
    }

    /**
     * Mark as sold to customer
     */
    public function markSold(int $customerId, ?\Carbon\Carbon $purchaseDate = null): void
    {
        $this->status = self::STATUS_SOLD;
        $this->customer_id = $customerId;
        $this->purchase_date = $purchaseDate ?? now();
        
        // Set warranty expiry if product has warranty
        if ($this->product->warranty_months) {
            $this->warranty_expiry = $this->purchase_date->addMonths($this->product->warranty_months);
        }
        
        $this->save();
    }

    /**
     * Mark as returned
     */
    public function markReturned(): void
    {
        $this->status = self::STATUS_RETURNED;
        $this->save();
    }
}

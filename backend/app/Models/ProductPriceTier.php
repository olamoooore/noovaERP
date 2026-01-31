<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductPriceTier Model
 * Volume pricing and customer-specific pricing
 */
class ProductPriceTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'product_variant_id',
        'name',
        'min_quantity',
        'max_quantity',
        'price',
        'discount_percent',
        'valid_from',
        'valid_until',
        'customer_id',
        'customer_group',
        'is_active',
    ];

    protected $casts = [
        'min_quantity' => 'integer',
        'max_quantity' => 'integer',
        'price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Check if this tier is valid for given quantity and date
     */
    public function isValidFor(int $quantity, ?\Carbon\Carbon $date = null): bool
    {
        $date = $date ?? now();

        // Check quantity range
        if ($quantity < $this->min_quantity) {
            return false;
        }
        if ($this->max_quantity && $quantity > $this->max_quantity) {
            return false;
        }

        // Check date range
        if ($this->valid_from && $date->lt($this->valid_from)) {
            return false;
        }
        if ($this->valid_until && $date->gt($this->valid_until)) {
            return false;
        }

        return $this->is_active;
    }

    /**
     * Calculate price for given base price
     */
    public function calculatePrice(float $basePrice): float
    {
        if ($this->price !== null) {
            return (float) $this->price;
        }

        if ($this->discount_percent !== null) {
            return $basePrice * (1 - $this->discount_percent / 100);
        }

        return $basePrice;
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductPriceTier Model
 * Volume pricing and customer-specific pricing.
 *
 * Issue #132: the model previously advertised product_variant_id / name /
 * price / discount_percent, none of which exist in the real
 * product_price_tiers table (discount_type + discount_value do), so every
 * calculation fell through to the base price.
 */
class ProductPriceTier extends Model
{
    use HasFactory;

    public const TYPE_PERCENTAGE = 'percentage';

    public const TYPE_FIXED = 'fixed';

    public const TYPE_FIXED_PRICE = 'fixed_price';

    protected $fillable = [
        'product_id',
        'customer_id',
        'customer_group',
        'min_quantity',
        'max_quantity',
        'discount_type',
        'discount_value',
        'valid_from',
        'valid_until',
        'is_active',
    ];

    protected $casts = [
        'min_quantity' => 'integer',
        'max_quantity' => 'integer',
        'discount_value' => 'decimal:2',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Check if this tier is valid for given quantity and date
     */
    public function isValidFor(int $quantity, ?\Carbon\CarbonInterface $date = null): bool
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

        return (bool) $this->is_active;
    }

    /**
     * Calculate the per-unit price for this tier from a base price.
     */
    public function calculatePrice(float $basePrice): float
    {
        $value = (float) $this->discount_value;

        return match ($this->discount_type) {
            self::TYPE_FIXED_PRICE => $value,
            self::TYPE_FIXED => max($basePrice - $value, 0.0),
            self::TYPE_PERCENTAGE => max($basePrice * (1 - $value / 100), 0.0),
            default => $basePrice,
        };
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductBundle Model
 * Components of a bundle/kit product
 */
class ProductBundle extends Model
{
    use HasFactory;

    /**
     * Issue #130: realign to the actual product_bundles schema
     * (component_variant_id + discount_type/discount_value; the previous
     * discount_percent column does not exist, so bundle discounts silently
     * never applied).
     */
    protected $fillable = [
        'bundle_product_id',
        'component_product_id',
        'component_variant_id',
        'quantity',
        'discount_type',
        'discount_value',
        'is_optional',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'discount_value' => 'decimal:2',
        'is_optional' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The bundle product
     */
    public function bundleProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'bundle_product_id');
    }

    /**
     * The component product
     */
    public function componentProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    /**
     * Calculate component price with discount
     */
    public function getDiscountedPriceAttribute(): float
    {
        $basePrice = (float) $this->componentProduct->price * (float) $this->quantity;
        $value = (float) $this->discount_value;

        return match ($this->discount_type) {
            'fixed_price' => $value * (float) $this->quantity,
            'fixed' => max($basePrice - $value, 0.0),
            'percentage' => max($basePrice * (1 - $value / 100), 0.0),
            default => $basePrice,
        };
    }

    /**
     * Calculate component cost
     */
    public function getTotalCostAttribute(): float
    {
        return (float) ($this->componentProduct->cost ?? 0) * (float) $this->quantity;
    }
}

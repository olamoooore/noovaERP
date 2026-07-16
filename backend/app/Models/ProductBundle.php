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

    protected $fillable = [
        'bundle_product_id',
        'component_product_id',
        'quantity',
        'is_optional',
        'discount_percent',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'is_optional' => 'boolean',
        'discount_percent' => 'decimal:2',
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
        $basePrice = $this->componentProduct->price * $this->quantity;
        $discount = $basePrice * ($this->discount_percent / 100);

        return $basePrice - $discount;
    }

    /**
     * Calculate component cost
     */
    public function getTotalCostAttribute(): float
    {
        return $this->componentProduct->cost * $this->quantity;
    }
}

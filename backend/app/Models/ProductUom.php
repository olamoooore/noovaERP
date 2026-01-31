<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductUom Model
 * Unit of Measure conversions for products (e.g., 1 Box = 12 Pieces)
 */
class ProductUom extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'short_name',
        'conversion_factor',
        'price_multiplier',
        'barcode',
        'weight',
        'length',
        'width',
        'height',
        'is_purchase_uom',
        'is_sales_uom',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'conversion_factor' => 'decimal:6',
        'price_multiplier' => 'decimal:4',
        'weight' => 'decimal:3',
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'height' => 'decimal:2',
        'is_purchase_uom' => 'boolean',
        'is_sales_uom' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Convert quantity from base UOM to this UOM
     */
    public function convertFromBase(float $baseQuantity): float
    {
        return $baseQuantity / $this->conversion_factor;
    }

    /**
     * Convert quantity from this UOM to base UOM
     */
    public function convertToBase(float $quantity): float
    {
        return $quantity * $this->conversion_factor;
    }

    /**
     * Calculate price for this UOM based on base price
     */
    public function calculatePrice(float $basePrice): float
    {
        return $basePrice * $this->conversion_factor * $this->price_multiplier;
    }

    /**
     * Get volume (L×W×H)
     */
    public function getVolumeAttribute(): ?float
    {
        if ($this->length && $this->width && $this->height) {
            return $this->length * $this->width * $this->height;
        }
        return null;
    }
}

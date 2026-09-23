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

    /**
     * Issue #135: these names must match the real product_uoms schema
     * (migration 2026_01_06_000008-era create): abbreviation, is_base_unit,
     * is_purchase_unit, is_sales_unit. The previous list advertised
     * short_name / is_purchase_uom / is_sales_uom, which do not exist, so those
     * values were silently dropped on mass assignment.
     */
    protected $fillable = [
        'product_id',
        'name',
        'abbreviation',
        'conversion_factor',
        'is_base_unit',
        'is_purchase_unit',
        'is_sales_unit',
        'price_multiplier',
        'barcode',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'conversion_factor' => 'decimal:6',
        'price_multiplier' => 'decimal:4',
        'is_base_unit' => 'boolean',
        'is_purchase_unit' => 'boolean',
        'is_sales_unit' => 'boolean',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Backwards-compatible virtual attribute: existing API consumers/frontend
     * read `short_name`; the real column is `abbreviation`.
     */
    public function getShortNameAttribute(): ?string
    {
        return $this->attributes['abbreviation'] ?? null;
    }

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

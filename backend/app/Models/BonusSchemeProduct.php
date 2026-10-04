<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BonusSchemeProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'bonus_scheme_id',
        'qualifying_product_id',
        'scheme_type',
        'discount_amount',
        'discount_percentage',
        'minimum_purchase_qty',
        'foc_product_id',
        'foc_qty',
        'priority',
        'status',
    ];

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'minimum_purchase_qty' => 'decimal:4',
        'foc_qty' => 'decimal:4',
        'priority' => 'integer',
    ];

    // Relationships
    public function bonusScheme(): BelongsTo
    {
        return $this->belongsTo(BonusScheme::class);
    }

    public function qualifyingProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'qualifying_product_id');
    }

    public function focProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'foc_product_id');
    }

    // Accessors
    public function getIsFixedDiscountAttribute(): bool
    {
        return $this->scheme_type === 'fixed_discount';
    }

    public function getIsPercentageDiscountAttribute(): bool
    {
        return $this->scheme_type === 'percentage_discount';
    }

    public function getIsBuyXGetYAttribute(): bool
    {
        return $this->scheme_type === 'buy_x_get_y';
    }

    // Methods
    public function calculateDiscount(float $qty, float $unitPrice): array
    {
        $discount = 0;
        $focQty = 0;

        switch ($this->scheme_type) {
            case 'fixed_discount':
                $discount = (float) $this->discount_amount;
                break;

            case 'percentage_discount':
                $discount = ($unitPrice * $qty) * ($this->discount_percentage / 100);
                break;

            case 'buy_x_get_y':
                if ($this->minimum_purchase_qty > 0 && $qty >= $this->minimum_purchase_qty) {
                    $qualifyingSets = floor($qty / $this->minimum_purchase_qty);
                    $focQty = $qualifyingSets * $this->foc_qty;
                }
                break;
        }

        return [
            'discount' => $discount,
            'foc_qty' => $focQty,
        ];
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForProduct($query, $productId)
    {
        return $query->where('qualifying_product_id', $productId);
    }

    public function scopeByPriority($query)
    {
        return $query->orderBy('priority', 'desc');
    }
}

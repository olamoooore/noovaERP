<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Inventory\Models\Supplier;

/**
 * ProductSupplier Model
 * Links products to their suppliers with procurement details
 */
class ProductSupplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'supplier_id',
        'supplier_sku',
        'supplier_barcode',
        'cost',
        'last_cost',
        'lead_time_days',
        'moq',
        'order_multiple',
        'priority',
        'is_preferred',
        'last_purchase_date',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'last_cost' => 'decimal:2',
        'lead_time_days' => 'integer',
        'moq' => 'integer',
        'order_multiple' => 'integer',
        'priority' => 'integer',
        'is_preferred' => 'boolean',
        'last_purchase_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Calculate estimated delivery date based on lead time
     */
    public function getEstimatedDeliveryDate(): \Carbon\Carbon
    {
        return now()->addDays($this->lead_time_days);
    }

    /**
     * Check if reorder is needed based on MOQ
     */
    public function validateOrderQuantity(int $quantity): bool
    {
        if ($quantity < $this->moq) {
            return false;
        }
        
        if ($this->order_multiple > 1 && $quantity % $this->order_multiple !== 0) {
            return false;
        }
        
        return true;
    }

    /**
     * Get suggested order quantity (rounded to order multiple)
     */
    public function getSuggestedOrderQuantity(int $needed): int
    {
        $quantity = max($needed, $this->moq);
        
        if ($this->order_multiple > 1) {
            $quantity = ceil($quantity / $this->order_multiple) * $this->order_multiple;
        }
        
        return (int) $quantity;
    }
}

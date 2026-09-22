<?php

namespace Modules\Inventory\Models;

use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockLevel extends Model
{
    protected $fillable = [
        'product_id',
        'warehouse_id',
        'quantity',
        'reserved_quantity',
        'min_quantity',
        'max_quantity',
        'bin_location',
    ];

    protected $casts = [
        // Issue #250: fractional UOM-converted quantities must survive the
        // model cast (integer casts truncate on assignment).
        'quantity' => 'decimal:4',
        'reserved_quantity' => 'decimal:4',
        'min_quantity' => 'decimal:4',
        'max_quantity' => 'decimal:4',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function getAvailableQuantityAttribute(): int
    {
        return max(0, $this->quantity - $this->reserved_quantity);
    }

    /**
     * A stock level is "low" when:
     *  - quantity is above zero (it's not simply out-of-stock), AND
     *  - a meaningful min_quantity is configured (> 0), AND
     *  - quantity is at or below that threshold.
     *
     * Returns false when min_quantity is 0 or null to avoid every record
     * with no configured minimum being incorrectly flagged as low-stock.
     */
    public function isLowStock(): bool
    {
        if (! $this->min_quantity || $this->min_quantity <= 0) {
            return false;
        }

        return $this->quantity > 0 && $this->quantity <= $this->min_quantity;
    }

    public function isOutOfStock(): bool
    {
        return $this->quantity <= 0;
    }
}

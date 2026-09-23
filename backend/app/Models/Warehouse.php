<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Models\PurchaseOrder;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Models\WarehouseTransfer;

class Warehouse extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'code',
        'address',
        'phone',
        'email',
        'manager_name',
        'is_active',
        'is_default',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    /**
     * Get stock levels for this warehouse
     */
    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    /**
     * Get stock movements for this warehouse
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Get purchase orders for this warehouse
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * Get outgoing transfers from this warehouse
     */
    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(WarehouseTransfer::class, 'from_warehouse_id');
    }

    /**
     * Get incoming transfers to this warehouse
     */
    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(WarehouseTransfer::class, 'to_warehouse_id');
    }

    /**
     * Get the default warehouse
     */
    public static function getDefault(): ?self
    {
        return static::where('is_default', true)->first();
    }

    /**
     * Set this warehouse as default
     */
    public function setAsDefault(): void
    {
        // Do not clear this row in the bulk update. If the in-memory model
        // already contains true, Eloquent otherwise considers the following
        // save clean and leaves the database value set to false.
        static::whereKeyNot($this->getKey())
            ->where('is_default', true)
            ->update(['is_default' => false]);

        static::whereKey($this->getKey())->update(['is_default' => true]);
        $this->refresh();
    }

    /**
     * Get total stock value in this warehouse
     */
    public function getTotalStockValue(): float
    {
        return $this->stockLevels()
            ->join('products', 'stock_levels.product_id', '=', 'products.id')
            ->selectRaw('SUM(stock_levels.quantity * products.cost) as total')
            ->value('total') ?? 0;
    }

    /**
     * Get total stock count in this warehouse
     */
    public function getTotalStockCount(): int
    {
        return $this->stockLevels()->sum('quantity');
    }
}

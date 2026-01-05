<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventory\Models\Brand;
use Modules\Inventory\Models\StockLevel;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'secondary_name',
        'sku',
        'barcode',
        'description',
        'category_id',
        'brand_id',
        'type',
        'cost',
        'price',
        'tax_rate',
        'tax_mode',
        'tax_included',
        'tax_rate_override',
        'tax_id',
        'income_account_id',
        'expense_account_id',
        'track_inventory',
        'is_preorder',
        'is_new_arrival',
        'show_on_website',
        'preorder_available_at',
        'stock_quantity',
        'reorder_level',
        'min_stock_threshold',
        'unit',
        'image',
        'gallery',
        'attributes',
        'is_active',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_rate_override' => 'decimal:2',
        'tax_included' => 'boolean',
        'gallery' => 'array',
        'attributes' => 'array',
        'track_inventory' => 'boolean',
        'is_preorder' => 'boolean',
        'is_new_arrival' => 'boolean',
        'show_on_website' => 'boolean',
        'preorder_available_at' => 'date:Y-m-d',
        'is_active' => 'boolean',
    ];

    public function tax()
    {
        return $this->belongsTo(Tax::class);
    }

    protected $appends = ['stock'];

    public function getStockAttribute()
    {
        return $this->stock_quantity;
    }

    public function incomeAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'income_account_id');
    }

    public function expenseAccount()
    {
        return $this->belongsTo(ChartOfAccount::class, 'expense_account_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function stockLevels(): HasMany
    {
        return $this->hasMany(StockLevel::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function featureValues(): HasMany
    {
        return $this->hasMany(ProductFeatureValue::class);
    }

    public function customFieldValues(): HasMany
    {
        return $this->hasMany(ProductCustomFieldValue::class);
    }

    public function adjustStock(int $quantity, string $type, ?string $reference = null, ?int $warehouseId = null): void
    {
        $this->stock_quantity += ($type === 'in' ? $quantity : -$quantity);
        $this->save();

        StockMovement::create([
            'product_id' => $this->id,
            'warehouse_id' => $warehouseId,
            'type' => $type,
            'quantity' => abs($quantity),
            'old_quantity' => $this->stock_quantity - ($type === 'in' ? $quantity : -$quantity),
            'reference' => $reference,
            'balance_after' => $this->stock_quantity,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * Get stock level for a specific warehouse
     */
    public function getStockInWarehouse(int $warehouseId): int
    {
        $stockLevel = $this->stockLevels()->where('warehouse_id', $warehouseId)->first();
        return $stockLevel ? $stockLevel->quantity : 0;
    }

    /**
     * Get available stock (total - reserved) for a specific warehouse
     */
    public function getAvailableStockInWarehouse(int $warehouseId): int
    {
        $stockLevel = $this->stockLevels()->where('warehouse_id', $warehouseId)->first();
        return $stockLevel ? $stockLevel->available_quantity : 0;
    }

    /**
     * Check if product is low on stock
     */
    public function isLowStock(): bool
    {
        return $this->stock_quantity <= $this->min_stock_threshold;
    }

    /**
     * Check if product is out of stock
     */
    public function isOutOfStock(): bool
    {
        return $this->stock_quantity <= 0;
    }

    public static function findSellableByBarcodeOrSku(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $variant = ProductVariant::query()
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)->orWhere('sku', $code);
            })
            ->where('is_active', true)
            ->first();

        if ($variant) {
            return [
                'type' => 'variant',
                'variant' => $variant,
                'product' => $variant->product,
            ];
        }

        $product = Product::query()
            ->where(function ($q) use ($code) {
                $q->where('barcode', $code)->orWhere('sku', $code);
            })
            ->where('is_active', true)
            ->first();

        if (!$product) {
            return null;
        }

        return [
            'type' => 'product',
            'product' => $product,
        ];
    }
}

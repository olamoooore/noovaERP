<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'stock_quantity',
        'reorder_level',
        'unit',
        'image',
        'gallery',
        'is_active',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_rate_override' => 'decimal:2',
        'tax_included' => 'boolean',
        'gallery' => 'array',
        'track_inventory' => 'boolean',
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

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function adjustStock(int $quantity, string $type, ?string $reference = null): void
    {
        $this->stock_quantity += ($type === 'in' ? $quantity : -$quantity);
        $this->save();

        StockMovement::create([
            'product_id' => $this->id,
            'type' => $type,
            'quantity' => abs($quantity),
            'reference' => $reference,
            'balance_after' => $this->stock_quantity,
            'created_by' => auth()->id(),
        ]);
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

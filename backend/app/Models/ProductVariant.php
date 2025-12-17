<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'name',
        'sku',
        'barcode',
        'cost',
        'price',
        'tax_rate',
        'track_inventory',
        'stock_quantity',
        'reorder_level',
        'unit',
        'attributes',
        'is_active',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'track_inventory' => 'bool',
        'attributes' => 'array',
        'is_active' => 'bool',
    ];

    protected $appends = ['stock'];

    public function getStockAttribute()
    {
        return $this->stock_quantity;
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function adjustStock(int $quantity, string $type, ?string $reference = null): void
    {
        $this->stock_quantity += ($type === 'in' ? $quantity : -$quantity);
        $this->save();

        StockMovement::create([
            'product_id' => $this->product_id,
            'type' => $type,
            'quantity' => abs($quantity),
            'reference' => $reference,
            'reference_type' => 'ProductVariant',
            'reference_id' => $this->id,
            'balance_after' => $this->stock_quantity,
            'created_by' => auth()->id(),
        ]);
    }
}

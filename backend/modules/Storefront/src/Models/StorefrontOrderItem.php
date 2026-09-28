<?php

namespace Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontOrderItem extends Model
{
    protected $table = 'storefront_order_items';

    protected $fillable = [
        'order_id', 'product_id', 'variant_id', 'name', 'sku', 'unit_price',
        'quantity', 'tax_rate', 'tax_amount', 'discount_amount', 'line_total',
        'income_account_id', 'fulfilled_quantity', 'returned_quantity',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'quantity' => 'decimal:3',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'fulfilled_quantity' => 'decimal:3',
        'returned_quantity' => 'decimal:3',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(StorefrontOrder::class, 'order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Product::class);
    }
}

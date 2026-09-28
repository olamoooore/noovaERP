<?php

namespace Modules\Storefront\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorefrontCartItem extends Model
{
    protected $table = 'storefront_cart_items';

    protected $fillable = ['cart_id', 'product_id', 'variant_id', 'quantity'];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(StorefrontCart::class, 'cart_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ProductVariant::class, 'variant_id');
    }
}

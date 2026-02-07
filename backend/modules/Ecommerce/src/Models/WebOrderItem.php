<?php

namespace Modules\Ecommerce\Models;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebOrderItem extends Model
{
    use HasFactory;

    protected $table = 'web_order_items';

    protected $fillable = [
        'web_order_id',
        'product_id',
        'variant_id',
        'product_name',
        'product_sku',
        'product_image',
        'unit_price',
        'quantity',
        'tax_rate',
        'tax_amount',
        'line_total',
        'income_account_id',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    // Relationships
    public function order(): BelongsTo
    {
        return $this->belongsTo(WebOrder::class, 'web_order_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

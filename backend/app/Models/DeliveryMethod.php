<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #409: a delivery method (Odoo "delivery carrier") — how a shipping
 * charge is priced. The charge itself is an ordinary line billed through the
 * method's service product, so tax, income account and posting come from the
 * existing line pipeline, never from this record.
 */
class DeliveryMethod extends Model
{
    public const PRICING_FIXED = 'fixed';

    public const PRICING_PERCENTAGE = 'percentage';

    public const PRICING_TYPES = [self::PRICING_FIXED, self::PRICING_PERCENTAGE];

    protected $fillable = [
        'name',
        'code',
        'pricing_type',
        'fixed_price',
        'percentage',
        'free_over_amount',
        'product_id',
        'description',
        'is_active',
    ];

    protected $casts = [
        'fixed_price' => 'decimal:4',
        'percentage' => 'decimal:4',
        'free_over_amount' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

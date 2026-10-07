<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #338: one default line of a {@see QuotationTemplate}.
 */
class QuotationTemplateLine extends Model
{
    protected $fillable = [
        'quotation_template_id',
        'line_type',
        'sequence',
        'product_id',
        'description',
        'product_notes',
        'quantity',
        'uom_id',
        'discount_amount',
        'is_optional',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'quantity' => 'decimal:4',
        'discount_amount' => 'decimal:2',
        'is_optional' => 'boolean',
    ];

    public function template(): BelongsTo
    {
        return $this->belongsTo(QuotationTemplate::class, 'quotation_template_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

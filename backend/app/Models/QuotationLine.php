<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationLine extends Model
{
    protected $fillable = [
        'quotation_id',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'tax_rate',
        'tax_amount',
        'discount_amount',
        'line_total',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        // Auto-compute tax_amount and line_total on save so Quotation::calculateTotals()
        // always has accurate figures (mirrors InvoiceLine behaviour).
        static::saving(function ($line) {
            $line->calculateLineTotals();
        });
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Compute tax_amount and line_total from quantity, unit_price, tax_rate
     * and discount_amount. Tax is computed on the gross amount; the line
     * discount is then subtracted from the net portion (tax-exclusive model).
     */
    public function calculateLineTotals(): void
    {
        $gross = (float) $this->quantity * (float) $this->unit_price;
        $discount = (float) ($this->discount_amount ?? 0);
        $this->tax_amount = round($gross * ((float) $this->tax_rate / 100), 2);
        $this->line_total = round($gross - $discount + $this->tax_amount, 2);
    }
}

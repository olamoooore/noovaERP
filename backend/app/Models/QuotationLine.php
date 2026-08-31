<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationLine extends Model
{
    protected $fillable = [
        'line_type',
        'sequence',
        'quotation_id',
        'product_id',
        'model_number',
        'description',
        'product_notes',
        'quantity',
        'unit_price',
        'tax_rate',
        'tax_amount',
        'discount_amount',
        'line_total',
    ];

    protected $casts = [
        'sequence' => 'integer',
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
     * and discount_amount. Tax is computed on the post-discount amount.
     */
    public function calculateLineTotals(): void
    {
        if (in_array($this->line_type, ['note', 'section'], true)) {
            $this->product_id = null;
            $this->quantity = 0;
            $this->unit_price = 0;
            $this->tax_rate = 0;
            $this->tax_amount = 0;
            $this->discount_amount = 0;
            $this->line_total = 0;
            return;
        }
        $gross = (float) $this->quantity * (float) $this->unit_price;
        $discount = (float) ($this->discount_amount ?? 0);

        if (!empty($this->tax_inclusive)) {
            $divisor = 1 + ((float) $this->tax_rate / 100);
            $netBeforeDiscount = $divisor > 0 ? $gross / $divisor : $gross;
            $taxBeforeDiscount = $gross - $netBeforeDiscount;
            $discountRatio = $gross > 0 ? $discount / $gross : 0;
            $this->tax_amount = round($taxBeforeDiscount * (1 - $discountRatio), 2);
            $netAfterDiscount = $netBeforeDiscount * (1 - $discountRatio);
            $this->line_total = round($netAfterDiscount + $this->tax_amount, 2);
        } else {
            $netAfterDiscount = $gross - $discount;
            $this->tax_amount = round($netAfterDiscount * ((float) $this->tax_rate / 100), 2);
            $this->line_total = round($netAfterDiscount + $this->tax_amount, 2);
        }
    }
}

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
        // Issue #884 (UX2): optional line income account + analytic
        // dimensions; they travel to the sales order and then the invoice.
        'income_account_id',
        'project_id',
        'department_id',
        'model_number',
        'description',
        'product_notes',
        'quantity',
        // Issue #685: the sales UoM the quoted line is denominated in.
        'uom_id',
        'unit_price',
        'base_unit_price',
        'pricing_trace',
        'tax_rate',
        'tax_inclusive',
        'tax_amount',
        'discount_amount',
        'line_total',
    ];

    protected $casts = [
        'sequence' => 'integer',
        // #295 (Q7): decimal quantities (kg/hour/metre), like sales orders.
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'base_unit_price' => 'decimal:4',
        'pricing_trace' => 'array',
        'tax_rate' => 'decimal:2',
        'tax_inclusive' => 'boolean',
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
     * Issue #884 (UX2): the line's own income account override, defaulted from
     * the product at posting when null.
     */
    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'income_account_id');
    }

    /**
     * Issue #685: the sales UoM the quoted line is denominated in.
     */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(ProductUom::class, 'uom_id');
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
        // MRD R3.1: single tax arithmetic via TaxEngineService::calculate().
        $calc = \App\Services\TaxEngineService::calculate(
            (float) ($this->quantity * $this->unit_price),
            (float) $this->tax_rate,
            ! empty($this->tax_inclusive),
            (float) ($this->discount_amount ?? 0),
        );
        $this->tax_amount = $calc['tax'];
        $this->line_total = $calc['gross'];
    }
}

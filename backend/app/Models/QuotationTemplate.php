<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Issue #338 ([SALES-ODOO]): a reusable quotation template — default lines
 * (some of them optional), notes, terms and validity days. A quotation is
 * created from it through the normal POST /quotations path, so template
 * lines are priced by PricingService and taxed by SalesLineTaxResolver like
 * any other quoted line (a template stores no price of its own).
 */
class QuotationTemplate extends Model
{
    protected $fillable = [
        'name',
        'notes',
        'terms',
        'validity_days',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'validity_days' => 'integer',
        'is_active' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationTemplateLine::class)
            ->orderBy('sequence')
            ->orderBy('id');
    }

    /**
     * The template's lines in the shape POST /quotations accepts. Prices are
     * left to the pricing engine (price_mode = auto).
     *
     * @return array<int, array<string, mixed>>
     */
    public function toQuotationLines(): array
    {
        return $this->lines->values()->map(function (QuotationTemplateLine $line, int $index) {
            $isProduct = ($line->line_type ?? 'product') === 'product';

            return array_filter([
                'line_type' => $line->line_type ?? 'product',
                'sequence' => $index,
                'product_id' => $isProduct ? $line->product_id : null,
                'description' => $line->description,
                'product_notes' => $line->product_notes,
                // Note/section rows carry no quantity (the line model zeroes
                // it); the key is still sent, as the editor does.
                'quantity' => $isProduct ? (float) $line->quantity : 0,
                'uom_id' => $isProduct ? $line->uom_id : null,
                'discount_amount' => $isProduct ? (float) $line->discount_amount : null,
                'price_mode' => $isProduct ? 'auto' : null,
                'is_optional' => (bool) $line->is_optional,
            ], fn ($v) => $v !== null);
        })->all();
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCreditNoteLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'credit_note_id',
        'invoice_line_id',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'base_unit_price',
        'pricing_trace',
        'tax_rate',
        'tax_inclusive',
        'discount_amount',
        'tax_amount',
        'line_total',
        'account_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'base_unit_price' => 'decimal:4',
        'pricing_trace' => 'array',
        'tax_rate' => 'decimal:2',
        'tax_inclusive' => 'boolean',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($line) {
            // #512 (CN3): the SAME line arithmetic as invoices — discount and
            // tax mode included. The old qty × price overwrote the real total.
            $calc = InvoiceLine::computeLineTotals(
                (float) $line->quantity,
                (float) $line->unit_price,
                (float) $line->tax_rate,
                (bool) $line->tax_inclusive,
                (float) ($line->discount_amount ?? 0),
            );
            $line->tax_amount = $calc['tax_amount'];
            $line->line_total = $calc['line_total'];
        });
    }

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CustomerCreditNote::class, 'credit_note_id');
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}

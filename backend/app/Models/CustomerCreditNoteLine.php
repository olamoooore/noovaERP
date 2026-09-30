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
        'line_total',
        'account_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'base_unit_price' => 'decimal:4',
        'pricing_trace' => 'array',
        'tax_rate' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($line) {
            $line->line_total = $line->quantity * $line->unit_price;
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

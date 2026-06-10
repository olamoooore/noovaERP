<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorDebitNoteLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'debit_note_id',
        'bill_line_id',
        'product_id',
        'description',
        'quantity',
        'unit_price',
        'tax_rate',
        'line_total',
        'account_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
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

    public function debitNote(): BelongsTo
    {
        return $this->belongsTo(VendorDebitNote::class, 'debit_note_id');
    }

    public function billLine(): BelongsTo
    {
        return $this->belongsTo(BillLine::class);
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

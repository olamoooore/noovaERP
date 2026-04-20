<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
        'line_type',
        'product_id',
        'gl_account_id',
        'cost_center_id',
        'project_id',
        'department_id',
        'branch_id',
        'description',
        'quantity',
        'unit_price',
        'tax_rate',
        'tax_amount',
        'discount_amount',
        'line_total',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($line) {
            $line->calculateTotals();
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function calculateTotals(): void
    {
        $subtotal = $this->quantity * $this->unit_price;
        $this->tax_amount = $subtotal * ($this->tax_rate / 100);
        $this->line_total = $subtotal + $this->tax_amount - $this->discount_amount;
    }
}

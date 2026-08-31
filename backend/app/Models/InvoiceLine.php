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
        'model_number',
        'gl_account_id',
        'cost_center_id',
        'project_id',
        'department_id',
        'branch_id',
        'description',
        'product_notes',
        'lot_number',
        'expiry_date',
        'quantity',
        'unit_price',
        'tax_rate',
        'tax_inclusive',
        'tax_amount',
        'discount_amount',
        'line_total',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_inclusive' => 'boolean',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'expiry_date' => 'date',
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
        $gross = $this->quantity * $this->unit_price;
        $discount = $this->discount_amount ?? 0;

        if ($this->tax_inclusive) {
            // Tax is included in the gross amount: extract net and tax
            $divisor = 1 + ($this->tax_rate / 100);
            $netBeforeDiscount = $divisor > 0 ? $gross / $divisor : $gross;
            $taxBeforeDiscount = $gross - $netBeforeDiscount;

            // Apply discount proportionally to net and tax
            $discountRatio = $gross > 0 ? $discount / $gross : 0;
            $this->tax_amount = round($taxBeforeDiscount * (1 - $discountRatio), 2);
            $netAfterDiscount = $netBeforeDiscount * (1 - $discountRatio);
            $this->line_total = round($netAfterDiscount + $this->tax_amount, 2);
        } else {
            // Tax is exclusive: add tax on top of net-after-discount.
            // Apply discount to net first, then compute tax on the reduced
            // amount so the tax base is post-discount.
            $netAfterDiscount = $gross - $discount;
            $this->tax_amount = round($netAfterDiscount * ($this->tax_rate / 100), 2);
            $this->line_total = round($netAfterDiscount + $this->tax_amount, 2);
        }
    }
}

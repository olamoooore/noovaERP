<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderLine extends Model
{
    protected $fillable = [
        'line_type',
        'sequence',
        'sales_order_id',
        // Issue #140: mirrors the parent order's legal entity.
        'legal_entity_id',
        'product_id',
        'model_number',
        'variant_id',
        'description',
        'product_notes',
        'sku',
        'quantity',
        'quantity_allocated',
        'quantity_fulfilled',
        'quantity_delivered',
        'quantity_invoiced',
        'invoicing_policy',
        'quantity_cancelled',
        'unit',
        'unit_price',
        'list_price',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_rate',
        'tax_inclusive',
        'tax_amount',
        'line_total',
        'income_account_id',
        'source_line_type',
        'source_line_id',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'quantity' => 'decimal:4',
        'quantity_allocated' => 'decimal:4',
        'quantity_fulfilled' => 'decimal:4',
        'quantity_delivered' => 'decimal:4',
        'quantity_invoiced' => 'decimal:4',
        'quantity_cancelled' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'list_price' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_inclusive' => 'boolean',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Product::class);
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ChartOfAccount::class, 'income_account_id');
    }

    public function deliveryLines()
    {
        return $this->hasMany(DeliveryOrderLine::class, 'sales_order_line_id');
    }

    public function getRemainingQuantityAttribute(): string
    {
        return (string) ($this->quantity - $this->quantity_fulfilled - $this->quantity_cancelled);
    }

    public function getUnallocatedQuantityAttribute(): string
    {
        return (string) ($this->quantity - $this->quantity_allocated - $this->quantity_cancelled);
    }

    public function calculateLineTotal(): void
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
        $baseTotal = $this->quantity * $this->unit_price;

        // When a discount_value is explicitly provided (percentage or fixed),
        // derive discount_amount from it. Otherwise honour a directly-set
        // discount_amount (e.g. flat discount from the invoice line editor or
        // CSV import) instead of clobbering it to 0.
        if (($this->discount_value ?? 0) > 0) {
            $this->discount_amount = $this->discount_type === 'percentage'
                ? $baseTotal * ($this->discount_value / 100)
                : $this->discount_value;
        } else {
            $this->discount_amount = $this->discount_amount ?? 0;
        }

        $discount = $this->discount_amount;
        $netTotal = $baseTotal - $discount;

        if ($this->tax_inclusive) {
            $divisor = 1 + ($this->tax_rate / 100);
            $netBeforeDiscount = $divisor > 0 ? $baseTotal / $divisor : $baseTotal;
            $taxBeforeDiscount = $baseTotal - $netBeforeDiscount;
            $discountRatio = $baseTotal > 0 ? $this->discount_amount / $baseTotal : 0;
            $this->tax_amount = round($taxBeforeDiscount * (1 - $discountRatio), 2);
            $netAfterDiscount = $netBeforeDiscount * (1 - $discountRatio);
            $this->line_total = round($netAfterDiscount + $this->tax_amount, 2);
        } else {
            $this->tax_amount = round($netTotal * ($this->tax_rate / 100), 2);
            $this->line_total = round($netTotal + $this->tax_amount, 2);
        }
    }
}

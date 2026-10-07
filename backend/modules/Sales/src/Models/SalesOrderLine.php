<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesOrderLine extends Model
{
    protected $fillable = [
        'line_type',
        // Issue #409: the shipping line added from a delivery method.
        'is_delivery',
        'sequence',
        'sales_order_id',
        // Issue #140: mirrors the parent order's legal entity.
        'legal_entity_id',
        'product_id',
        'model_number',
        'variant_id',
        'description',
        // #1004: sanitized rich-text copy of the description (plain text stays
        // in `description` for search/exports/e-invoice).
        'description_html',
        'product_notes',
        'sku',
        'quantity',
        'quantity_allocated',
        'quantity_fulfilled',
        'quantity_delivered',
        'quantity_invoiced',
        'invoicing_policy',
        'quantity_cancelled',
        'backorder_quantity',
        'unit',
        // Issue #685: the sales UoM this line was entered in. Null (or the
        // base unit) means the persisted quantity is already in stock units.
        'uom_id',
        'unit_price',
        'base_unit_price',
        'pricing_trace',
        'list_price',
        'discount_type',
        'discount_value',
        'discount_amount',
        'tax_rate',
        'tax_inclusive',
        // #302: persisted tax identity resolved at save.
        'tax_rule_id',
        'tax_code',
        'tax_treatment',
        'tax_amount',
        'line_total',
        'income_account_id',
        // Issue #884 (UX2): optional analytic dimensions carried from the
        // quotation line onto the invoice line.
        'project_id',
        'department_id',
        // Issue #332: [{analytic_account_id, percentage}] summing to 100.
        'analytic_distribution',
        'source_line_type',
        'source_line_id',
    ];

    /**
     * #379 (O10): the line is on backorder when stock could not be reserved.
     */
    protected $appends = ['is_backordered'];

    protected $casts = [
        'is_delivery' => 'boolean',
        'analytic_distribution' => 'array',
        'sequence' => 'integer',
        'quantity' => 'decimal:4',
        'quantity_allocated' => 'decimal:4',
        'quantity_fulfilled' => 'decimal:4',
        'quantity_delivered' => 'decimal:4',
        'quantity_invoiced' => 'decimal:4',
        'quantity_cancelled' => 'decimal:4',
        'backorder_quantity' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'base_unit_price' => 'decimal:4',
        'pricing_trace' => 'array',
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

    /**
     * Issue #685: the sales unit this line was entered in.
     */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ProductUom::class, 'uom_id');
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

        // MRD R3.1: single tax arithmetic via TaxEngineService::calculate().
        $calc = \App\Services\TaxEngineService::calculate(
            (float) $baseTotal,
            (float) $this->tax_rate,
            (bool) $this->tax_inclusive,
            (float) ($this->discount_amount ?? 0),
        );
        $this->tax_amount = $calc['tax'];
        $this->line_total = $calc['gross'];
    }

    public function getIsBackorderedAttribute(): bool
    {
        return (float) ($this->backorder_quantity ?? 0) > 0;
    }
}

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
        'sales_order_line_id',
        'line_type',
        'sequence',
        'product_id',
        'product_batch_id',
        'product_serial_id',
        'model_number',
        'gl_account_id',
        'cost_center_id',
        'project_id',
        'department_id',
        // Issue #332: [{analytic_account_id, percentage}] summing to 100.
        'analytic_distribution',
        'branch_id',
        'description',
        // #1004: sanitized rich-text copy of the description (plain text stays
        // in `description` for search/exports/e-invoice).
        'description_html',
        'tax_type',
        'tax_exemption_reason',
        'product_notes',
        'lot_number',
        'serial_number',
        'expiry_date',
        'quantity',
        // Issue #685: the sales UoM the line was entered in; `unit` is the
        // abbreviation snapshot shown on the document ("2 BX"). A null uom_id
        // means the quantity is in the product's stock unit, as before.
        'uom_id',
        'unit',
        'unit_price',
        'base_unit_price',
        'pricing_trace',
        'tax_rate',
        'tax_inclusive',
        // #302: persisted tax identity resolved at save.
        'tax_rule_id',
        'tax_code',
        'tax_treatment',
        'tax_amount',
        'discount_amount',
        'line_total',
        // Issue #882 (SA9): deferred revenue — optional service period.
        'is_deferred',
        'service_start_date',
        'service_end_date',
        // #1006: bonus-scheme FOC line paired under its paid source line.
        'is_foc',
        'source_line_id',
        // Issue #331: on a final invoice's "Less: down payment" line, the
        // down-payment invoice whose deposit it draws down.
        'down_payment_invoice_id',
    ];

    protected $casts = [
        'analytic_distribution' => 'array',
        'sequence' => 'integer',
        'unit_price' => 'decimal:2',
        'base_unit_price' => 'decimal:4',
        'pricing_trace' => 'array',
        'tax_rate' => 'decimal:2',
        'tax_inclusive' => 'boolean',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'expiry_date' => 'date',
        'is_deferred' => 'boolean',
        'service_start_date' => 'date',
        'service_end_date' => 'date',
        'is_foc' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($line) {
            $line->calculateTotals();
            $line->fillLotFromBatch();
        });
    }

    /**
     * A line linked to a batch always carries the human-readable lot number and
     * expiry, even when the client only sent the batch id (the invoice form and
     * conversions used to drop them, so the selected lot never applied).
     */
    public function fillLotFromBatch(): void
    {
        if (! $this->product_batch_id || filled($this->lot_number)) {
            return;
        }

        $batch = ProductBatch::find($this->product_batch_id);

        if (! $batch) {
            return;
        }

        $this->lot_number = $batch->batch_number ?: $batch->lot_number;

        if (blank($this->expiry_date) && $batch->expiry_date) {
            $this->expiry_date = $batch->expiry_date;
        }
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Issue #876 (SA2): the originating sales-order line — it carries its own
     * income_account_id, which the posting reads as a line-level revenue
     * override before falling back to the product's account.
     */
    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\SalesOrderLine::class, 'sales_order_line_id');
    }

    /**
     * Issue #882 (SA9): the deferred-revenue schedule that carries this
     * line's unearned balance until the recognition job releases it.
     */
    public function deferredRevenueSchedule(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DeferredRevenueSchedule::class, 'invoice_line_id');
    }

    /**
     * Issue #685: the sales UoM this line is denominated in. Stock and COGS
     * convert through ProductUomConverter at posting; the line keeps the sales
     * quantity and per-sales-UoM price.
     */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(ProductUom::class, 'uom_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductBatch::class, 'product_batch_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(ProductSerial::class, 'product_serial_id');
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
        // MRD R3.1: single tax arithmetic — all inclusive/exclusive and
        // line-discount handling lives in TaxEngineService::calculate().
        $calc = self::computeLineTotals(
            (float) $this->quantity,
            (float) $this->unit_price,
            (float) $this->tax_rate,
            (bool) $this->tax_inclusive,
            (float) ($this->discount_amount ?? 0),
        );
        $this->tax_amount = $calc['tax_amount'];
        $this->line_total = $calc['line_total'];
    }

    /**
     * Issue #512 (CN3): THE line arithmetic — the one formula invoices and
     * credit notes share. Wraps TaxEngineService::calculate (which owns all
     * inclusive/exclusive/discount handling) so no second calculation can
     * drift from the invoices'.
     *
     * @return array{net: float, tax_amount: float, line_total: float}
     */
    public static function computeLineTotals(
        float $quantity,
        float $unitPrice,
        float $taxRate,
        bool $taxInclusive,
        float $discountAmount = 0.0,
    ): array {
        $calc = \App\Services\TaxEngineService::calculate(
            $quantity * $unitPrice,
            $taxRate,
            $taxInclusive,
            $discountAmount,
        );

        return [
            'net' => $calc['net'],
            'tax_amount' => $calc['tax'],
            'line_total' => $calc['gross'],
        ];
    }
}

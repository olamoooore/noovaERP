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
        'branch_id',
        'description',
        'product_notes',
        'lot_number',
        'serial_number',
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
        'sequence' => 'integer',
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
        $calc = \App\Services\TaxEngineService::calculate(
            (float) ($this->quantity * $this->unit_price),
            (float) $this->tax_rate,
            (bool) $this->tax_inclusive,
            (float) ($this->discount_amount ?? 0),
        );
        $this->tax_amount = $calc['tax'];
        $this->line_total = $calc['gross'];
    }
}

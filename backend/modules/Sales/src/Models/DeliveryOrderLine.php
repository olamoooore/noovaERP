<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryOrderLine extends Model
{
    protected $fillable = [
        'line_type',
        'sequence',
        'delivery_order_id',
        'sales_order_line_id',
        'product_id',
        'product_batch_id',
        'product_serial_id',
        'model_number',
        'description',
        'product_notes',
        'patient_name',
        'patient_name_encrypted',
        'quantity_ordered',
        'quantity_delivered',
        'quantity_invoiced',
        'unit_price',
        'base_unit_price',
        'pricing_trace',
        'line_total',
        'unit',
        // Issue #685: the sales UoM the line was entered in (quantity_delivered
        // is always persisted in stock units — see DeliveryOrderService).
        'uom_id',
        'lot_number',
        'batch_number',
        'serial_number',
        'expiry_date',
        // Issue #131: serials consumed by this delivery line.
        'serial_numbers',
    ];

    protected $casts = [
        'patient_name_encrypted' => \App\Casts\EncryptedOrLegacyString::class,
        'sequence' => 'integer',
        'quantity_ordered' => 'decimal:4',
        'quantity_delivered' => 'decimal:4',
        'quantity_invoiced' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'base_unit_price' => 'decimal:4',
        'pricing_trace' => 'array',
        'line_total' => 'decimal:2',
        'serial_numbers' => 'array',
        'expiry_date' => 'date',
    ];

    public function deliveryOrder(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class);
    }

    public function salesOrderLine(): BelongsTo
    {
        return $this->belongsTo(SalesOrderLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Product::class);
    }

    /**
     * Issue #685: the sales unit the operator entered for this line.
     */
    public function uom(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ProductUom::class, 'uom_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ProductBatch::class, 'product_batch_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ProductSerial::class, 'product_serial_id');
    }

    public function getRemainingQuantityAttribute(): float
    {
        if (in_array($this->line_type, ['note', 'section'], true)) {
            return 0;
        }

        return (float) $this->quantity_ordered - (float) $this->quantity_delivered;
    }

    // Issue #222: patient name is PHI — encrypted at rest, exposed as the
    // virtual patient_name attribute so existing callers keep working.
    protected $appends = ['patient_name'];

    protected $hidden = ['patient_name_encrypted'];

    public function getPatientNameAttribute(): ?string
    {
        return $this->patient_name_encrypted;
    }

    public function setPatientNameAttribute(mixed $value): void
    {
        $this->attributes['patient_name_encrypted'] = ($value === null || $value === '')
            ? null
            : \Illuminate\Support\Facades\Crypt::encryptString((string) $value);
    }
}

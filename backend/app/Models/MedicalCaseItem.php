<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MedicalCaseItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'medical_case_id',
        'sales_order_item_id',
        'invoice_item_id',
        'delivery_order_item_id',
        'product_id',
        'lot_id',
        'allocated_qty',
        'delivered_qty',
        'invoiced_qty',
        'unit_price',
        'is_foc',
        'notes',
    ];

    protected $casts = [
        'allocated_qty' => 'decimal:4',
        'delivered_qty' => 'decimal:4',
        'invoiced_qty' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'is_foc' => 'boolean',
    ];

    // Relationships
    public function medicalCase(): BelongsTo
    {
        return $this->belongsTo(MedicalCase::class);
    }

    public function salesOrderItem(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\SalesOrderLine::class, 'sales_order_item_id');
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class, 'invoice_item_id');
    }

    public function deliveryOrderItem(): BelongsTo
    {
        {
            return $this->belongsTo(\Modules\Sales\Models\DeliveryOrderLine::class, 'delivery_order_item_id');
        }
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function lot(): BelongsTo
    {
        return $this->belongsTo(ProductLot::class, 'lot_id');
    }

    // Accessors
    public function getRemainingAllocatedQtyAttribute(): float
    {
        return $this->allocated_qty - $this->delivered_qty;
    }

    public function getRemainingDeliveredQtyAttribute(): float
    {
        return $this->delivered_qty - $this->invoiced_qty;
    }

    // Validation helpers
    public function canAllocate(float $qty): bool
    {
        return $this->allocated_qty + $qty <= $this->salesOrderItem->quantity;
    }

    public function canDeliver(float $qty): bool
    {
        return $this->delivered_qty + $qty <= $this->allocated_qty;
    }

    public function canInvoice(float $qty): bool
    {
        return $this->invoiced_qty + $qty <= $this->delivered_qty;
    }
}

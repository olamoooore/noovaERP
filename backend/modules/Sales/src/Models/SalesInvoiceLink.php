<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesInvoiceLink extends Model
{
    protected $fillable = [
        'sales_order_id',
        'invoice_id',
        'link_type',
        'link_state',
        'invoiced_qty',
        'invoiced_value',
        'created_by',
    ];

    protected $casts = [
        'invoiced_qty' => 'decimal:4',
        'invoiced_value' => 'decimal:2',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Invoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }
}

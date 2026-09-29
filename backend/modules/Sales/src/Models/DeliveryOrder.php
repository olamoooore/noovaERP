<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'delivery_order_number',
        'sales_order_id',
        'customer_id',
        'warehouse_id',
        'status',
        // Issue #177: dual confirmation path audit trail.
        'confirmation_method',
        'confirmed_via_channel',
        'signed_document_path',
        'signed_document_uploaded_at',
        // Issue #153: signer metadata travels with the resulting Invoice for
        // client AP/payment processing.
        'signed_by_name',
        'signed_by_email',
        'signed_at',
        // Issue #178: rejection/replacement revision chain.
        'correction_group_id',
        'version_number',
        'is_active_version',
        'original_document_id',
        'replacement_document_id',
        'superseded_at',
        'correction_type',
        'correction_reason_code',
        'correction_reason_text',
        'delivery_date',
        'expected_date',
        'payment_terms',
        'completed_at',
        'source',
        'external_reference',
        'po_number',
        'customer_reference',
        'internal_reference',
        'salesperson',
        'attention_to',
        'invoice_id',
        'quotation_id',
        'carrier',
        'tracking_number',
        'shipping_address',
        'shipping_address_overridden',
        'delivery_address_snapshot',
        'delivered_by_user_id',
        'issued_at',
        'delivered_at',
        'notes',
        'total',
        'created_by',
        'confirmed_by',
        'completed_by',
        'acceptance_token',
        'acceptance_expires_at',
        'requires_approval',
        'approval_status',
    ];

    protected $casts = [
        'signed_document_uploaded_at' => 'datetime',
        'signed_at' => 'datetime',
        'acceptance_expires_at' => 'datetime',
        'is_active_version' => 'boolean',
        'requires_approval' => 'boolean',
        'superseded_at' => 'datetime',
        'delivery_date' => 'date',
        'expected_date' => 'date',
        'completed_at' => 'datetime',
        'issued_at' => 'datetime',
        'delivered_at' => 'datetime',
        'shipping_address_overridden' => 'boolean',
        'total' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->delivery_order_number)) {
                $order->delivery_order_number = static::generateNumber();
            }
        });

        // Keep the invoice↔delivery-order pivot in sync with the legacy
        // `invoice_id` pointer: DOs are also linked from forms/imports that
        // write `invoice_id` directly, and a DO may accumulate several invoices
        // (the legacy column stays the primary one).
        static::saved(function ($order) {
            if ($order->invoice_id && ($order->wasRecentlyCreated || $order->wasChanged('invoice_id'))) {
                $order->invoices()->syncWithoutDetaching([$order->invoice_id]);
            }
        });
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Customer::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryOrderLine::class)
            ->orderBy('sequence')
            ->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'confirmed_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'completed_by');
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'delivered_by_user_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Invoice::class);
    }

    /**
     * Every invoice linked to this delivery order — a DO may be billed across
     * several invoices. The legacy `invoice_id` column remains the primary
     * invoice; this relation (via `invoice_delivery_orders`) is the full set.
     */
    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\Invoice::class,
            'invoice_delivery_orders',
            'delivery_order_id',
            'invoice_id',
        )->withTimestamps();
    }

    /**
     * Quantity of a line that can still be invoiced: delivered (or ordered when
     * nothing has shipped) minus whatever earlier invoices already billed.
     */
    public function invoiceableQuantityForLine(DeliveryOrderLine $line): float
    {
        if (($line->line_type ?? 'product') !== 'product') {
            return 0.0;
        }

        $billable = (float) ($line->quantity_delivered ?: $line->quantity_ordered)
            - (float) $line->quantity_invoiced;

        return round(max($billable, 0), 4);
    }

    public function hasInvoiceableLines(): bool
    {
        foreach ($this->lines as $line) {
            if ($this->invoiceableQuantityForLine($line) > 0) {
                return true;
            }
        }

        return false;
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Quotation::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'done';
    }

    public static function generateNumber(): string
    {
        try {
            $number = app(\App\Services\DocumentNumberingService::class)
                ->generate('delivery_order', 'DO-{YYYY}-{SEQ:4}');

            // Verify uniqueness (DocumentNumberingService counter may fall out of sync)
            if (! static::withTrashed()->where('delivery_order_number', $number)->exists()) {
                return $number;
            }
            // Clear counter cache on collision
            cache()->forget('document_numbering_delivery_order');
        } catch (\Throwable $e) {
            // Fallback below
        }

        $year = date('Y');
        $prefix = "DO-{$year}-";

        // Retry loop — find the next available number
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $latest = static::withTrashed()
                ->where('delivery_order_number', 'like', $prefix.'%')
                ->orderBy('delivery_order_number', 'desc')
                ->first();
            $seq = $latest ? ((int) substr($latest->delivery_order_number, -4)) + 1 : 1;
            $number = $prefix.str_pad($seq, 4, '0', STR_PAD_LEFT);

            if (! static::withTrashed()->where('delivery_order_number', $number)->exists()) {
                return $number;
            }
        }

        // Last resort — timestamp suffix
        return $prefix.date('His');
    }
}

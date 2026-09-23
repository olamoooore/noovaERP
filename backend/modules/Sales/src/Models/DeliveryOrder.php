<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
    ];

    protected $casts = [
        'signed_document_uploaded_at' => 'datetime',
        'signed_at' => 'datetime',
        'is_active_version' => 'boolean',
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
        return $this->hasMany(DeliveryOrderLine::class);
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

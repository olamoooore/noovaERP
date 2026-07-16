<?php

namespace Modules\Sales\Models;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'sales_order_number',
        'customer_id',
        'channel',
        'status',
        'accounting_status',
        'source_document_type',
        'source_document_id',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total',
        'customer_reference',
        'notes',
        'internal_notes',
        'currency_code',
        'order_date',
        'required_date',
        'cancellation_reason',
        'cancelled_by',
        'cancelled_at',
        'warehouse_id',
        'created_by',
        'confirmed_by',
        'allocated_by',
        'bill_by_delivered_qty',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'order_date' => 'date',
        'required_date' => 'date',
        'cancelled_at' => 'datetime',
        'warehouse_id' => 'integer',
        'bill_by_delivered_qty' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->sales_order_number)) {
                $order->sales_order_number = static::generateOrderNumber();
            }
            if (empty($order->order_date)) {
                $order->order_date = now()->toDateString();
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(SalesOrderStatusHistory::class);
    }

    public function invoiceLinks(): HasMany
    {
        return $this->hasMany(SalesInvoiceLink::class);
    }

    public function channelReferences(): HasMany
    {
        return $this->hasMany(SalesChannelReference::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'confirmed_by');
    }

    public function allocatedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'allocated_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'cancelled_by');
    }

    public function invoices()
    {
        return $this->belongsToMany(
            \App\Models\Invoice::class,
            'sales_invoice_links',
            'sales_order_id',
            'invoice_id'
        )->withPivot(['link_type', 'link_state', 'invoiced_qty', 'invoiced_value']);
    }

    public function deliveryOrders()
    {
        return $this->hasMany(DeliveryOrder::class);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isAllocated(): bool
    {
        return $this->status === 'allocated';
    }

    public function isFulfilled(): bool
    {
        return $this->status === 'fulfilled';
    }

    public function isInvoiced(): bool
    {
        return $this->status === 'invoiced';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function canTransitionTo(string $newStatus): bool
    {
        $allowed = [
            'draft' => ['confirmed', 'cancelled'],
            'confirmed' => ['allocated', 'cancelled'],
            'allocated' => ['fulfilled', 'cancelled'],
            'fulfilled' => ['invoiced'],
            'invoiced' => [],
            'cancelled' => [],
        ];

        return in_array($newStatus, $allowed[$this->status] ?? []);
    }

    public function calculateTotals(): void
    {
        $this->subtotal = $this->lines->sum('line_total');
        $this->tax_amount = $this->lines->sum('tax_amount');
        $this->total = $this->subtotal + $this->tax_amount - $this->discount_amount;
        $this->save();
    }

    public static function generateOrderNumber(): string
    {
        $latest = static::withTrashed()
            ->where('sales_order_number', 'like', 'SO-'.date('Y').'-%')
            ->orderBy('id', 'desc')
            ->first();

        $number = $latest
            ? (int) substr($latest->sales_order_number, -4) + 1
            : 1;

        return 'SO-'.date('Y').'-'.str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}

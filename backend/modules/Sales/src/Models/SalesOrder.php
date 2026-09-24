<?php

namespace Modules\Sales\Models;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class SalesOrder extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'sales_order_number',
        'customer_id',
        // Issue #140: legal entity the order belongs to (backfilled and set at
        // creation from the customer's entity).
        'legal_entity_id',
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
        'attention_to',
        'contact_email',
        'contact_phone',
        // Issue #149: online quotation acceptance / eSignature portal.
        'acceptance_token',
        'acceptance_expires_at',
        'accepted_at',
        'signer_name',
        'signer_email',
        'signature_data',
        'acceptance_source',
        'customer_name_snapshot',
        'billing_address_snapshot',
        'shipping_address_snapshot',
        'company_registration_number',
        'tax_registration_number',
    ];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'order_date' => 'date',
        'required_date' => 'date',
        'cancelled_at' => 'datetime',
        'acceptance_expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'warehouse_id' => 'integer',
        'bill_by_delivered_qty' => 'boolean',
        'customer_name_snapshot' => 'array',
        'billing_address_snapshot' => 'array',
        'shipping_address_snapshot' => 'array',
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
        return $this->hasMany(SalesOrderLine::class)
            ->orderBy('sequence')
            ->orderBy('id');
    }

    /**
     * Issue #140: scope to an explicit set of legal entities. Fail-closed when
     * the set is empty (no accessible entities means no rows visible).
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int[]  $accessibleEntityIds
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeAccessibleBy($query, array $accessibleEntityIds)
    {
        if (empty($accessibleEntityIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('legal_entity_id', $accessibleEntityIds);
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

    public function quotation()
    {
        return $this->hasOne(\App\Models\Quotation::class, 'sales_order_id');
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
        $maxAttempts = 10;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            // On first attempt, use DocumentNumberingService for normal sequencing.
            // On retries (collision), bypass it and use MAX-based to avoid
            // counter-in-rollback loop where the incremented counter gets
            // rolled back along with the failed INSERT, causing the same number
            // to be generated again.
            $number = $attempt === 0
                ? static::generateOrderNumberRaw()
                : static::generateNextAvailableNumber();

            if (! static::withTrashed()->where('sales_order_number', $number)->exists()) {
                return $number;
            }

            Log::warning('SalesOrder number collision, retrying', [
                'number' => $number,
                'attempt' => $attempt + 1,
            ]);
        }

        // Final fallback: use timestamp-based suffix
        return 'SO-'.date('Y').'-'.date('is');
    }

    private static function generateNextAvailableNumber(): string
    {
        $prefix = 'SO-'.date('Y').'-';

        $latest = static::withTrashed()
            ->where('sales_order_number', 'like', $prefix.'%')
            ->orderByRaw("SUBSTRING(sales_order_number FROM '[0-9]+$')::int DESC")
            ->first();

        $nextSeq = $latest
            ? (int) preg_replace('/[^0-9]/', '', substr($latest->sales_order_number, strlen($prefix))) + 1
            : 1;

        return $prefix.str_pad($nextSeq, 4, '0', STR_PAD_LEFT);
    }

    private static function generateOrderNumberRaw(): string
    {
        try {
            return app(\App\Services\DocumentNumberingService::class)
                ->generate('sales_order', 'SO-{YYYY}-{SEQ:4}');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('DocumentNumberingService failed for SO, using fallback', [
                'error' => $e->getMessage(),
            ]);
        }

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

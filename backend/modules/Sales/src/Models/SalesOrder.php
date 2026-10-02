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
        'internal_reference',
        'po_number',
        'notes',
        'internal_notes',
        'currency_code',
        'exchange_rate',
        'base_currency_code',
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
        'exchange_rate' => 'decimal:6',
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
        // #291: subtotal is NET of tax — line_total is gross (incl. tax), so
        // subtract the line tax. This matches Invoice::calculateTotals and
        // Quotation::calculateTotals; summing the gross line_total and then
        // ADDING tax again double-counted tax whenever a line carried a rate.
        $this->subtotal = round($this->lines->sum(fn ($line) => (float) $line->line_total - (float) $line->tax_amount), 2);
        $lineTax = round($this->lines->sum('tax_amount'), 2);

        $discount = min((float) ($this->discount_amount ?? 0), (float) $this->subtotal);
        $this->discount_amount = round(max($discount, 0), 2);

        // A header discount reduces the taxable base BEFORE tax; pro-rate the
        // line tax by (subtotal - discount) / subtotal so mixed rates keep
        // their relative share (same rule as Invoice/Quotation).
        if ($discount > 0 && $this->subtotal > 0) {
            $taxable = max($this->subtotal - $discount, 0);
            $this->tax_amount = round($lineTax * ($taxable / $this->subtotal), 2);
        } else {
            $this->tax_amount = $lineTax;
        }

        $this->total = round(max($this->subtotal - $discount, 0) + $this->tax_amount, 2);
        $this->save();
    }

    public static function generateOrderNumber(): string
    {
        // #538 (ST1): the numbering service advances its counter past the
        // issued numbers inside the lock, so the counter-in-rollback loop this
        // method used to work around can no longer happen — and there is no
        // timestamp fallback.
        return app(\App\Services\DocumentNumberingService::class)->generateUnique(
            'sales_order',
            'SO-{YYYY}-{SEQ:4}',
            fn (string $prefix): array => static::withTrashed()
                ->where('sales_order_number', 'like', $prefix.'%')
                ->pluck('sales_order_number')
                ->all(),
        );
    }
}

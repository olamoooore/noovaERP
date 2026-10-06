<?php

namespace Modules\Sales\Models;

use App\Platform\Workflow\Contracts\WorkflowSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryOrder extends Model implements WorkflowSubject
{
    use SoftDeletes;

    protected $fillable = [
        'delivery_order_number',
        'sales_order_id',
        // Issue #461: the short delivery this DO is a back-order of.
        'backorder_of_id',
        'customer_id',
        // Issue #462: the legal entity that owns this document (inherited).
        'legal_entity_id',
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
        'reversed_at',
        'reversal_reason',
        // Issue #461: why the undelivered remainder was cancelled (cancel policy).
        'short_close_reason',
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
        'currency_code',
        'exchange_rate',
        'base_currency_code',
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
        'reversed_at' => 'datetime',
        'issued_at' => 'datetime',
        'delivered_at' => 'datetime',
        'shipping_address_overridden' => 'boolean',
        'total' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            if (empty($order->delivery_order_number)) {
                $order->delivery_order_number = static::generateNumber();
            }
            // Issue #462 (DO10): a delivery order inherits the legal entity of
            // its sales order (when linked), else its customer — the same
            // source of truth #140 used for sales orders. The column is
            // resolved here so every creation path (services, replacement
            // drafts, imports going through the model) is stamped.
            if (! $order->legal_entity_id) {
                $order->legal_entity_id = $order->sales_order_id
                    ? SalesOrder::whereKey($order->sales_order_id)->value('legal_entity_id')
                    : null;

                if (! $order->legal_entity_id && $order->customer_id) {
                    $order->legal_entity_id = \App\Models\Customer::whereKey($order->customer_id)
                        ->value('legal_entity_id');
                }
            }
            // MRD pricelist-multicurrency FR-5.6: DOs inherit the SO/quote
            // currency; this hook guarantees non-NULL for any other creator.
            if (! $order->currency_code) {
                $order->currency_code = \App\Services\DocumentCurrencyResolver::resolve();
            }
            if (! $order->base_currency_code) {
                $order->base_currency_code = \App\Services\DocumentCurrencyResolver::baseFor(null, $order->customer_id);
            }
            if ($order->exchange_rate === null
                && $order->currency_code
                && strtoupper($order->currency_code) === strtoupper($order->base_currency_code)) {
                $order->exchange_rate = 1.000000;
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

    /**
     * Issue #462 (DO10): scope to an explicit set of legal entities. Fail-closed
     * when the set is empty (no accessible entities means no rows visible),
     * mirroring SalesOrder::scopeAccessibleBy.
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

    /**
     * Issue #461: the back-order DO this document was created from (a short
     * delivery left a remainder behind).
     */
    public function backorderOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'backorder_of_id');
    }

    /**
     * Issue #461: back-order DOs split off this short delivery.
     */
    public function backorders(): HasMany
    {
        return $this->hasMany(self::class, 'backorder_of_id');
    }

    /**
     * Issue #461: partial customer returns recorded against this delivery.
     */
    public function returns(): HasMany
    {
        return $this->hasMany(DeliveryOrderReturn::class);
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
     * nothing has shipped) minus whatever earlier invoices already billed and
     * minus what customers have since returned — Issue #461, returned goods are
     * back on the shelf and must not be billed again.
     */
    public function invoiceableQuantityForLine(DeliveryOrderLine $line): float
    {
        if (($line->line_type ?? 'product') !== 'product') {
            return 0.0;
        }

        $billable = (float) ($line->quantity_delivered ?: $line->quantity_ordered)
            - (float) $line->quantity_invoiced
            - (float) $line->quantity_returned;

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

    /**
     * #463 (DO11): a reversed delivery order carries status 'cancelled' too
     * (stock was restored, COGS reversed) but is tracked with reversed_at so it
     * is distinguishable from a never-shipped cancellation.
     */
    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function isCompleted(): bool
    {
        return $this->status === 'done';
    }

    public static function generateNumber(): string
    {
        // #899 (PC5): the one platform numbering service owns the locked
        // counter; no timestamp suffix is ever issued and there is no local
        // guess/repair loop.
        return app(\App\Platform\Numbering\NumberingService::class)->nextFor(
            'delivery_order',
            'DO-{YYYY}-{SEQ:4}',
            null,
            fn (string $prefix): array => static::withTrashed()
                ->where('delivery_order_number', 'like', $prefix.'%')
                ->pluck('delivery_order_number')
                ->all(),
        );
    }

    // ─── WorkflowEngine subject (#900, PC6) ───────────────────────

    public function workflowEntityType(): string
    {
        return 'delivery_order';
    }

    public function workflowEntityId(): string
    {
        return (string) $this->getKey();
    }

    public function workflowCurrentStatus(): string
    {
        return (string) ($this->status ?? '');
    }

    public function workflowContext(): array
    {
        return [
            'status' => $this->status,
            'approval_status' => $this->approval_status,
            'requires_approval' => (bool) $this->requires_approval,
            'warehouse_id' => $this->warehouse_id,
            'sales_order_id' => $this->sales_order_id,
        ];
    }

    public function applyWorkflowStatus(string $status, array $context = []): void
    {
        $this->forceFill(['status' => $status])->save();
    }
}

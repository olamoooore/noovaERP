<?php

namespace Modules\Sales\Models;

use App\Models\ApprovalRequest;
use App\Models\Warehouse;
use App\Platform\Workflow\Contracts\WorkflowSubject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesOrder extends Model implements WorkflowSubject
{
    use SoftDeletes;

    /** Issue #408: deliver each line as soon as it is available (partial OK). */
    public const SHIPPING_POLICY_ASAP = 'asap';

    /** Issue #408: only ship once every remaining line is ready (no partials). */
    public const SHIPPING_POLICY_WHEN_ALL_READY = 'when_all_ready';

    /** @var string[] */
    public const SHIPPING_POLICIES = [self::SHIPPING_POLICY_ASAP, self::SHIPPING_POLICY_WHEN_ALL_READY];

    protected $fillable = [
        'sales_order_number',
        'customer_id',
        // Issue #140: legal entity the order belongs to (backfilled and set at
        // creation from the customer's entity).
        'legal_entity_id',
        'channel',
        'status',
        // Issue #407: a confirmed order can be locked against amendments and
        // unlocked to amend it again.
        'is_locked',
        'locked_at',
        'locked_by',
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
        // Issue #408: commitment (promised) date + shipping policy.
        'commitment_date',
        'shipping_policy',
        // Issue #333: Incoterm code + place (free text; no statutory list).
        'incoterm_code',
        'incoterm_place',
        // Issue #321: payment term carried from the quotation; drives the
        // invoice due date.
        'payment_term_id',
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
        // Issue #553: default salesperson/team stamped at creation from the
        // user's Sales Settings defaults (free text; see SalesDefaultsService).
        'salesperson',
        'sales_team',
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

    /**
     * #378 (O9) / #371 (O2): canonical transition map + invoice progress,
     * exposed so the UI never re-implements either.
     *
     * Issue #408: expected_date (computed from lead times) and is_late (past
     * the commitment date and not fully delivered) are appended so the lists
     * and the #365 action queue read them without recomputing.
     */
    protected $appends = ['allowed_transitions', 'invoice_status', 'has_backorder', 'expected_date', 'is_late'];

    protected $casts = [
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'order_date' => 'date',
        'required_date' => 'date',
        // Issue #408
        'commitment_date' => 'date',
        'cancelled_at' => 'datetime',
        'acceptance_expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'warehouse_id' => 'integer',
        'bill_by_delivered_qty' => 'boolean',
        'customer_name_snapshot' => 'array',
        'billing_address_snapshot' => 'array',
        'shipping_address_snapshot' => 'array',
        // Issue #896: optimistic-concurrency token.
        'lock_version' => 'integer',
        // Issue #407: lock state of a confirmed order.
        'is_locked' => 'boolean',
        'locked_at' => 'datetime',
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

    /**
     * #402 (SU4): the latest approval-gate request for this order, so the
     * detail payload carries the approval state the header chip shows.
     */
    public function latestApprovalRequest(): HasOne
    {
        return $this->hasOne(ApprovalRequest::class, 'subject_id')
            ->where('subject_type', static::class)
            ->where('request_type', \App\Services\SalesApprovalGate::REQUEST_TYPE_SALES_ORDER)
            ->latestOfMany();
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

    /**
     * Issue #407: an order is locked when a user (or the tenant's
     * auto-lock-on-confirm option) explicitly locked it after confirmation.
     * Drafts are never lockable (see SalesOrderService::lockOrder).
     */
    public function isLocked(): bool
    {
        return (bool) ($this->is_locked ?? false);
    }

    public function lockedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'locked_by');
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

    /**
     * #371 (O2): invoice progress as a first-class status, kept separate from
     * the delivery/lifecycle state: nothing_to_invoice | to_invoice |
     * partially_invoiced | fully_invoiced, derived from each line's billable
     * (policy-aware) vs invoiced quantity.
     */
    public function invoiceStatus(): string
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        $hasBillable = false;
        $hasInvoiced = false;
        $hasRemaining = false;

        foreach ($lines as $line) {
            if (in_array($line->line_type ?? 'product', ['note', 'section'], true)) {
                continue;
            }

            // Overall invoice progress is measured against the ordered
            // quantity; the line's invoicing policy only limits what THIS
            // moment can bill (delivered_qty waits for deliveries).
            $billable = max((float) $line->quantity - (float) $line->quantity_cancelled, 0);

            if ($billable <= 0.0001) {
                continue;
            }

            $hasBillable = true;
            $invoiced = (float) $line->quantity_invoiced;

            if ($invoiced > 0.0001) {
                $hasInvoiced = true;
            }

            if ($invoiced + 0.0001 < $billable) {
                $hasRemaining = true;
            }
        }

        if (! $hasBillable) {
            return 'nothing_to_invoice';
        }

        if (! $hasInvoiced) {
            return 'to_invoice';
        }

        return $hasRemaining ? 'partially_invoiced' : 'fully_invoiced';
    }

    public function isFullyInvoiced(): bool
    {
        return $this->invoiceStatus() === 'fully_invoiced';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * #378 (O9): delegate to the lifecycle service — the model used to carry a
     * divergent copy of this map (missing confirmed/allocated→invoiced,
     * fulfilled→cancelled, invoiced→invoiced and cancelled→draft).
     */
    public function canTransitionTo(string $newStatus): bool
    {
        return \Modules\Sales\Services\OrderLifecycleService::canTransition((string) $this->status, $newStatus);
    }

    /**
     * @return string[]
     */
    public function allowedStatusTransitions(): array
    {
        return \Modules\Sales\Services\OrderLifecycleService::allowedTransitions((string) $this->status);
    }

    public function getInvoiceStatusAttribute(): string
    {
        return $this->invoiceStatus();
    }

    /**
     * #378 (O9): exposed to the frontend so the UI never re-implements the
     * transition map (button visibility = allowed transitions).
     *
     * @return string[]
     */
    public function getAllowedTransitionsAttribute(): array
    {
        return $this->allowedStatusTransitions();
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
        // #899 (PC5): the one platform numbering service owns the locked
        // counter; it advances past any issued number and never falls back to
        // a guess. The callback keeps imported/legacy numbers reserved.
        return app(\App\Platform\Numbering\NumberingService::class)->nextFor(
            'sales_order',
            'SO-{YYYY}-{SEQ:4}',
            null,
            fn (string $prefix): array => static::withTrashed()
                ->where('sales_order_number', 'like', $prefix.'%')
                ->pluck('sales_order_number')
                ->all(),
        );
    }

    /**
     * #379 (O10): any line that could not be reserved at confirmation.
     */
    public function getHasBackorderAttribute(): bool
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        return $lines->contains(fn ($line) => (float) ($line->backorder_quantity ?? 0) > 0);
    }

    /**
     * Issue #408: every deliverable product line has its ordered (minus
     * cancelled) quantity delivered. Note/section rows never block delivery.
     */
    public function isFullyDelivered(): bool
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        $deliverable = $lines->filter(function ($line) {
            if (in_array($line->line_type ?? 'product', ['note', 'section'], true)) {
                return false;
            }

            return (float) $line->quantity - (float) $line->quantity_cancelled > 0.0001;
        });

        if ($deliverable->isEmpty()) {
            return true;
        }

        return $deliverable->every(
            fn ($line) => (float) $line->quantity_delivered + 0.0001 >= (float) $line->quantity - (float) $line->quantity_cancelled
        );
    }

    /**
     * Issue #408: expected (scheduled) date computed from the product lead
     * times: order date + the longest product/supplier lead time. Null when no
     * line has lead-time data (never invented).
     */
    public function getExpectedDateAttribute(): ?string
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        $productIds = $lines
            ->reject(fn ($line) => in_array($line->line_type ?? 'product', ['note', 'section'], true))
            ->pluck('product_id');

        $baseDate = $this->order_date?->toDateString()
            ?? $this->created_at?->toDateString()
            ?? now()->toDateString();

        return app(\App\Services\SalesLeadTimeService::class)
            ->expectedDate($productIds, $baseDate)
            ?->toDateString();
    }

    /**
     * Issue #408: the order is late when its commitment date has passed and it
     * is not fully delivered (cancelled orders are never late). Exposed to the
     * lists and the #365 dashboard action queue.
     */
    public function isLate(): bool
    {
        if ($this->commitment_date === null) {
            return false;
        }

        if ($this->isCancelled()) {
            return false;
        }

        if (! $this->commitment_date->isBefore(now()->startOfDay())) {
            return false;
        }

        return ! $this->isFullyDelivered();
    }

    public function getIsLateAttribute(): bool
    {
        return $this->isLate();
    }

    /**
     * Issue #408: 'when_all_ready' forbids partial deliveries; every other
     * value (including legacy null) keeps the permissive 'asap' behaviour.
     */
    public function requiresAllProductsReady(): bool
    {
        return $this->shipping_policy === self::SHIPPING_POLICY_WHEN_ALL_READY;
    }

    // ─── WorkflowEngine subject (#900, PC6) ───────────────────────

    public function workflowEntityType(): string
    {
        return 'sales_order';
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
            'sales_order_number' => $this->sales_order_number,
            'total' => (float) $this->total,
        ];
    }

    public function applyWorkflowStatus(string $status, array $context = []): void
    {
        $this->forceFill(['status' => $status])->save();
    }
}

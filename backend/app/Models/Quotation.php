<?php

namespace App\Models;

use App\Platform\Workflow\Contracts\WorkflowSubject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quotation extends Model implements WorkflowSubject
{
    use HasFactory, SoftDeletes;

    /**
     * Issue #634: header fields whose edits are written to the chatter History
     * feed as "field: old → new" (see App\Services\Chatter\FieldChangeTracker).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const CHATTER_TRACKED_FIELDS = [
        'customer_id' => ['Customer', 'customer'],
        'quotation_date' => ['Quotation date', 'date'],
        'valid_until' => ['Valid until', 'date'],
        'commitment_date' => ['Commitment date', 'date'],
        'delivery_date' => ['Delivery date', 'date'],
        'payment_term_id' => ['Payment term', 'payment_term'],
        'credit_terms' => ['Credit terms', 'text'],
        'salesperson' => ['Salesperson', 'text'],
        'discount_type' => ['Discount type', 'text'],
        'discount_value' => ['Discount value', 'decimal'],
        'discount_amount' => ['Discount', 'money'],
    ];

    /**
     * Issue #634: line fields whose old → new values are shown in the chatter
     * History feed (grouped per line). Keys match the audit line snapshot.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const CHATTER_TRACKED_LINE_FIELDS = [
        'description' => ['description', 'text'],
        'quantity' => ['qty', 'quantity'],
        'unit_price' => ['price', 'money'],
        'discount_amount' => ['discount', 'money'],
        'tax_rate' => ['tax', 'percent'],
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($quotation) {
            if (! $quotation->quotation_number) {
                $quotation->quotation_number = static::generateQuotationNumber();
            }
            if (! $quotation->quotation_date) {
                $quotation->quotation_date = now()->toDateString();
            }
            // MRD pricelist-multicurrency FR-5.1/5.2: currency + FX snapshot.
            // The creation services stamp this deliberately (customer price
            // list currency → entity base); the hook only guarantees the
            // columns are never NULL for rows created outside them.
            if (! $quotation->currency_code) {
                $quotation->currency_code = \App\Services\DocumentCurrencyResolver::resolve();
            }
            if (! $quotation->base_currency_code) {
                $quotation->base_currency_code = \App\Services\DocumentCurrencyResolver::baseFor(null, $quotation->customer_id);
            }
            if ($quotation->exchange_rate === null
                && $quotation->currency_code
                && strtoupper($quotation->currency_code) === strtoupper($quotation->base_currency_code)) {
                $quotation->exchange_rate = 1.000000;
            }
        });
    }

    protected $fillable = [
        'quotation_number',
        'customer_id',
        'quotation_date',
        'valid_until',
        // Issue #408: commitment (promised) date + shipping policy.
        'commitment_date',
        'shipping_policy',
        'status',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'discount_type',
        'discount_value',
        'total',
        'currency_code',
        'exchange_rate',
        'base_currency_code',
        'notes',
        'terms',
        'customer_reference',
        'internal_reference',
        'credit_terms',
        // Issue #321: payment term from the payment-terms master.
        'payment_term_id',
        'attention_to',
        'billing_contact_id',
        'shipping_contact_id',
        'contact_email',
        'contact_phone',
        'salesperson',
        // Issue #553: default sales team stamped from the user's Sales
        // Settings default (free text alongside the free-text salesperson).
        'sales_team',
        'delivery_lead_time',
        'shipping_method',
        'shipping_terms',
        // Issue #333: Incoterm code + place (free text; no statutory list).
        'incoterm_code',
        'incoterm_place',
        'validity',
        'case_number',
        'date_of_case',
        'case_location',
        'patient_name',
        'patient_name_encrypted',
        'cost_center_id',
        'delivery_date',
        'pr_no',
        'contract_no',
        'plant_code',
        'company_code',
        'billing_address_snapshot',
        'shipping_address_snapshot',
        'sales_order_id',
        'created_by',
        // Issue #550: per-quotation acceptance requirements (stamped from
        // Sales Settings on create; NULL falls back to the tenant setting).
        'require_signature',
        'require_payment',
        'prepayment_percent',
        // Issue #338: the template the quotation was created from.
        'quotation_template_id',
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
        // Issue #334: public acceptance link + signer metadata.
        'acceptance_token',
        'acceptance_expires_at',
        'accepted_at',
        'signer_name',
        'signer_email',
        'signature_data',
        'acceptance_source',
    ];

    protected $casts = [
        'patient_name_encrypted' => \App\Casts\EncryptedOrLegacyString::class,
        'quotation_date' => 'date',
        'valid_until' => 'date',
        // Issue #408
        'commitment_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'discount_value' => 'decimal:4',
        'total' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'date_of_case' => 'date',
        'delivery_date' => 'date',
        'is_active_version' => 'boolean',
        'superseded_at' => 'datetime',
        // Issue #334: acceptance token expiry + acceptance timestamp.
        'acceptance_expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        // Issue #896: optimistic-concurrency token.
        'lock_version' => 'integer',
        // Issue #550
        'require_signature' => 'boolean',
        'require_payment' => 'boolean',
        'prepayment_percent' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuotationLine::class)
            ->orderBy('sequence')
            ->orderBy('id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\SalesOrder::class, 'sales_order_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'quotation_id');
    }

    public function deliveryOrders(): HasMany
    {
        return $this->hasMany(\Modules\Sales\Models\DeliveryOrder::class, 'quotation_id');
    }

    public function billingContact(): BelongsTo
    {
        return $this->belongsTo(\Modules\Contact\src\Models\CoreContact::class, 'billing_contact_id');
    }

    public function shippingContact(): BelongsTo
    {
        return $this->belongsTo(\Modules\Contact\src\Models\CoreContact::class, 'shipping_contact_id');
    }

    public static function generateQuotationNumber(): string
    {
        // #538 (ST1): collision recovery lives in the numbering service, which
        // advances the counter past issued numbers and never falls back to a
        // timestamp suffix.
        return app(\App\Services\DocumentNumberingService::class)->generateUnique(
            'quotation',
            'QUO-{YYYY}-{SEQ:4}',
            fn (string $prefix): array => static::withTrashed()
                ->where('quotation_number', 'like', $prefix.'%')
                ->pluck('quotation_number')
                ->all(),
        );
    }

    public function calculateTotals(): void
    {
        $subtotal = 0;
        $taxAmount = 0;
        $grossSubtotal = 0;

        // Recompute each line's tax_amount + line_total from base fields so the
        // header totals are always derived from accurate line data.
        foreach ($this->lines as $line) {
            $line->calculateLineTotals();
            if ($line->isDirty(['tax_amount', 'line_total'])) {
                $line->save();
            }
            // Issue #338: an optional line keeps its own figures (shown to
            // the customer) but adds nothing to the document's subtotal, tax
            // or total until the customer selects it.
            if ($line->is_optional) {
                continue;
            }
            $subtotal += (float) $line->line_total - (float) $line->tax_amount;
            $taxAmount += (float) $line->tax_amount;
            if (! in_array($line->line_type, ['note', 'section'], true)) {
                $grossSubtotal += (float) $line->quantity * (float) $line->unit_price;
            }
        }

        $this->subtotal = round($subtotal, 2);
        $discount = (float) ($this->discount_amount ?? 0);
        if ($this->discount_value !== null) {
            $discount = $this->discount_type === 'percentage'
                ? round($grossSubtotal * ((float) $this->discount_value / 100), 2)
                : round((float) $this->discount_value, 2);
        }
        $discount = min($discount, (float) $this->subtotal);
        $this->discount_amount = round($discount, 2);

        // Header discounts reduce the taxable amount. Distribute the document
        // discount proportionally across lines so mixed tax rates retain their
        // relative contribution without recalculating each persisted line.
        if ($discount > 0 && $this->subtotal > 0) {
            $taxableAmount = max($this->subtotal - $discount, 0);
            $taxRatio = $taxableAmount / $this->subtotal;
            $this->tax_amount = round($taxAmount * $taxRatio, 2);
        } else {
            $this->tax_amount = round($taxAmount, 2);
        }

        $this->total = round(max($this->subtotal - $discount, 0) + $this->tax_amount, 2);
        $this->save();
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isSent(): bool
    {
        return $this->status === 'sent';
    }

    /**
     * Issue #335 (Q6): a quotation whose validity date has passed without
     * acceptance. Confirmed (already converted) and cancelled quotations are
     * never "expired"; a null valid_until means no expiry.
     */
    public function isExpired(): bool
    {
        if ($this->valid_until === null) {
            return false;
        }

        if (in_array((string) $this->status, ['confirmed', 'cancelled'], true)) {
            return false;
        }

        return $this->valid_until->lt(now()->startOfDay());
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->isExpired();
    }

    // Issue #222: patient name is PHI — encrypted at rest, exposed as the
    // virtual patient_name attribute so existing callers keep working.
    //
    // Issue #408: expected_date (computed from lead times) and is_late (past
    // the commitment date on an open quotation) ride along for the lists.
    // Issue #335 (Q6): is_expired lets the list badge past-due quotations that
    // the daily job has not swept yet.
    protected $appends = ['patient_name', 'expected_date', 'is_late', 'is_expired'];

    protected $hidden = ['patient_name_encrypted'];

    /**
     * Issue #408: expected (scheduled) date computed from the product lead
     * times: quotation date + the longest product/supplier lead time. Null when
     * no line has lead-time data.
     */
    public function getExpectedDateAttribute(): ?string
    {
        $lines = $this->relationLoaded('lines') ? $this->lines : $this->lines()->get();

        $productIds = $lines
            ->reject(fn ($line) => in_array($line->line_type ?? 'product', ['note', 'section'], true))
            ->pluck('product_id');

        $baseDate = $this->quotation_date?->toDateString()
            ?? $this->created_at?->toDateString()
            ?? now()->toDateString();

        return app(\App\Services\SalesLeadTimeService::class)
            ->expectedDate($productIds, $baseDate)
            ?->toDateString();
    }

    /**
     * Issue #408: an open quotation whose commitment date has passed is late to
     * convert. Confirmed (already converted) and cancelled quotations are not
     * late.
     */
    public function isLate(): bool
    {
        if ($this->commitment_date === null) {
            return false;
        }

        if (in_array((string) $this->status, ['confirmed', 'cancelled'], true)) {
            return false;
        }

        return $this->commitment_date->isBefore(now()->startOfDay());
    }

    public function getIsLateAttribute(): bool
    {
        return $this->isLate();
    }

    /**
     * Issue #408: mirrors SalesOrder::requiresAllProductsReady() for the quote.
     */
    public function requiresAllProductsReady(): bool
    {
        return $this->shipping_policy === \Modules\Sales\Models\SalesOrder::SHIPPING_POLICY_WHEN_ALL_READY;
    }

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

    // ─── WorkflowEngine subject (#900, PC6) ───────────────────────

    public function workflowEntityType(): string
    {
        return 'quotation';
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
            'sales_order_id' => $this->sales_order_id,
            'total' => (float) $this->total,
        ];
    }

    public function applyWorkflowStatus(string $status, array $context = []): void
    {
        $this->forceFill(['status' => $status])->save();
    }
}

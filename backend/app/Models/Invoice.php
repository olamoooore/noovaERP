<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use \App\Models\Concerns\GuardsJournalEntryLinks;
    use \App\Models\Concerns\ResolvesLegalEntityContext;
    use HasFactory, SoftDeletes;

    // Approval dimension (writer: InvoiceApprovalService)
    public const APPROVAL_NOT_REQUIRED = 'not_required';

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_REJECTED = 'rejected';

    public const APPROVAL_EXPIRED = 'expired';

    public const APPROVAL_WITHDRAWN = 'withdrawn';

    // Delivery dimension (writer: InvoiceDeliveryService)
    public const DELIVERY_NOT_SENT = 'not_sent';

    public const DELIVERY_PARTIALLY_SENT = 'partially_sent';

    public const DELIVERY_SENT = 'sent';

    public const DELIVERY_FAILED = 'failed';

    // Evidence dimension (writer: InvoiceEvidenceService)
    public const EVIDENCE_NOT_REQUIRED = 'not_required';

    public const EVIDENCE_PENDING = 'pending';

    public const EVIDENCE_SATISFIED = 'satisfied';

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($invoice) {
            if (! $invoice->invoice_number) {
                $invoice->invoice_number = static::generateInvoiceNumber();
            }
            if (! $invoice->lifecycle_status) {
                $invoice->lifecycle_status = 'draft';
            }
            if (! $invoice->settlement_status) {
                $invoice->settlement_status = 'unpaid';
            }
            if (! $invoice->reconciliation_status) {
                $invoice->reconciliation_status = 'unreconciled';
            }
            if (! $invoice->version_number) {
                $invoice->version_number = 1;
            }
            if ($invoice->is_active_version === null) {
                $invoice->is_active_version = true;
            }
            // Issue #98: AR/AP documents are base-currency denominated unless an
            // explicit currency is supplied; keep the reporting views truthful.
            // MRD pricelist-multicurrency FR-5.1: the creation services stamp a
            // full snapshot (currency + rate + base); this hook guarantees no
            // row is ever created with the FX columns NULL.
            if (! $invoice->currency_code) {
                $invoice->currency_code = \App\Services\DocumentCurrencyResolver::resolve();
            }
            if (! $invoice->base_currency_code) {
                $invoice->base_currency_code = \App\Services\DocumentCurrencyResolver::baseFor(
                    $invoice->sales_order_id
                        ? \Modules\Sales\Models\SalesOrder::query()->whereKey($invoice->sales_order_id)->value('legal_entity_id')
                        : null,
                    $invoice->customer_id,
                );
            }
            if ($invoice->exchange_rate === null
                && $invoice->currency_code
                && $invoice->base_currency_code
                && strtoupper($invoice->currency_code) === strtoupper($invoice->base_currency_code)) {
                $invoice->exchange_rate = 1.000000;
            }
        });

        static::deleting(function ($invoice) {
            if ($invoice->journal_entry_id !== null) {
                throw new \RuntimeException(
                    "Cannot delete posted invoice #{$invoice->invoice_number}. ".
                    'Posted invoices must be reversed or credited, not deleted. '.
                    'Create a credit note to cancel this invoice.'
                );
            }

            if ($invoice->payments()->exists()) {
                $paymentCount = $invoice->payments()->count();
                throw new \RuntimeException(
                    "Cannot delete invoice #{$invoice->invoice_number} because {$paymentCount} payment(s) exist. ".
                    'Posted invoices must be reversed or credited, not deleted.'
                );
            }

            // Issue #47 (HR47-01): a valid JournalEntryLink to a live ledger
            // posting blocks the delete (previously the link survived and the
            // invoice was deleted anyway). Orphaned links are cleaned up.
            $invoice->guardJournalEntryLinksOnDelete("invoice #{$invoice->invoice_number}");
        });

        // ADR-005 §15.5 — date integrity between operational invoice and its
        // posted journal entry. If a posted invoice's invoice_date changes
        // (e.g. correction via direct DB write, an older controller revision,
        // or a future bulk update), the underlying JournalEntry.entry_date
        // MUST move with it — otherwise the JE keeps reporting the sale/AR
        // hit in the original period and the P&L is wrong.
        //
        // Triggered on EVERY save (Eloquent firing order: saving → updating →
        // updated → saved → saved (after commit)). We run the JE sync at
        // 'updated' so the change happens in the same DB transaction as the
        // invoice update, guaranteeing atomicity (no half-synced state).
        static::updated(function ($invoice) {
            // Only act when an active posted JE is linked, and only when the
            // date actually changed (avoid pointless writes on every update).
            if (! $invoice->journal_entry_id
                || ! $invoice->wasChanged('invoice_date')
                || ! $invoice->getOriginal('invoice_date')
            ) {
                return;
            }

            // Skip draft invoices (no posted JE exists yet).
            $invoice->refresh();
            $isPostedStatus = in_array($invoice->status, ['posted', 'sent', 'partial', 'overdue'], true);
            if (! $isPostedStatus) {
                return;
            }

            $newDate = $invoice->invoice_date instanceof \Carbon\Carbon
                ? $invoice->invoice_date->toDateString()
                : (string) $invoice->invoice_date;

            $je = \App\Models\JournalEntry::find($invoice->journal_entry_id);
            if (! $je) {
                return;
            }

            $currentJeDate = $je->entry_date instanceof \Carbon\Carbon
                ? $je->entry_date->toDateString()
                : (string) $je->entry_date;

            if ($currentJeDate === $newDate) {
                return;
            }

            // Bypass the prevent_journal_entry_deletion trigger — it only
            // blocks DELETE, not UPDATE, but on Postgres the trigger fires
            // before any UPDATE check; defensive ensure the UPDATE is allowed.
            try {
                \Illuminate\Support\Facades\DB::table('journal_entries')
                    ->where('id', $je->id)
                    ->update(['entry_date' => $newDate]);
            } catch (\Throwable $e) {
                // Re-raise so the caller sees the failure rather than silently
                // leaving invoice/JE out of sync (the exact bug we're preventing).
                throw new \RuntimeException(
                    "Failed to sync JournalEntry #{$je->id} entry_date with invoice #{$invoice->invoice_number} ".
                    "({$currentJeDate} → {$newDate}): ".$e->getMessage(),
                    0,
                    $e
                );
            }
        });
    }

    protected $fillable = [
        'invoice_number',
        'customer_id',
        'invoice_date',
        'due_date',
        'case_number',
        'case_information',
        'pr_no', 'contract_no', 'buyer_contact_id', 'plant_code', 'company_code',
        'date_of_case',
        'case_location',
        'patient_name',
        'patient_name_encrypted',
        'cost_center_id',
        'delivery_date',
        'customer_reference',
        'po_number',
        'doctor_contact_id',
        'requestor_contact_id',
        'billing_contact_id',
        'shipping_contact_id',
        'internal_reference',
        'attention_to',
        'contact_email',
        'contact_phone',
        'billing_address_snapshot',
        'delivery_address_snapshot',
        'payment_terms_snapshot',
        'company_registration_number',
        'tax_registration_number',
        'tax_exemption_code',
        'tax_exemption_reason',
        'tax_jurisdiction',
        'tax_regime',
        'tax_treatment',
        'status',
        'requires_approval',
        'approval_status',
        'approval_request_id',
        'delivery_status',
        'evidence_status',
        'payment_link_url',
        'payment_link_expires_at',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'shipping_cost',
        'shipping_method',
        'total',
        'paid_amount',
        'balance',
        'credited_amount',
        'notes',
        'terms',
        'journal_entry_id',
        'pos_order_id',
        'web_order_id',
        'sales_order_id',
        'sent_at',
        'created_by',
        'origin_type',
        'origin_reference_type',
        'origin_reference_id',
        'origin_context',
        'created_via_module',
        'created_via_role',
        'quotation_id',
        'lifecycle_status',
        'settlement_status',
        'reconciliation_status',
        'correction_group_id',
        'original_document_id',
        'replacement_document_id',
        'correction_type',
        'correction_reason_code',
        'correction_reason_text',
        'voided_at',
        'reversed_at',
        'credited_at',
        'superseded_at',
        'reversal_journal_entry_id',
        'credit_note_id',
        'version_number',
        'is_active_version',
        'currency_code',
        'exchange_rate',
        'base_currency_code',
        // Issue #875: Sales documents carry their legal entity + book.
        'legal_entity_id',
        'entity_book_id',
    ];

    protected $casts = [
        'patient_name_encrypted' => \App\Casts\EncryptedOrLegacyString::class,
        'invoice_date' => 'date',
        'exchange_rate' => 'decimal:6',
        'due_date' => 'date',
        'date_of_case' => 'date',
        'delivery_date' => 'date',
        'sent_at' => 'datetime',
        'requires_approval' => 'boolean',
        'payment_link_expires_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'shipping_method',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'credited_amount' => 'decimal:2',
        'origin_context' => 'array',
        'voided_at' => 'datetime',
        'reversed_at' => 'datetime',
        'credited_at' => 'datetime',
        'superseded_at' => 'datetime',
        'is_active_version' => 'boolean',
        'case_information' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)
            ->orderBy('sequence')
            ->orderBy('id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Get the journal entry links for this invoice (source_document_type = 'invoice')
     */
    public function journalEntryLinks(): HasMany
    {
        return $this->hasMany(JournalEntryLink::class, 'source_document_id')
            ->where('source_document_type', 'invoice');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Credit notes issued against this invoice (issue #169). Each posted credit
     * note carries its own journal entry, independent of this invoice's JE.
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CustomerCreditNote::class, 'invoice_id');
    }

    public function approvalRequest(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'approval_request_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(InvoiceDelivery::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(InvoiceEvidence::class);
    }

    public function activityLog(): HasMany
    {
        return $this->hasMany(InvoiceActivityLog::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function posOrder(): BelongsTo
    {
        return $this->belongsTo(\Modules\POS\Models\PosOrder::class, 'pos_order_id');
    }

    public function webOrder(): BelongsTo
    {
        return $this->belongsTo(\Modules\Ecommerce\Models\WebOrder::class, 'web_order_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(\Modules\Sales\Models\SalesOrder::class, 'sales_order_id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function deliveryOrders(): BelongsToMany
    {
        return $this->belongsToMany(
            \Modules\Sales\Models\DeliveryOrder::class,
            'invoice_delivery_orders',
            'invoice_id',
            'delivery_order_id',
        )->withTimestamps();
    }

    /**
     * Issue #153: surface the linked delivery order's signed proof on the
     * Invoice API response so client AP/payment processing can verify the
     * goods were actually received and signed for. Returns the first DO with
     * a signed document (a DO can have multiple proofs; the most recent wins).
     */
    public function proofOfDelivery(): ?array
    {
        $do = $this->deliveryOrders()
            ->whereNotNull('signed_document_path')
            ->orderByDesc('signed_at')
            ->orderByDesc('signed_document_uploaded_at')
            ->first();

        if (! $do) {
            return null;
        }

        return [
            'delivery_order_id' => $do->id,
            'delivery_order_number' => $do->delivery_order_number,
            'confirmation_method' => $do->confirmation_method,
            'confirmed_via_channel' => $do->confirmed_via_channel,
            'signed_by_name' => $do->signed_by_name,
            'signed_by_email' => $do->signed_by_email,
            'signed_at' => optional($do->signed_at)->toIso8601String(),
            'signed_document_uploaded_at' => optional($do->signed_document_uploaded_at)->toIso8601String(),
            'signed_document_path' => $do->signed_document_path,
            'signed_document_url' => route('sales.delivery-orders.signed-document.download', [
                'id' => $do->id,
            ], false),
        ];
    }

    public function medicalCases(): HasMany
    {
        return $this->hasMany(MedicalCase::class);
    }

    public function doctor()
    {
        return $this->belongsTo(\Modules\Contact\src\Models\CoreContact::class, 'doctor_contact_id');
    }

    public function requestor()
    {
        return $this->belongsTo(\Modules\Contact\src\Models\CoreContact::class, 'requestor_contact_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(\Modules\Contact\src\Models\CoreContact::class, 'buyer_contact_id');
    }

    public function billingContact()
    {
        return $this->belongsTo(\Modules\Contact\src\Models\CoreContact::class, 'billing_contact_id');
    }

    public function shippingContact()
    {
        return $this->belongsTo(\Modules\Contact\src\Models\CoreContact::class, 'shipping_contact_id');
    }

    public static function generateInvoiceNumber(): string
    {
        $maxAttempts = 10;
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $number = static::generateInvoiceNumberRaw();
            if (! static::withTrashed()->where('invoice_number', $number)->exists()) {
                return $number;
            }
            \Illuminate\Support\Facades\Log::warning('Invoice number collision, retrying', [
                'number' => $number,
                'attempt' => $attempt + 1,
            ]);
        }

        // Final fallback: use timestamp-based suffix
        return 'INV-'.date('Y').'-'.date('is');
    }

    private static function generateInvoiceNumberRaw(): string
    {
        try {
            return app(\App\Services\DocumentNumberingService::class)
                ->generate('invoice', 'INV-{YYYY}-{SEQ:4}');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('DocumentNumberingService failed, using fallback', [
                'error' => $e->getMessage(),
            ]);
        }

        $year = date('Y');
        $prefix = "INV-{$year}-";
        $latest = static::withTrashed()
            ->where('invoice_number', 'like', $prefix.'%')
            ->orderBy('id', 'desc')
            ->first();
        $number = $latest ? (int) substr($latest->invoice_number, -4) + 1 : 1;

        return $prefix.str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    public function calculateTotals(): void
    {
        $this->subtotal = $this->lines->sum(function ($line) {
            return $line->line_total - $line->tax_amount;
        });
        $lineTaxSum = $this->lines->sum('tax_amount');
        $discount = $this->discount_amount ?? 0;

        if ($discount > 0 && $this->subtotal > 0) {
            $taxableAmount = max($this->subtotal - $discount, 0);
            $taxRatio = $this->subtotal > 0 ? $taxableAmount / $this->subtotal : 0;
            $this->tax_amount = round($lineTaxSum * $taxRatio, 2);
        } else {
            $this->tax_amount = $lineTaxSum;
        }

        $this->total = $this->subtotal - $discount + $this->tax_amount;
        $this->balance = $this->total - $this->paid_amount;
        $this->save();
    }

    public function scopeActiveVersion($query)
    {
        return $query->where('is_active_version', true);
    }

    public function scopePosted($query)
    {
        return $query->where('lifecycle_status', 'posted');
    }

    public function scopeNotSuperseded($query)
    {
        return $query->whereNotIn('lifecycle_status', ['superseded', 'voided', 'reversed']);
    }

    public function scopeNotVoided($query)
    {
        return $query->whereNotIn('lifecycle_status', ['voided', 'reversed']);
    }

    // Issue #222: patient name is PHI — encrypted at rest, exposed as the
    // virtual patient_name attribute so existing callers keep working.
    protected $appends = ['patient_name'];

    protected $hidden = ['patient_name_encrypted'];

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
}

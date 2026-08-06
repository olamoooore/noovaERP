<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

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
        });
    }

    protected $fillable = [
        'invoice_number',
        'customer_id',
        'invoice_date',
        'due_date',
        'case_number',
        'date_of_case',
        'case_location',
        'patient_name',
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
        'status',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'shipping_cost',
        'shipping_method',
        'total',
        'paid_amount',
        'balance',
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
    ];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'date_of_case' => 'date',
        'delivery_date' => 'date',
        'sent_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'shipping_method',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'origin_context' => 'array',
        'voided_at' => 'datetime',
        'reversed_at' => 'datetime',
        'credited_at' => 'datetime',
        'superseded_at' => 'datetime',
        'is_active_version' => 'boolean',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
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

    public function deliveryOrders(): HasMany
    {
        return $this->hasMany(\Modules\Sales\Models\DeliveryOrder::class, 'invoice_id');
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
        // subtotal = sum of net amounts (line_total already includes tax, so subtract tax)
        $this->subtotal = $this->lines->sum(function ($line) {
            return $line->line_total - $line->tax_amount;
        });
        $this->tax_amount = $this->lines->sum('tax_amount');
        $this->total = $this->subtotal + $this->tax_amount - ($this->discount_amount ?? 0);
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
}

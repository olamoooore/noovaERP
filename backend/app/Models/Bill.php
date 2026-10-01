<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Inventory\Models\PurchaseOrder;

class Bill extends Model
{
    use \App\Models\Concerns\GuardsJournalEntryLinks;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'bill_number',
        'vendor_id',
        'purchase_order_id',
        'goods_receipt_id',
        'bill_date',
        'due_date',
        'vendor_reference',
        'internal_reference',
        'attention_to',
        'contact_email',
        'contact_phone',
        'vendor_address_snapshot',
        'remittance_address_snapshot',
        'company_registration_number',
        'tax_registration_number',
        'status',
        'bill_type',
        'subtotal',
        'tax_amount',
        'discount_amount',
        'total',
        'paid_amount',
        'balance',
        'notes',
        'terms',
        'journal_entry_id',
        'received_at',
        'created_by',
        'origin_type',
        'origin_reference_type',
        'origin_reference_id',
        'origin_context',
        'created_via_module',
        'created_via_role',
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
        'approved_by',
        'approved_at',
        'currency_code',
        'exchange_rate',
        'base_currency_code',
    ];

    protected $casts = [
        'exchange_rate' => 'decimal:6',
        'bill_date' => 'date',
        'due_date' => 'date',
        'received_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'origin_context' => 'array',
        'voided_at' => 'datetime',
        'reversed_at' => 'datetime',
        'credited_at' => 'datetime',
        'superseded_at' => 'datetime',
        'is_active_version' => 'boolean',
        'approved_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($bill) {
            if (! $bill->bill_number) {
                $bill->bill_number = static::generateBillNumber();
            }
            if (! $bill->lifecycle_status) {
                $bill->lifecycle_status = 'draft';
            }
            if (! $bill->settlement_status) {
                $bill->settlement_status = 'unpaid';
            }
            if (! $bill->reconciliation_status) {
                $bill->reconciliation_status = 'unreconciled';
            }
            if (! $bill->version_number) {
                $bill->version_number = 1;
            }
            // Issue #98: AP documents are base-currency denominated unless an
            // explicit currency is supplied; keep the reporting views truthful.
            if (! $bill->currency_code) {
                $bill->currency_code = \App\Services\DocumentCurrencyResolver::resolve();
            }
            if ($bill->is_active_version === null) {
                $bill->is_active_version = true;
            }
        });

        // Workstream 2 (2026-04-15): Prevent deletion of posted bills
        static::deleting(function ($bill) {
            if ($bill->journal_entry_id !== null) {
                throw new \RuntimeException(
                    "Cannot delete posted bill #{$bill->bill_number}. ".
                    'Posted bills must be reversed or credited, not deleted. '.
                    'Create a vendor credit note to cancel this bill.'
                );
            }

            // Prevent deletion if payments exist
            if ($bill->payments()->exists()) {
                $paymentCount = $bill->payments()->count();
                throw new \RuntimeException(
                    "Cannot delete bill #{$bill->bill_number} because {$paymentCount} payment(s) exist. ".
                    'Posted bills must be reversed or credited, not deleted.'
                );
            }

            // Issue #47 (HR47-01): a valid JournalEntryLink to a live ledger
            // posting blocks the delete; orphaned links are cleaned up.
            $bill->guardJournalEntryLinksOnDelete("bill #{$bill->bill_number}");
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Get the journal entry links for this bill (source_document_type = 'bill')
     */
    public function journalEntryLinks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(JournalEntryLink::class, 'source_document_id')
            ->where('source_document_type', 'bill');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Is this an expense-style bill (non-PO, non-stock, free-text lines)?
     */
    public function isExpense(): bool
    {
        return $this->bill_type === 'expense';
    }

    /**
     * Is this a procurement bill (PO/GRN-backed, three-way match enforced)?
     */
    public function isProcurement(): bool
    {
        return $this->bill_type === 'procurement';
    }

    public static function generateBillNumber(): string
    {
        $latest = static::withTrashed()->orderBy('id', 'desc')->first();
        $number = $latest ? (int) substr($latest->bill_number, -4) + 1 : 1;

        return 'BILL-'.date('Y').'-'.str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    public function calculateTotals(): void
    {
        $this->subtotal = $this->lines->sum('line_total');
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
}

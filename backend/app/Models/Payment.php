<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'payment_number',
        'customer_id',
        'vendor_id',
        'invoice_id',
        'bill_id',
        'payment_date',
        'amount',
        'discount_amount',
        'method',
        'reference',
        'notes',
        'bank_account_id',
        'journal_entry_id',
        'received_by',
        'tenant_id',
        'payment_status',
        'currency',
        'original_payment_id',
        'refund_payment_id',
        'correction_group_id',
        'correction_type',
        'correction_reason_code',
        'correction_reason_text',
        'unapplied_at',
        'refunded_at',
        'reversal_journal_entry_id',
    ];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'unapplied_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($payment) {
            if (! $payment->payment_number) {
                $payment->payment_number = static::generatePaymentNumber();
            }
            if (! $payment->payment_status) {
                $payment->payment_status = 'recorded';
            }
            // Issue #98: payments are base-currency denominated unless an
            // explicit currency is supplied; keep the reporting views truthful.
            if (! $payment->currency) {
                $payment->currency = \App\Services\DocumentCurrencyResolver::resolve();
            }
        });

        // Workstream 2 (2026-04-15): Prevent deletion of posted payments
        static::deleting(function ($payment) {
            if ($payment->journal_entry_id !== null) {
                throw new \RuntimeException(
                    "Cannot delete posted payment #{$payment->payment_number}. ".
                    'Posted payments must be reversed, not deleted. '.
                    'Use the refund endpoint to reverse this payment.'
                );
            }

            // Safety net: clean up any orphaned journal entry links for this payment.
            // Issue #47: include soft-deleted JournalEntries (withTrashed) so the
            // link is not destroyed when its linked JE was soft-deleted — soft
            // deletion still counts as a present, authoritative traceability row.
            $payment->journalEntryLinks()->each(function ($link) {
                if ($link->journal_entry_id
                    && ! \App\Models\JournalEntry::withTrashed()->whereKey($link->journal_entry_id)->exists()
                ) {
                    $link->delete();
                }
            });
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Get the journal entry links for this payment (source_document_type = 'payment')
     */
    public function journalEntryLinks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(JournalEntryLink::class, 'source_document_id')
            ->where('source_document_type', 'payment');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Draft payments can be freely edited and deleted.
     * Once a payment has posted to the ledger (journal_entry_id set) it is locked.
     */
    public function isEditable(): bool
    {
        return $this->payment_status === 'draft' && $this->journal_entry_id === null;
    }

    public function isDeletable(): bool
    {
        return $this->isEditable();
    }

    public function hasAccountingImpact(): bool
    {
        return $this->journal_entry_id !== null;
    }

    public function scopeDraft($query)
    {
        return $query->where('payment_status', 'draft')->whereNull('journal_entry_id');
    }

    public function scopePosted($query)
    {
        return $query->where('payment_status', 'posted')->whereNotNull('journal_entry_id');
    }

    public function scopeActive($query)
    {
        return $query->whereNotIn('payment_status', ['cancelled', 'reversed', 'refunded']);
    }

    public function scopeForInvoice($query, int $invoiceId)
    {
        return $query->where('invoice_id', $invoiceId);
    }

    public static function generatePaymentNumber(): string
    {
        try {
            return app(\App\Services\DocumentNumberingService::class)
                ->generate('payment', 'PAY/{YYYY}/{MM}/{SEQ:3}');
        } catch (\Throwable $e) {
            // Fallback if DocumentNumberingService is unavailable
        }

        $year = date('Y');
        $month = date('m');
        $prefix = "PAY/{$year}/{$month}/";
        $latest = static::where('payment_number', 'like', $prefix.'%')
            ->orderByDesc('payment_number')
            ->first();
        $number = $latest ? (int) substr($latest->payment_number, -3) + 1 : 1;

        return $prefix.str_pad($number, 3, '0', STR_PAD_LEFT);
    }
}

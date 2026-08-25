<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BillPayment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'payment_number',
        'vendor_id',
        'bill_id',
        'payment_date',
        'amount',
        'payment_method',
        'bank_account_id',
        'reference',
        'memo',
        'journal_entry_id',
        'created_by',
        'tenant_id',
        'payment_status',
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

        // Workstream 2 (2026-04-15): Prevent deletion of posted bill payments
        static::deleting(function ($payment) {
            if ($payment->journal_entry_id !== null) {
                throw new \RuntimeException(
                    "Cannot delete posted bill payment #{$payment->payment_number}. ".
                    'Posted payments must be reversed, not deleted. '.
                    'Create a reversing entry to cancel this payment.'
                );
            }

            // Safety net: clean up any orphaned journal entry links for this bill payment.
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

    public function vendor()
    {
        return $this->belongsTo(Vendor::class);
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Get the journal entry links for this bill payment (source_document_type = 'bill_payment')
     */
    public function journalEntryLinks(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(JournalEntryLink::class, 'source_document_id')
            ->where('source_document_type', 'bill_payment');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Generate next payment number
     */
    public static function generatePaymentNumber(): string
    {
        $year = date('Y');
        $lastPayment = self::whereYear('created_at', $year)
            ->orderBy('id', 'desc')
            ->first();

        if ($lastPayment && preg_match('/BP-(\d{4})-(\d+)/', $lastPayment->payment_number, $matches)) {
            $nextNumber = intval($matches[2]) + 1;
        } else {
            $nextNumber = 1;
        }

        return sprintf('BP-%s-%04d', $year, $nextNumber);
    }
}

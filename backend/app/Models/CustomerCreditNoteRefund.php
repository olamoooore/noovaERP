<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #528: a refund of a posted credit note's remaining credit, paid out
 * to the customer as an outbound payment (Dr AR / Cr Bank through the
 * PostingEngine). Voided (never deleted) by reversing that journal, which
 * restores the credit.
 */
class CustomerCreditNoteRefund extends Model
{
    protected $table = 'customer_credit_note_refunds';

    protected $fillable = [
        'credit_note_id',
        'payment_id',
        'amount',
        'currency_code',
        'bank_account_id',
        'journal_entry_id',
        'voided_at',
        'voided_by',
        'void_reason',
        'reversal_journal_entry_id',
        'reversal_payment_id',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'voided_at' => 'datetime',
    ];

    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CustomerCreditNote::class, 'credit_note_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}

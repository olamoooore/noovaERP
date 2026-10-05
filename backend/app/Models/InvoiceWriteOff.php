<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #879 (SA6): one bad-debt write-off event against an invoice.
 * `recovered_amount` tracks how much later payment allocations released;
 * recovery credits the same account the write-off debited.
 */
class InvoiceWriteOff extends Model
{
    protected $fillable = [
        'invoice_id',
        'amount',
        'recovered_amount',
        'reason',
        'account_id',
        'journal_entry_id',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'recovered_amount' => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * The portion of this write-off not yet recovered.
     */
    public function openAmount(): float
    {
        return round((float) $this->amount - (float) $this->recovered_amount, 2);
    }
}

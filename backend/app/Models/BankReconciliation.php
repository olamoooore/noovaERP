<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankReconciliation extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_account_id',
        'statement_date',
        'statement_balance',
        'opening_balance',
        'closing_balance',
        'difference',
        'status',
        'reconciled_by',
        'reconciled_at',
    ];

    protected $casts = [
        'statement_date' => 'date',
        'statement_balance' => 'decimal:2',
        'opening_balance' => 'decimal:2',
        'closing_balance' => 'decimal:2',
        'difference' => 'decimal:2',
        'reconciled_at' => 'datetime',
    ];

    /**
     * Get the bank account for this reconciliation
     */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    /**
     * Get the user who reconciled this
     */
    public function reconciledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    /**
     * Get the reconciliation items
     */
    public function items(): HasMany
    {
        return $this->hasMany(ReconciliationItem::class, 'reconciliation_id');
    }

    /**
     * Get the reconciled journal entry lines
     */
    public function journalEntryLines()
    {
        return $this->hasManyThrough(
            JournalEntryLine::class,
            ReconciliationItem::class,
            'reconciliation_id',
            'id',
            'id',
            'journal_entry_line_id'
        );
    }

    /**
     * Check if reconciliation is balanced
     */
    public function isBalanced(): bool
    {
        return abs($this->difference) < 0.01; // Allow for floating point precision
    }

    /**
     * Mark reconciliation as completed
     */
    public function complete(?int $userId): void
    {
        $this->update([
            'status' => 'completed',
            'reconciled_by' => $userId,
            'reconciled_at' => now(),
        ]);
    }
}

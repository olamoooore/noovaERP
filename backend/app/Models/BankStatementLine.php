<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatementLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_statement_id',
        'bank_account_id',
        'transaction_date',
        'description',
        'reference',
        'debit',
        'credit',
        'amount',
        'currency_code',
        'status',
        'matched_journal_entry_line_id',
        'matched_at',
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'amount' => 'decimal:2',
        'matched_at' => 'datetime',
    ];

    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function matchedJournalEntryLine(): BelongsTo
    {
        return $this->belongsTo(JournalEntryLine::class, 'matched_journal_entry_line_id');
    }

    public function matchCandidates(): HasMany
    {
        return $this->hasMany(MatchCandidate::class);
    }

    public function getNetAmountAttribute(): float
    {
        return (float) $this->debit - (float) $this->credit;
    }
}

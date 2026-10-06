<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Journal - Configuration for journal types
 * Manages different types of journals (Sales, Purchases, Cash, Bank, General)
 */
class Journal extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'type',
        'default_debit_account_id',
        'default_credit_account_id',
        'sequence_prefix',
        'sequence_number',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sequence_number' => 'integer',
    ];

    /**
     * Get the default debit account
     */
    public function defaultDebitAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'default_debit_account_id');
    }

    /**
     * Get the default credit account
     */
    public function defaultCreditAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'default_credit_account_id');
    }

    /**
     * Get all journal entries for this journal
     */
    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    /**
     * Generate the next entry number for this journal.
     *
     * Issue #989: the number is claimed atomically in the database. A single
     * UPDATE ... RETURNING takes the journal row lock before the value is
     * read, so two concurrent callers can never claim the same number; the
     * in-memory sequence_number of a model loaded earlier is never used
     * (the previous read-then-increment let 8 concurrent requests collide on
     * journal_entries.entry_number and fail with a unique-key error).
     */
    public function getNextEntryNumber(): string
    {
        $row = DB::selectOne(
            'UPDATE journals SET sequence_number = sequence_number + 1, updated_at = NOW() WHERE id = ? RETURNING sequence_number - 1 AS claimed',
            [$this->id]
        );

        if ($row === null) {
            throw new \RuntimeException("Journal #{$this->id} no longer exists; cannot allocate an entry number.");
        }

        $number = (int) $row->claimed;

        // Keep this instance consistent for callers that reuse the model.
        $this->setAttribute('sequence_number', $number + 1);

        return $this->sequence_prefix.'-'.str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Scope to get only active journals
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}

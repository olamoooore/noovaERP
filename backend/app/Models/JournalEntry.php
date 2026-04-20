<?php

namespace App\Models;

use App\Services\LockDateValidationService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * Journal Entry - Immutable once posted
 * Enforces debit = credit rule
 */
class JournalEntry extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'journal_id',
        'entry_number',
        'entry_date',
        'type',
        'reference',
        'description',
        'status',
        'posted_at',
        'posted_by',
        'created_by',
        'reversed_entry_id',
        'is_reversal',
        'legal_entity_id',
        'entity_book_id',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'posted_at' => 'datetime',
        'is_reversal' => 'boolean',
    ];

    protected $appends = ['is_balanced'];

    /**
     * Boot the model.
     * 
     * Workstream 2 (2026-04-15): Prevent deletion of posted entries.
     * Posted financial records must be reversed, not deleted.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::deleting(function ($entry) {
            // Prevent deletion of posted entries
            if ($entry->status === 'posted' && $entry->posted_at !== null) {
                throw new \RuntimeException(
                    "Cannot delete posted journal entry #{$entry->entry_number}. " .
                    "Posted entries must be reversed, not deleted. " .
                    "Use the reversal endpoint to create a reversing entry."
                );
            }

            // Prevent deletion of entries that have been reconciled
            if ($entry->lines()->whereNotNull('reconciliation_id')->exists()) {
                throw new \RuntimeException(
                    "Cannot delete journal entry #{$entry->entry_number} because one or more lines have been reconciled."
                );
            }
        });
    }

    /**
     * Get the journal
     */
    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    /**
     * Get the lines
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /**
     * Get the user who posted this entry
     */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * Get the user who created this entry
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the reversed entry (if this is a reversal)
     */
    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversed_entry_id');
    }

    /**
     * Get the reversal entry (if this entry was reversed)
     */
    public function reversalEntry(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'reversed_entry_id');
    }

    /**
     * Get total debit
     */
    public function getTotalDebit(): float
    {
        return (float) $this->lines()->sum('debit');
    }

    /**
     * Get total credit
     */
    public function getTotalCredit(): float
    {
        return (float) $this->lines()->sum('credit');
    }

    /**
     * Check if entry is balanced
     */
    public function getIsBalancedAttribute(): bool
    {
        return abs($this->getTotalDebit() - $this->getTotalCredit()) < 0.01;
    }

    /**
     * Post the journal entry
     * 
     * @deprecated Use PostingEngine::post() instead. This method does not create JournalEntryLink or PostingAuditLog.
     *             PostingEngine provides idempotency, audit trail, and proper transaction handling.
     * @throws \BadMethodCallException Always throws since this method is deprecated
     */
    public function post(): void
    {
        throw new \BadMethodCallException(
            'JournalEntry::post() is deprecated. Use PostingEngine::post() instead. ' .
            'This method does not create JournalEntryLink or PostingAuditLog which are required for audit compliance.'
        );
    }

    /**
     * Reverse the journal entry.
     *
     * @deprecated Use PostingEngine::reverse() instead. This method does not create
     *              JournalEntryLink or PostingAuditLog which are required for audit compliance.
     * @throws \BadMethodCallException Always throws since this method is deprecated
     */
    public function reverse(?string $reason = null, ?string $reversalDate = null): self
    {
        throw new \BadMethodCallException(
            'JournalEntry::reverse() is deprecated. Use PostingEngine::reverse() instead. ' .
            'This method does not create JournalEntryLink or PostingAuditLog which are required for audit compliance.'
        );
    }

    /**
     * Generate a fallback entry number for entries without a journal.
     *
     * Uses PostgreSQL advisory lock to prevent TOCTOU race conditions when
     * multiple requests generate entry numbers concurrently.
     *
     * Made public static so PostingEngine and other callers can share the
     * same sequence logic instead of duplicating it.
     *
     * Hardening (2026-04-15): Added pg_advisory_xact_lock to serialize
     * concurrent entry number generation within the current transaction.
     */
    public static function generateEntryNumber(): string
    {
        $prefix = 'JE-' . date('Y') . '-';

        // Advisory lock key derived from a fixed namespace. The lock is released
        // automatically when the enclosing transaction commits/rolls back.
        // If there is no outer transaction, use a non-transactional advisory lock.
        $lockKey = crc32('journal_entry_number_gen');
        \Illuminate\Support\Facades\DB::statement("SELECT pg_advisory_xact_lock(?)", [$lockKey]);

        $latest = static::where('entry_number', 'like', $prefix . '%')
            ->orderByRaw("CAST(SUBSTRING(entry_number FROM '.{4}$') AS INTEGER) DESC")
            ->first();

        $number = $latest ? (int) substr($latest->entry_number, -4) + 1 : 1;
        return $prefix . str_pad($number, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Scope to get only posted entries
     */
    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }

    /**
     * Scope to get only draft entries
     */
    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    /**
     * Scope to filter by date range
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('entry_date', [$startDate, $endDate]);
    }

    /**
     * Scope to filter by journal
     */
    public function scopeByJournal($query, int $journalId)
    {
        return $query->where('journal_id', $journalId);
    }
}

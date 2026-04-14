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
     */
    public function post(): void
    {
        if ($this->status === 'posted') {
            throw new \Exception('Journal entry already posted');
        }

        if (!$this->is_balanced) {
            throw new \Exception('Journal entry must balance before posting');
        }

        // Route lock-date check through LockDateValidationService (uses consistent lte boundary).
        // LockDate::isDateLocked() used strict < which was inconsistent with the service.
        app(LockDateValidationService::class)->validateOrFail('journal_entries', $this->entry_date);

        $this->update([
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => TenantUserHelper::getTenantUserId(),
        ]);
    }

    /**
     * Reverse the journal entry.
     *
     * @param string|null $reason        Human-readable reason for the reversal
     * @param string|null $reversalDate  Effective date (Y-m-d). Defaults to today when null.
     *                                   Callers (e.g. PostingEngine) are responsible for
     *                                   validating this date against lock + fiscal controls
     *                                   BEFORE invoking this method.
     */
    public function reverse(?string $reason = null, ?string $reversalDate = null): self
    {
        if ($this->status !== 'posted') {
            throw new \Exception('Can only reverse posted entries');
        }

        if ($this->is_reversal) {
            throw new \Exception('Cannot reverse a reversal entry');
        }

        // Check if already reversed
        if ($this->reversalEntry()->exists()) {
            throw new \Exception('Entry has already been reversed');
        }

        $effectiveDate = $reversalDate ?? now()->toDateString();

        // Create reversal entry
        $reversalEntry = static::create([
            'journal_id'        => $this->journal_id,
            'entry_number'      => $this->journal ? $this->journal->getNextEntryNumber() : static::generateEntryNumber(),
            'entry_date'        => $effectiveDate,
            'type'              => $this->type,
            'reference'         => $this->reference,
            'description'       => 'REVERSAL: ' . ($reason ?? $this->description),
            'status'            => 'posted',
            'posted_at'         => now(),
            'posted_by'         => TenantUserHelper::getTenantUserId(),
            'created_by'        => TenantUserHelper::getTenantUserId(),
            'reversed_entry_id' => $this->id,
            'is_reversal'       => true,
            'legal_entity_id'   => $this->legal_entity_id,
            'entity_book_id'    => $this->entity_book_id,
        ]);

        // Create reversed lines
        foreach ($this->lines as $line) {
            $reversalEntry->lines()->create([
                'account_id' => $line->account_id,
                'debit' => $line->credit, // Swap debit and credit
                'credit' => $line->debit,
                'description' => 'REVERSAL: ' . $line->description,
                'reference' => $line->reference,
                'currency_code' => $line->currency_code,
                'original_amount' => $line->original_amount ? -$line->original_amount : null,
                'exchange_rate' => $line->exchange_rate,
                'department_id' => $line->department_id,
                'project_id' => $line->project_id,
                'location_id' => $line->location_id,
            ]);
        }

        return $reversalEntry;
    }

    /**
     * Generate a fallback entry number for entries without a journal.
     *
     * Made public static so PostingEngine and other callers can share the
     * same sequence logic instead of duplicating it.
     */
    public static function generateEntryNumber(): string
    {
        $prefix = 'JE-' . date('Y') . '-';

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

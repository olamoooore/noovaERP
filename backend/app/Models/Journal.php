<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Generate the next entry number for this journal
     */
    public function getNextEntryNumber(): string
    {
        $number = $this->sequence_number;
        $this->increment('sequence_number');

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

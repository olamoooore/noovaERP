<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Journal Entry Line - Individual line in a journal entry
 * Supports multi-currency and analytic dimensions
 */
class JournalEntryLine extends Model
{
    use HasFactory;

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'debit',
        'credit',
        'description',
        'reference',
        'reconciled',
        'reconciliation_id',
        'reconciliation_date',
        // Multi-currency fields
        'currency_code',
        'original_amount',
        'exchange_rate',
        // Analytic dimensions
        'department_id',
        'project_id',
        'location_id',
    ];

    protected $casts = [
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'reconciled' => 'boolean',
        'reconciliation_date' => 'date',
    ];

    /**
     * Get the journal entry
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Get the account
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    /**
     * Get the reconciliation
     */
    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(BankReconciliation::class, 'reconciliation_id');
    }

    /**
     * Get the currency
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * Get the department (analytic account)
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(AnalyticAccount::class, 'department_id');
    }

    /**
     * Get the project (analytic account)
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(AnalyticAccount::class, 'project_id');
    }

    /**
     * Get the location (analytic account)
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(AnalyticAccount::class, 'location_id');
    }

    /**
     * Convert amount to base currency
     */
    public function convertToBaseCurrency(string $baseCurrency = 'USD'): float
    {
        if (! $this->currency_code || $this->currency_code === $baseCurrency) {
            return (float) ($this->debit ?: $this->credit);
        }

        if ($this->exchange_rate) {
            return (float) $this->original_amount * (float) $this->exchange_rate;
        }

        // Fallback to exchange rate lookup
        try {
            $amount = (float) $this->original_amount;

            return ExchangeRate::convert($amount, $this->currency_code, $baseCurrency);
        } catch (\Exception $e) {
            return (float) ($this->debit ?: $this->credit);
        }
    }

    /**
     * Get the amount in original currency
     */
    public function getOriginalAmountAttribute($value): float
    {
        if ($value !== null) {
            return (float) $value;
        }

        // If no original amount, use debit or credit
        return (float) ($this->debit ?: $this->credit);
    }

    /**
     * Scope to filter by analytic dimension
     */
    public function scopeByDepartment($query, int $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }

    /**
     * Scope to filter by project
     */
    public function scopeByProject($query, int $projectId)
    {
        return $query->where('project_id', $projectId);
    }

    /**
     * Scope to filter by location
     */
    public function scopeByLocation($query, int $locationId)
    {
        return $query->where('location_id', $locationId);
    }

    /**
     * Scope to filter by currency
     */
    public function scopeByCurrency($query, string $currencyCode)
    {
        return $query->where('currency_code', $currencyCode);
    }
}

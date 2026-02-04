<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bank Account - Links bank accounts to GL accounts
 */
class BankAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_name',
        'account_number',
        'bank_name',
        'currency_code',
        'gl_account_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the currency
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    /**
     * Get the GL account
     */
    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'gl_account_id');
    }

    /**
     * Get all reconciliations for this bank account
     */
    public function reconciliations(): HasMany
    {
        return $this->hasMany(BankReconciliation::class);
    }

    /**
     * Get the current balance from GL account
     */
    public function getCurrentBalance(): float
    {
        return $this->glAccount ? $this->glAccount->getBalance() : 0;
    }

    /**
     * Get unreconciled transactions
     */
    public function getUnreconciledTransactions()
    {
        return JournalEntryLine::where('account_id', $this->gl_account_id)
            ->whereHas('journalEntry', fn($q) => $q->where('status', 'posted'))
            ->where(function ($q) {
                $q->whereNull('is_reconciled')
                    ->orWhere('is_reconciled', false);
            })
            ->with('journalEntry')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Scope to get only active bank accounts
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get bank accounts by currency
     */
    public function scopeByCurrency($query, string $currencyCode)
    {
        return $query->where('currency_code', $currencyCode);
    }
}

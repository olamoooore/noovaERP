<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Account balance projection — derived/rebuildable read optimization.
 * ADR-005 §11.6 — balance projections are derived and rebuildable from
 * authoritative LedgerEntry/LedgerEntryLine.
 */
class AccountBalanceProjection extends Model
{
    protected $table = 'account_balance_projections';

    protected $fillable = [
        'tenant_id',
        'entity_id',
        'accounting_book_id',
        'ledger_account_id',
        'period_id',
        'currency_basis',
        'dimensions',
        'ledger_checkpoint',
        'total_debit',
        'total_credit',
        'balance',
        'computed_at',
        'is_authoritative',
    ];

    protected $casts = [
        'dimensions' => 'array',
        'total_debit' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'balance' => 'decimal:2',
        'computed_at' => 'datetime',
        'is_authoritative' => 'boolean',
    ];

    // ── Scopes ────────────────────────────────────────

    public function scopeForAccount($query, int $accountId)
    {
        return $query->where('ledger_account_id', $accountId);
    }

    public function scopeForPeriod($query, string $periodId)
    {
        return $query->where('period_id', $periodId);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeAuthoritative($query)
    {
        return $query->where('is_authoritative', true);
    }

    // ── Helpers ─────────────────────────────────────────

    /**
     * Rebuild projection from authoritative ledger entries.
     * ADR-005 §11.6 — any balance cache must be derivable from authoritative entries.
     */
    public static function rebuild(
        int $accountId,
        ?string $periodId = null,
        ?string $tenantId = null,
    ): self {
        // In production, this would query LedgerEntry/LedgerEntryLine
        // For now, aggregate from journal_entries + journal_entry_lines
        $query = \App\Models\JournalEntryLine::query()
            ->where('account_id', $accountId)
            ->where('status', 'posted');

        if ($periodId) {
            $query->whereHas('journalEntry', function ($q) use ($periodId) {
                $q->where('journal_id', $periodId);
            });
        }

        $totalDebit = (float) $query->sum('debit');
        $totalCredit = (float) $query->sum('credit');

        $projection = static::updateOrCreate(
            [
                'tenant_id' => $tenantId ?? auth()->user()?->tenant_id,
                'ledger_account_id' => $accountId,
                'period_id' => $periodId,
            ],
            [
                'total_debit' => $totalDebit,
                'total_credit' => $totalCredit,
                'balance' => $totalDebit - $totalCredit,
                'computed_at' => now(),
                'is_authoritative' => true,
                'ledger_checkpoint' => now()->timestamp,
            ],
        );

        return $projection;
    }
}

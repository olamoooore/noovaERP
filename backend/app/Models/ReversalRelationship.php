<?php

namespace App\Models;

use App\Accounting\Enums\ReversalBasis;
use App\Accounting\Enums\ReversalMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable lineage record linking an original posting to its reversal.
 * ADR-005 §15.3.4 — ReversalRelationship minimum lineage record.
 *
 * Header-level reversed/remaining amounts are summary values only.
 * Partial reversal eligibility is tracked at the component level.
 */
class ReversalRelationship extends Model
{
    protected $table = 'reversal_relationships';

    protected $fillable = [
        'tenant_id',
        'original_journal_entry_id',
        'original_ledger_entry_id',
        'reversal_journal_entry_id',
        'reversal_ledger_entry_id',
        'reversal_mode',
        'reversal_basis',
        'reversal_reason_code',
        'reversal_reason_text',
        'full_or_partial',
        'reversed_amount_summary',
        'remaining_reversible_amount_summary',
        'component_count',
        'requested_by',
        'approved_by',
        'posted_at',
        'correction_case_id',
        'correlation_id',
    ];

    protected $casts = [
        'reversed_amount_summary' => 'decimal:2',
        'remaining_reversible_amount_summary' => 'decimal:2',
        'component_count' => 'integer',
        'posted_at' => 'datetime',
    ];

    /**
     * reversal_relationships lives in the central schema keyed by tenant_id.
     * Without this scope, a journal-entry id collision across tenants (e.g.
     * this tenant's JE #119 vs another tenant's already-reversed JE #119)
     * made eligibility checks answer "already fully reversed" and exposed
     * other tenants' reversal rows through the API.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            if (function_exists('tenant') && ($tenant = tenant())) {
                $query->where(
                    $query->getModel()->getTable().'.tenant_id',
                    $tenant->getTenantKey()
                );
            }
        });
    }

    // ── Relationships ──────────────────────────────────

    public function originalJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'original_journal_entry_id');
    }

    public function reversalJournal(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function components()
    {
        return $this->hasMany(ReversalComponent::class, 'reversal_relationship_id');
    }

    // ── Scopes ────────────────────────────────────────

    public function scopeForOriginal($query, int $journalEntryId)
    {
        return $query->where('original_journal_entry_id', $journalEntryId);
    }

    public function scopeFullReversals($query)
    {
        return $query->where('full_or_partial', 'FULL');
    }

    public function scopePartialReversals($query)
    {
        return $query->where('full_or_partial', 'PARTIAL');
    }

    // ── Accessors ──────────────────────────────────────

    public function isFullReversal(): bool
    {
        return $this->full_or_partial === 'FULL';
    }

    public function isPartialReversal(): bool
    {
        return $this->full_or_partial === 'PARTIAL';
    }

    public function getReversedAmountAttribute(): float
    {
        return (float) ($this->reversed_amount_summary ?? 0);
    }

    public function getRemainingReversibleAttribute(): float
    {
        return (float) ($this->remaining_reversible_amount_summary ?? 0);
    }
}

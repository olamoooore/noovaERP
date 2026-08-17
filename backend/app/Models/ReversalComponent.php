<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable component-level partial reversal lineage.
 * ADR-005 §15.3.4/§15.3.5 — every partial reversal must retain immutable component lineage.
 *
 * Tracks exactly which original ledger entry lines were reversed and how much.
 * This prevents over-reversal at the component level.
 */
class ReversalComponent extends Model
{
    protected $table = 'reversal_components';

    protected $fillable = [
        'reversal_relationship_id',
        'original_ledger_entry_line_id',
        'original_source_line_reference',
        'reversal_amount',
        'transaction_currency_amount',
        'base_currency_amount',
        'tax_component_reference',
        'dimensions',
        'component_remaining_reversible_amount',
        'account_id',
        'original_debit',
        'original_credit',
        'reversed_debit',
        'reversed_credit',
    ];

    protected $casts = [
        'reversal_amount' => 'decimal:2',
        'transaction_currency_amount' => 'decimal:2',
        'base_currency_amount' => 'decimal:2',
        'component_remaining_reversible_amount' => 'decimal:2',
        'original_debit' => 'decimal:2',
        'original_credit' => 'decimal:2',
        'reversed_debit' => 'decimal:2',
        'reversed_credit' => 'decimal:2',
        'dimensions' => 'array',
    ];

    // ── Relationships ──────────────────────────────────

    public function reversalRelationship(): BelongsTo
    {
        return $this->belongsTo(ReversalRelationship::class, 'reversal_relationship_id');
    }

    public function originalLine(): BelongsTo
    {
        return $this->belongsTo(JournalEntryLine::class, 'original_ledger_entry_line_id');
    }

    // ── Helpers ─────────────────────────────────────────

    /**
     * Check if this component has been fully reversed.
     */
    public function isFullyReversed(): bool
    {
        return abs($this->component_remaining_reversible_amount) < 0.01;
    }

    /**
     * Calculate the remaining reversible amount for this component.
     */
    public static function calculateRemaining(JournalEntryLine $line): float
    {
        $totalReversed = self::where('original_ledger_entry_line_id', $line->id)
            ->sum('reversal_amount');

        $originalAmount = max((float) $line->debit, (float) $line->credit);

        return $originalAmount - abs($totalReversed);
    }
}

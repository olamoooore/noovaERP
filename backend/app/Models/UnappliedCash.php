<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Unapplied cash — holds payment amounts not yet allocated to obligations.
 * ADR-005 §22 — UnappliedCash entity.
 */
class UnappliedCash extends Model
{
    protected $table = 'unapplied_cash';

    protected $fillable = [
        'tenant_id',
        'settlement_id',
        'amount',
        'currency_code',
        'customer_id',
        'vendor_id',
        'reason',
        'status',
        'journal_entry_id',
        'correlation_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    // ── Scopes ────────────────────────────────────────

    public function scopeAvailable($query)
    {
        return $query->where('status', 'AVAILABLE');
    }

    public function scopeForSettlement($query, int $settlementId)
    {
        return $query->where('settlement_id', $settlementId);
    }

    // ── Relationships ──────────────────────────────────

    public function settlement()
    {
        return $this->belongsTo(Settlement::class, 'settlement_id');
    }
}

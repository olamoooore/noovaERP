<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Allocation — links a settlement to specific receivable/payable obligations.
 * ADR-005 §22 — settlement allocation.
 */
class Allocation extends Model
{
    protected $table = 'allocations';

    protected $fillable = [
        'tenant_id',
        'settlement_id',
        'allocation_type',
        'source_record_type',
        'source_record_id',
        'allocated_amount',
        'currency_code',
        'exchange_rate',
        'journal_entry_id',
        'correlation_id',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'exchange_rate' => 'decimal:8',
    ];

    // ── Scopes ────────────────────────────────────────

    public function scopeForSettlement($query, int $settlementId)
    {
        return $query->where('settlement_id', $settlementId);
    }

    public function scopeForSource($query, string $type, int $id)
    {
        return $query->where('source_record_type', $type)->where('source_record_id', $id);
    }

    // ── Relationships ──────────────────────────────────

    public function settlement()
    {
        return $this->belongsTo(Settlement::class, 'settlement_id');
    }
}

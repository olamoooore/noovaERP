<?php

namespace App\Models;

use App\Accounting\Enums\SettlementState;
use Illuminate\Database\Eloquent\Model;

/**
 * Accounting settlement — allocates payments to receivables/payables.
 * ADR-005 §22 — separate state machine from payment execution.
 */
class Settlement extends Model
{
    protected $table = 'settlements';

    protected $fillable = [
        'tenant_id',
        'entity_id',
        'settlement_number',
        'settlement_state',
        'settlement_type',
        'payment_execution_id',
        'source_record_type',
        'source_record_id',
        'allocated_amount',
        'unapplied_amount',
        'currency_code',
        'journal_entry_id',
        'initiated_by',
        'correlation_id',
        'metadata',
        'reversed_at',
        'reversal_reason',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'unapplied_amount' => 'decimal:2',
        'metadata' => 'array',
        'settled_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Settlement $model) {
            $model->settlement_state ??= SettlementState::UNRECOGNISED->value;
            if (!$model->settlement_number) {
                $model->settlement_number = 'STL-' . strtoupper(uniqid('', true));
            }
        });
    }

    // ── Accessors ──────────────────────────────────────

    public function getStateEnumAttribute(): SettlementState
    {
        return SettlementState::from($this->settlement_state);
    }

    // ── Scopes ────────────────────────────────────────

    public function scopeUnallocated($query)
    {
        return $query->where('settlement_state', SettlementState::UNALLOCATED->value);
    }

    public function scopePartiallyAllocated($query)
    {
        return $query->where('settlement_state', SettlementState::PARTIALLY_ALLOCATED->value);
    }

    public function scopeFullyAllocated($query)
    {
        return $query->where('settlement_state', SettlementState::FULLY_ALLOCATED->value);
    }

    // ── Relationships ──────────────────────────────────

    public function allocations()
    {
        return $this->hasMany(Allocation::class, 'settlement_id');
    }

    public function unappliedCash()
    {
        return $this->hasMany(UnappliedCash::class, 'settlement_id');
    }

    public function paymentExecution()
    {
        return $this->belongsTo(PaymentExecution::class, 'payment_execution_id');
    }
}

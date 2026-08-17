<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Scheduled auto-reversal instruction.
 * ADR-005 §15.3.7 — accrual or policy-driven auto-reversal.
 *
 * A scheduled reversal instruction retains execution state and must
 * re-evaluate all mandatory gates at execution time.
 */
class AutoReversalInstruction extends Model
{
    protected $table = 'auto_reversal_instructions';

    protected $fillable = [
        'tenant_id',
        'original_posting_reference',
        'original_journal_entry_id',
        'scheduled_reversal_date',
        'reversal_reason_code',
        'reversal_reason_text',
        'reversal_policy_id',
        'reversal_policy_version',
        'execution_state',
        'executed_at',
        'executed_journal_entry_id',
        'failure_reason',
        'retry_count',
        'max_retries',
        'created_by',
    ];

    protected $casts = [
        'scheduled_reversal_date' => 'date',
        'executed_at' => 'datetime',
        'retry_count' => 'integer',
        'max_retries' => 'integer',
    ];

    // ── States ──────────────────────────────────────────

    const STATE_SCHEDULED = 'SCHEDULED';
    const STATE_READY = 'READY';
    const STATE_BLOCKED = 'BLOCKED';
    const STATE_EXECUTING = 'EXECUTING';
    const STATE_COMPLETED = 'COMPLETED';
    const STATE_FAILED = 'FAILED';
    const STATE_CANCELLED = 'CANCELLED';

    // ── Scopes ────────────────────────────────────────

    public function scopeScheduled($query)
    {
        return $query->where('execution_state', self::STATE_SCHEDULED);
    }

    public function scopeReady($query)
    {
        return $query->where('execution_state', self::STATE_READY);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeDueForExecution($query)
    {
        return $query->where('execution_state', self::STATE_SCHEDULED)
            ->where('scheduled_reversal_date', '<=', now()->toDateString());
    }

    // ── Helpers ─────────────────────────────────────────

    public function isScheduled(): bool
    {
        return $this->execution_state === self::STATE_SCHEDULED;
    }

    public function isCompleted(): bool
    {
        return $this->execution_state === self::STATE_COMPLETED;
    }

    public function canRetry(): bool
    {
        return $this->execution_state === self::STATE_FAILED
            && $this->retry_count < ($this->max_retries ?? 3);
    }

    public function markExecuting(): void
    {
        $this->update(['execution_state' => self::STATE_EXECUTING]);
    }

    public function markCompleted(int $journalEntryId): void
    {
        $this->update([
            'execution_state' => self::STATE_COMPLETED,
            'executed_at' => now(),
            'executed_journal_entry_id' => $journalEntryId,
        ]);
    }

    public function markFailed(string $reason): void
    {
        $this->update([
            'execution_state' => self::STATE_FAILED,
            'failure_reason' => $reason,
            'retry_count' => $this->retry_count + 1,
        ]);
    }

    public function markBlocked(string $reason): void
    {
        $this->update([
            'execution_state' => self::STATE_BLOCKED,
            'failure_reason' => $reason,
        ]);
    }
}

<?php

namespace App\Models;

use App\Accounting\Enums\CorrectionCaseState;
use Illuminate\Database\Eloquent\Model;

/**
 * Cross-capability correction case.
 * ADR-005 §56-59 — orchestrates corrections across multiple capabilities
 * (document, accounting, tax/regulatory, settlement).
 *
 * A CorrectionCase may be COMPLETED only when ALL_REQUIRED_CHILD_STEPS
 * = COMPLETED_OR_EXPLICITLY_NOT_REQUIRED.
 */
class CorrectionCase extends Model
{
    protected $table = 'correction_cases';

    protected $fillable = [
        'tenant_id',
        'entity_id',
        'case_number',
        'case_type',
        'overall_state',
        'document_correction_state',
        'accounting_correction_state',
        'regulatory_correction_state',
        'settlement_correction_state',
        'replacement_issue_state',
        'triggered_by',
        'trigger_reference_type',
        'trigger_reference_id',
        'reason_code',
        'reason_text',
        'initiated_by',
        'assigned_to',
        'correlation_id',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'opened_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (CorrectionCase $model) {
            $model->overall_state ??= CorrectionCaseState::OPEN->value;
            $model->opened_at ??= now();
            if (!$model->case_number) {
                $model->case_number = 'CC-' . strtoupper(uniqid('', true));
            }
        });
    }

    // ── States ──────────────────────────────────────────

    public function getOverallStateEnumAttribute(): CorrectionCaseState
    {
        return CorrectionCaseState::from($this->overall_state);
    }

    public function isComplete(): bool
    {
        return $this->overall_state === CorrectionCaseState::COMPLETED->value;
    }

    public function isBlocked(): bool
    {
        return $this->overall_state === CorrectionCaseState::BLOCKED->value;
    }

    /**
     * Check if all required child steps are completed or explicitly not required.
     * ADR-005 §58 — completion rule.
     */
    public function canComplete(): bool
    {
        $requiredStates = [
            $this->document_correction_state,
            $this->accounting_correction_state,
            $this->regulatory_correction_state,
            $this->settlement_correction_state,
            $this->replacement_issue_state,
        ];

        foreach ($requiredStates as $state) {
            if ($state !== null && $state !== 'COMPLETED' && $state !== 'NOT_REQUIRED') {
                return false;
            }
        }

        return true;
    }

    /**
     * Recalculate overall state from child states.
     * ADR-005 §57 — child failure does not automatically mean case failure.
     */
    public function recalculateState(): void
    {
        $childStates = array_filter([
            $this->document_correction_state,
            $this->accounting_correction_state,
            $this->regulatory_correction_state,
            $this->settlement_correction_state,
            $this->replacement_issue_state,
        ]);

        if (empty($childStates)) {
            return;
        }

        // Check if any child is FAILED with no remediation
        $hasFailed = in_array('FAILED', $childStates);
        $hasInProgress = in_array('IN_PROGRESS', $childStates);
        $hasPartiallyCompleted = in_array('PARTIALLY_COMPLETED', $childStates);

        $newState = match (true) {
            $this->canComplete() => CorrectionCaseState::COMPLETED,
            $hasFailed && !$hasInProgress => CorrectionCaseState::REMEDIATION_REQUIRED,
            $hasPartiallyCompleted || $hasInProgress => CorrectionCaseState::PARTIALLY_COMPLETED,
            default => CorrectionCaseState::IN_PROGRESS,
        };

        $this->update(['overall_state' => $newState->value]);

        if ($newState === CorrectionCaseState::COMPLETED) {
            $this->update(['completed_at' => now()]);
        }
    }

    // ── Scopes ────────────────────────────────────────

    public function scopeOpen($query)
    {
        return $query->where('overall_state', '!=', CorrectionCaseState::COMPLETED->value)
            ->where('overall_state', '!=', CorrectionCaseState::CANCELLED->value);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}

<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;
use App\Accounting\Enums\PeriodState;
use App\Models\FiscalPeriod;

/**
 * ACC-001 posting-authority gate: verifies the posting period is open.
 *
 * ADR-005 §8.4 — accounting period gate.
 * ACC-DEC-060 Track A (issue #69): now uses PeriodState enum for
 * authoritative evaluation instead of raw string comparison.
 *
 * The gate evaluates period_id from context and blocks if the period
 * is closed, locked, or missing. Unknown statuses are treated as
 * not-open (fail-closed).
 */
class PeriodOpenGate implements AuthorityGate
{
    public function gateCode(): string
    {
        return 'PERIOD_OPEN';
    }

    public function gateOwner(): string
    {
        return 'ACC-001';
    }

    public function gateVersion(): string
    {
        return '2.0';
    }

    public function evaluate(array $context): GateEvaluationResult
    {
        $periodId = $context['period_id'] ?? null;

        if (! $periodId) {
            // HR69-01: for actions where a period is MANDATORY (Opening
            // Balance admission, and any caller that flags period_required),
            // a missing period must fail CLOSED — never NOT_APPLICABLE.
            if ($this->periodIsMandatory($context)) {
                return GateEvaluationResult::block(
                    $this->gateCode(),
                    $this->gateOwner(),
                    'PERIOD_MISSING_MANDATORY',
                );
            }

            // Non-period-sensitive actions legitimately have no period to bind.
            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        $period = FiscalPeriod::find($periodId);

        if (! $period) {
            return GateEvaluationResult::block($this->gateCode(), $this->gateOwner(), 'PERIOD_NOT_FOUND');
        }

        // HR69-04: null/empty persisted status is mapped by fromDbStatus to a
        // fail-closed (CLOSED) state, never OPEN.
        $state = PeriodState::fromDbStatus($period->status);

        if (! $state->allowsPosting()) {
            return GateEvaluationResult::block($this->gateCode(), $this->gateOwner(), 'PERIOD_CLOSED');
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }

    /**
     * HR69-01: an action requires a bound posting period when the caller
     * explicitly flags it (period_required) or it is a period-mandatory
     * capability/document type. Opening Balance admission (ACC-001) is
     * period-mandatory per ACC-DEC-060 Track A.
     */
    private function periodIsMandatory(array $context): bool
    {
        if (($context['period_required'] ?? false) === true) {
            return true;
        }

        $mandatoryCapabilities = ['ACC-001']; // Opening Balance
        if (in_array($context['source_capability'] ?? null, $mandatoryCapabilities, true)) {
            return true;
        }

        return false;
    }
}

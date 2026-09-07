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
            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        $period = FiscalPeriod::find($periodId);

        if (! $period) {
            return GateEvaluationResult::block($this->gateCode(), $this->gateOwner(), 'PERIOD_NOT_FOUND');
        }

        $state = PeriodState::fromDbStatus($period->status ?? 'open');

        if (! $state->allowsPosting()) {
            return GateEvaluationResult::block($this->gateCode(), $this->gateOwner(), 'PERIOD_CLOSED');
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }
}

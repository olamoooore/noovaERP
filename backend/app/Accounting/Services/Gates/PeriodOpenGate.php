<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;
use App\Accounting\Enums\PeriodState;
use App\Models\FiscalPeriod;

/**
 * Verifies the posting period is open.
 * ADR-005 §8.4 — accounting period gate.
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
        return '1.0';
    }

    public function evaluate(array $context): GateEvaluationResult
    {
        $periodId = $context['period_id'] ?? null;

        if (!$periodId) {
            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        $period = FiscalPeriod::find($periodId);

        if (!$period) {
            return GateEvaluationResult::block($this->gateCode(), $this->gateOwner(), 'PERIOD_NOT_FOUND');
        }

        $state = $period->status ?? 'unknown';

        if ($state === 'closed' || $state === 'locked') {
            return GateEvaluationResult::block($this->gateCode(), $this->gateOwner(), 'PERIOD_CLOSED');
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }
}

<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;

/**
 * Verifies source aggregate version for optimistic concurrency.
 * ADR-005 §8.5 — commit-time revalidation of source aggregate version.
 */
class ConcurrencyGate implements AuthorityGate
{
    public function gateCode(): string
    {
        return 'SOURCE_VERSION';
    }

    public function gateOwner(): string
    {
        return 'ACC-002';
    }

    public function gateVersion(): string
    {
        return '1.0';
    }

    public function evaluate(array $context): GateEvaluationResult
    {
        $expectedVersion = $context['expected_record_version'] ?? null;
        $currentVersion = $context['current_record_version'] ?? null;

        // If no version check requested, not applicable
        if ($expectedVersion === null) {
            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        if ($currentVersion === null) {
            return GateEvaluationResult::error($this->gateCode(), $this->gateOwner(), 'VERSION_NOT_AVAILABLE');
        }

        if ($expectedVersion !== $currentVersion) {
            return GateEvaluationResult::block(
                $this->gateCode(),
                $this->gateOwner(),
                'SOURCE_VERSION_CONFLICT',
            );
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }
}

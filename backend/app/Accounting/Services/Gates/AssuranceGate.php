<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;
use App\Models\AccountingFinding;

/**
 * Checks for blocking assurance findings.
 * ADR-005 §39.1 — AssuranceBlockingPolicy: finding does not block merely because it exists.
 */
class AssuranceGate implements AuthorityGate
{
    public function gateCode(): string
    {
        return 'ASSURANCE_POLICY';
    }

    public function gateOwner(): string
    {
        return 'ACC-010';
    }

    public function gateVersion(): string
    {
        return '1.0';
    }

    public function evaluate(array $context): GateEvaluationResult
    {
        $blockingFindings = $context['blocking_assurance_findings'] ?? [];

        // If no blocking findings provided, check database
        if (empty($blockingFindings) && isset($context['record_type'], $context['record_id'])) {
            $blockingFindings = AccountingFinding::where('source_record_type', $context['record_type'])
                ->where('source_record_id', $context['record_id'])
                ->whereIn('status', ['OPEN', 'IN_PROGRESS'])
                ->where('blocking', true)
                ->get()
                ->toArray();
        }

        if (empty($blockingFindings)) {
            return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
        }

        // Check if any finding has a blocking policy that matches the current action
        $action = $context['requested_action'] ?? null;
        foreach ($blockingFindings as $finding) {
            $blockingPolicy = $finding['blocking_policy'] ?? null;
            if ($blockingPolicy && ($blockingPolicy === 'BLOCK_ALL' || ($action && str_contains($blockingPolicy, $action)))) {
                return GateEvaluationResult::block(
                    $this->gateCode(),
                    $this->gateOwner(),
                    'ASSURANCE_BLOCKED',
                    $this->gateVersion(),
                    $finding['id'] ?? null,
                );
            }
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }
}

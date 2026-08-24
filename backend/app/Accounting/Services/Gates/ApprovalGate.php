<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;
use App\Models\ApprovalPolicy;

/**
 * Verifies approval requirements for sensitive operations.
 * ADR-005 §19 — Approval Authority: certain actions require explicit approval
 * from authorized parties before execution.
 */
class ApprovalGate implements AuthorityGate
{
    public function gateCode(): string
    {
        return 'APPROVAL_REQUIRED';
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
        $actionType = $context['action_type'] ?? $context['document_type'] ?? null;
        $amount = $context['total_amount'] ?? 0;
        $hasApproval = $context['has_approval'] ?? false;
        $approvalPolicy = $context['approval_policy'] ?? null;

        if (!$actionType) {
            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        // If approval already obtained, pass
        if ($hasApproval) {
            return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
        }

        // Check if an approval policy applies
        if ($approvalPolicy) {
            $threshold = $approvalPolicy['threshold'] ?? 0;
            $requiresApproval = $approvalPolicy['requires_approval'] ?? false;

            if ($requiresApproval && $amount >= $threshold) {
                return GateEvaluationResult::block(
                    $this->gateCode(),
                    $this->gateOwner(),
                    'APPROVAL_NOT_OBTAINED',
                    $this->gateVersion(),
                    "Amount {$amount} exceeds threshold {$threshold} and requires approval",
                );
            }
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }
}

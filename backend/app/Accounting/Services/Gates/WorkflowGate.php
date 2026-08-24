<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;

/**
 * Verifies workflow state permits the requested action.
 * ADR-005 §40 — Workflow Authority: workflow consumes facts, doesn't recalculate them.
 * Ensures the workflow state of the source document allows the accounting action.
 */
class WorkflowGate implements AuthorityGate
{
    public function gateCode(): string
    {
        return 'WORKFLOW_STATE';
    }

    public function gateOwner(): string
    {
        return 'SHARED_PLATFORM';
    }

    public function gateVersion(): string
    {
        return '1.0';
    }

    public function evaluate(array $context): GateEvaluationResult
    {
        $workflowState = $context['workflow_state'] ?? null;
        $requiredWorkflowState = $context['required_workflow_state'] ?? null;

        // If no workflow state constraint, not applicable
        if ($requiredWorkflowState === null) {
            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        // If no workflow state provided, allow (document may not use workflow)
        if ($workflowState === null) {
            return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
        }

        // Check if the current workflow state matches the required state
        $allowedStates = is_array($requiredWorkflowState)
            ? $requiredWorkflowState
            : [$requiredWorkflowState];

        if (!in_array($workflowState, $allowedStates)) {
            return GateEvaluationResult::block(
                $this->gateCode(),
                $this->gateOwner(),
                'WORKFLOW_STATE_INVALID',
                $this->gateVersion(),
                "Workflow state '{$workflowState}' does not match required state(s): " . implode(', ', $allowedStates),
            );
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }
}

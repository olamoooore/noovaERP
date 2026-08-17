<?php

namespace App\Accounting\Contracts;

use App\Accounting\DTOs\GateEvaluationResult;

/**
 * Interface for individual authority gates.
 * ADR-005 §8 — a reusable Finance ActionEligibilityEvaluator shall implement ACC-REG P19 Mandatory Authority Conjunction.
 */
interface AuthorityGate
{
    /**
     * Unique code identifying this gate (e.g., 'TENANT_ACTIVE', 'PERIOD_OPEN').
     */
    public function gateCode(): string;

    /**
     * Capability that owns this gate.
     */
    public function gateOwner(): string;

    /**
     * Version of this gate for auditability.
     */
    public function gateVersion(): string;

    /**
     * Evaluate the gate against the given context.
     *
     * @param array $context  Evaluation context containing record, actor, tenant, etc.
     * @return GateEvaluationResult
     */
    public function evaluate(array $context): GateEvaluationResult;
}

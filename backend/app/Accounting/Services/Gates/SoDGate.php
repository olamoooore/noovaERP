<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;

/**
 * Separation of Duties gate — prevents the same actor from performing
 * conflicting operations in a workflow chain.
 * ADR-005 §41 — SoD: preparer and approver must be different actors;
 * certain role combinations are prohibited for the same transaction.
 */
class SoDGate implements AuthorityGate
{
    public function gateCode(): string
    {
        return 'SOD_SEPARATION';
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
        $actorId = $context['actor_id'] ?? null;
        $preparerId = $context['preparer_id'] ?? null;
        $approverId = $context['approver_id'] ?? null;
        $sodPolicy = $context['sod_policy'] ?? null;

        // If no SoD constraint, not applicable
        if (!$sodPolicy && !$preparerId && !$approverId) {
            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        // Rule 1: Preparer and approver must be different
        if ($preparerId && $approverId && $preparerId === $approverId) {
            return GateEvaluationResult::block(
                $this->gateCode(),
                $this->gateOwner(),
                'SOD_SELF_APPROVAL',
                $this->gateVersion(),
                'Preparer and approver must be different actors',
            );
        }

        // Rule 2: Actor cannot be both preparer and approver
        if ($actorId && $preparerId && $approverId) {
            if ($actorId === $preparerId && $actorId === $approverId) {
                return GateEvaluationResult::block(
                    $this->gateCode(),
                    $this->gateOwner(),
                    'SOD_CONFLICT',
                    $this->gateOwner(),
                    'Actor cannot perform both preparer and approver roles',
                );
            }
        }

        // Rule 3: Custom SoD policy — prohibited role combinations
        if ($sodPolicy && $actorId) {
            $prohibitedCombinations = $sodPolicy['prohibited_combinations'] ?? [];
            $actorRoles = $sodPolicy['actor_roles'] ?? [];

            foreach ($prohibitedCombinations as $combination) {
                $intersection = array_intersect($combination, $actorRoles);
                if (count($intersection) >= 2) {
                    return GateEvaluationResult::block(
                        $this->gateCode(),
                        $this->gateOwner(),
                        'SOD_ROLE_CONFLICT',
                        $this->gateVersion(),
                        'Actor holds prohibited role combination: ' . implode(', ', $intersection),
                    );
                }
            }
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }
}

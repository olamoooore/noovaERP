<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;

/**
 * Verifies the actor has access to the specific entity being acted upon.
 * ADR-005 §8.6 — Entity-Access Gate: actor must have access to the entity context.
 * Prevents cross-entity data leakage in multi-entity deployments.
 */
class EntityAccessGate implements AuthorityGate
{
    public function gateCode(): string
    {
        return 'ENTITY_ACCESS';
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
        $entityId = $context['entity_id'] ?? null;
        $actorEntities = $context['actor_accessible_entities'] ?? null;

        // If no entity constraint, not applicable
        if (!$entityId) {
            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        // If no actor, error
        if (!$actorId) {
            return GateEvaluationResult::error($this->gateCode(), $this->gateOwner(), 'MISSING_ACTOR');
        }

        // If accessible entities not provided, skip (platform-level enforcement)
        if ($actorEntities === null) {
            return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
        }

        // Check if the actor has access to this entity
        if (!in_array($entityId, (array) $actorEntities)) {
            return GateEvaluationResult::block(
                $this->gateCode(),
                $this->gateOwner(),
                'ENTITY_ACCESS_DENIED',
                $this->gateVersion(),
                "Actor does not have access to entity {$entityId}",
            );
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }
}

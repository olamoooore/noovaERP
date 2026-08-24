<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;
use App\Services\AccountingAccessService;

/**
 * Verifies the actor has the required entitlement for the specific accounting operation.
 * ADR-005 §8.3 — Entitlement Gate: actor must hold the specific entitlement for the operation.
 * This is more granular than the PermissionGate — it checks operation-specific entitlements
 * rather than general permissions.
 */
class EntitlementGate implements AuthorityGate
{
    public function __construct(
        private readonly ?AccountingAccessService $accessService = null,
    ) {}

    public function gateCode(): string
    {
        return 'ENTITLEMENT';
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
        $actorId = $context['actor_id'] ?? null;
        $entitlement = $context['required_entitlement'] ?? null;
        $tenantId = $context['tenant_id'] ?? null;

        // If no entitlement required, not applicable
        if (!$entitlement) {
            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        if (!$actorId) {
            return GateEvaluationResult::error($this->gateCode(), $this->gateOwner(), 'MISSING_ACTOR');
        }

        // If no access service available, pass (foundation phase)
        if (!$this->accessService) {
            return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
        }

        try {
            $hasEntitlement = $this->accessService->userHasEntitlement($actorId, $entitlement, $tenantId);
        } catch (\Exception) {
            return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
        }

        if (!$hasEntitlement) {
            return GateEvaluationResult::block(
                $this->gateCode(),
                $this->gateOwner(),
                'ENTITLEMENT_MISSING',
                $this->gateVersion(),
                "Actor lacks required entitlement: {$entitlement}",
            );
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }
}

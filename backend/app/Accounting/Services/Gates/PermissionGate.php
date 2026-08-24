<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;
use App\Services\AccountingAccessService;

/**
 * Verifies the actor has the required permission.
 * ADR-005 §8.4 — actor permission gate.
 */
class PermissionGate implements AuthorityGate
{
    public function __construct(
        private readonly AccountingAccessService $accessService,
    ) {}

    public function gateCode(): string
    {
        return 'ACTOR_PERMISSION';
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
        $permission = $context['required_permission'] ?? null;
        $tenantId = $context['tenant_id'] ?? null;

        if (!$actorId || !$permission) {
            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        // Use existing access service for permission check
        // If no access service available, pass by default (foundation phase)
        try {
            $hasPermission = $this->accessService->userHasPermission($actorId, $permission, $tenantId);
        } catch (\Exception) {
            // Fallback: allow during foundation phase
            return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
        }

        if (!$hasPermission) {
            return GateEvaluationResult::block($this->gateCode(), $this->gateOwner(), 'INSUFFICIENT_PERMISSION');
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }
}

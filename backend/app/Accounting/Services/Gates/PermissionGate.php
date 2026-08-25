<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;
use App\Services\AccountingAccessService;

/**
 * Verifies the actor has the required permission.
 * ADR-005 §8.4 — actor permission gate.
 *
 * FAIL-CLOSED: missing required inputs → BLOCK, not NOT_APPLICABLE.
 * Service exceptions → BLOCK (not PASS).
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
        return '2.0';
    }

    public function evaluate(array $context): GateEvaluationResult
    {
        $actorId = $context['actor_id'] ?? null;
        $permission = $context['required_permission'] ?? null;
        $tenantId = $context['tenant_id'] ?? null;

        // Missing actor is a blocking condition (fail-closed)
        if (! $actorId) {
            return GateEvaluationResult::block(
                $this->gateCode(),
                $this->gateOwner(),
                'MISSING_PERMISSION_CONTEXT',
            );
        }

        // No permission required → gate not applicable (module handles own RBAC)
        if (! $permission) {
            return GateEvaluationResult::pass(
                $this->gateCode(),
                $this->gateOwner(),
                $this->gateVersion(),
            );
        }

        // FAIL-CLOSED: service exception → BLOCK (not PASS)
        try {
            $hasPermission = $this->accessService->userHasPermission($actorId, $permission, $tenantId);
        } catch (\Exception) {
            return GateEvaluationResult::block(
                $this->gateCode(),
                $this->gateOwner(),
                'PERMISSION_SERVICE_ERROR',
            );
        }

        if (! $hasPermission) {
            return GateEvaluationResult::block($this->gateCode(), $this->gateOwner(), 'INSUFFICIENT_PERMISSION');
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }
}

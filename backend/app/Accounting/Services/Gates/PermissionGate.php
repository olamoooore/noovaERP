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
        $action = $context['action'] ?? null;

        // FAIL-CLOSED: missing actor is a blocking condition
        if (! $actorId) {
            return GateEvaluationResult::block(
                $this->gateCode(),
                $this->gateOwner(),
                'MISSING_PERMISSION_CONTEXT',
            );
        }

        // FAIL-CLOSED: for material posting actions (POST_JOURNAL, REVERSE,
        // etc.), a missing required_permission is a blocking error, not a
        // silent PASS. The gate is registered as mandatory in
        // ActionEligibilityEvaluator for these actions; treating null as
        // PASS would bypass authority conjunction (ADR-005 §8.4, P19).
        //
        // Read-only or auxiliary actions (where this gate was explicitly
        // registered as optional) can still pass when permission is not
        // supplied by passing `action: null` or a non-material action code.
        $isMaterialAction = in_array($action, [
            'POST_JOURNAL',
            'REVERSE',
            'REVERSE_JOURNAL',
            'CORRECT',
            'CORRECT_DOCUMENT',
            'PERIOD_CLOSE',
            'PERIOD_LOCK',
            'PERIOD_REOPEN',
        ], true);

        if (! $permission && $isMaterialAction) {
            return GateEvaluationResult::error(
                $this->gateCode(),
                $this->gateOwner(),
                'MISSING_REQUIRED_PERMISSION_FOR_MATERIAL_ACTION',
            );
        }

        // No permission required AND action is not material → not applicable
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

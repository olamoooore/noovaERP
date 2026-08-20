<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;

/**
 * Verifies the tenant is active.
 */
class TenantActiveGate implements AuthorityGate
{
    public function gateCode(): string
    {
        return 'TENANT_ACTIVE';
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
        $tenant = $context['tenant'] ?? (function_exists('tenant') ? tenant() : null);

        if (!$tenant) {
            return GateEvaluationResult::error($this->gateCode(), $this->gateOwner(), 'TENANT_NOT_FOUND');
        }

        if (isset($tenant->status) && $tenant->status !== 'active') {
            return GateEvaluationResult::block($this->gateCode(), $this->gateOwner(), 'ENTITLEMENT_INACTIVE');
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }
}

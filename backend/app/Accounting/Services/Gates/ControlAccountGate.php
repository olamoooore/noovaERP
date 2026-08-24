<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;
use App\Models\AccountingControlAccountPolicy;

/**
 * Verifies control-account source policy.
 * ADR-005 §17 — each control account stores an approved source policy.
 * Blocks posting to control accounts unless the source capability is explicitly allowed.
 */
class ControlAccountGate implements AuthorityGate
{
    public function gateCode(): string
    {
        return 'CONTROL_ACCOUNT';
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
        $postingLines = $context['posting_lines'] ?? [];
        $sourceCapability = $context['source_capability'] ?? $context['document_type'] ?? null;

        if (empty($postingLines) || !$sourceCapability) {
            return GateEvaluationResult::notApplicable($this->gateCode(), $this->gateOwner());
        }

        // Check each posting line for control account restrictions
        foreach ($postingLines as $line) {
            $accountId = $line['account_id'] ?? null;
            if (!$accountId) {
                continue;
            }

            $policy = AccountingControlAccountPolicy::active()
                ->forAccount($accountId)
                ->first();

            if (!$policy) {
                continue;
            }

            // Check if the source capability is allowed
            if (!$policy->isSourceAllowed($sourceCapability)) {
                // Check if exceptional authority is required
                if ($policy->requiresExceptionalAuthority()) {
                    $hasExceptional = $context['has_exceptional_authority'] ?? false;
                    if (!$hasExceptional) {
                        return GateEvaluationResult::block(
                            $this->gateCode(),
                            $this->gateOwner(),
                            'CONTROL_ACCOUNT_EXCEPTIONAL_REQUIRED',
                            $this->gateVersion(),
                            "Account {$accountId} requires exceptional authority for {$sourceCapability}",
                        );
                    }
                } else {
                    return GateEvaluationResult::block(
                        $this->gateCode(),
                        $this->gateOwner(),
                        'CONTROL_ACCOUNT_SOURCE_BLOCKED',
                        $this->gateVersion(),
                        "Source capability '{$sourceCapability}' is not allowed to post to control account {$accountId}",
                    );
                }
            }
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }
}

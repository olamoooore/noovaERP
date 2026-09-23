<?php

namespace App\Accounting\Services\Gates;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;
use App\Models\Accounting\AccountingFinding;
use App\Models\AssuranceBlockingPolicy;

/**
 * Checks for blocking assurance findings.
 * ADR-005 §39.1 — AssuranceBlockingPolicy: a finding does not block merely because it exists.
 * Blocking is controlled by a versioned AssuranceBlockingPolicy that considers
 * finding_category, assertion_level, and severity_threshold.
 */
class AssuranceGate implements AuthorityGate
{
    public function gateCode(): string
    {
        return 'ASSURANCE_POLICY';
    }

    public function gateOwner(): string
    {
        return 'ACC-010';
    }

    public function gateVersion(): string
    {
        return '1.1';
    }

    public function evaluate(array $context): GateEvaluationResult
    {
        // Resolve the list of candidate findings from context or from the DB
        $findings = $this->resolveCandidateFindings($context);

        if (empty($findings)) {
            return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
        }

        $action = $context['requested_action'] ?? $context['action'] ?? null;
        $tenantId = $context['tenant_id'] ?? null;

        // Load active, blocking AssuranceBlockingPolicy rows for this tenant.
        // A policy applies to a finding when (a) finding_category matches or is
        // wildcard '*', (b) policy is currently in its effective date window,
        // (c) action_scope (if present) contains the requested action or is null/empty.
        $policiesQuery = AssuranceBlockingPolicy::query()
            ->active()
            ->blocking();

        if ($tenantId !== null) {
            $policiesQuery->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            });
        } else {
            $policiesQuery->whereNull('tenant_id');
        }

        $policies = $policiesQuery->get();

        if ($policies->isEmpty()) {
            // ADR-005 §39.1: a finding does not block merely because it exists.
            // With no active blocking policies, no finding can block an action.
            return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
        }

        foreach ($findings as $finding) {
            $findingCategory = is_array($finding) ? ($finding['finding_type'] ?? null) : $finding->finding_type;
            $assertionLevel = is_array($finding) ? ($finding['assertion_level'] ?? 'STANDARD') : ($finding->assertion_level ?? 'STANDARD');
            $severity = strtoupper(is_array($finding) ? ($finding['severity'] ?? 'LOW') : ($finding->severity ?? 'LOW'));

            foreach ($policies as $policy) {
                if (! $this->actionMatches($policy->action_scope ?? null, $action)) {
                    continue;
                }

                if ($policy->shouldBlock((string) $findingCategory, (string) $assertionLevel, $severity)) {
                    $findingId = is_array($finding) ? ($finding['id'] ?? null) : $finding->id;

                    return GateEvaluationResult::block(
                        $this->gateCode(),
                        $this->gateOwner(),
                        'ASSURANCE_BLOCKED',
                        $this->gateVersion(),
                        $findingId,
                    );
                }
            }
        }

        return GateEvaluationResult::pass($this->gateCode(), $this->gateOwner(), $this->gateVersion());
    }

    /**
     * Resolve candidate findings from the context or by querying the database.
     *
     * Issue #66 (HR66-03): the aggregate identity in the context is the SOURCE
     * DOCUMENT of the posting. Findings are matched by source_type plus either
     * a UUID source_id (the controlled aggregate ids, e.g. close cycles) or the
     * document reference_number (numeric document ids cannot be stored in the
     * uuid source_id column — querying them unguarded raised a SQL cast error).
     */
    private function resolveCandidateFindings(array $context): array
    {
        if (! empty($context['blocking_assurance_findings'])) {
            return $context['blocking_assurance_findings'];
        }

        $recordType = $context['record_type'] ?? null;
        if ($recordType === null) {
            return [];
        }

        $recordId = $context['record_id'] ?? null;
        $recordReference = $context['record_reference'] ?? null;

        $hasUuidId = $recordId !== null && \Illuminate\Support\Str::isUuid((string) $recordId);
        $hasReference = is_string($recordReference) && $recordReference !== '';

        if (! $hasUuidId && ! $hasReference) {
            return [];
        }

        return AccountingFinding::where('source_type', $recordType)
            ->where(function ($query) use ($hasUuidId, $recordId, $hasReference, $recordReference) {
                if ($hasUuidId) {
                    $query->orWhere('source_id', (string) $recordId);
                }
                if ($hasReference) {
                    $query->orWhere('reference_number', $recordReference);
                }
            })
            ->whereIn('status', ['open', 'acknowledged', 'investigating'])
            ->get()
            ->all();
    }

    /**
     * Determine whether a policy's action_scope matches the requested action.
     * If action_scope is null/empty, the policy applies to all actions.
     */
    private function actionMatches(?array $actionScope, ?string $action): bool
    {
        if (empty($actionScope)) {
            return true;
        }
        if ($action === null) {
            return false;
        }

        foreach ($actionScope as $scope) {
            if ($scope === '*' || $scope === $action || str_contains((string) $action, (string) $scope)) {
                return true;
            }
        }

        return false;
    }
}

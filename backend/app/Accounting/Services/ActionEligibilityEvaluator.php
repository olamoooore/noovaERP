<?php

namespace App\Accounting\Services;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;
use App\Accounting\Enums\EligibilityResult;
use App\Accounting\Enums\ReasonCode;
use App\Accounting\Services\Gates\ApprovalGate;
use App\Accounting\Services\Gates\AssuranceGate;
use App\Accounting\Services\Gates\ConcurrencyGate;
use App\Accounting\Services\Gates\ControlAccountGate;
use App\Accounting\Services\Gates\EntitlementGate;
use App\Accounting\Services\Gates\EntityAccessGate;
use App\Accounting\Services\Gates\IdempotencyGate;
use App\Accounting\Services\Gates\PeriodOpenGate;
use App\Accounting\Services\Gates\PermissionGate;
use App\Accounting\Services\Gates\SoDGate;
use App\Accounting\Services\Gates\TaxGate;
use App\Accounting\Services\Gates\TenantActiveGate;
use App\Accounting\Services\Gates\WorkflowGate;
use App\Models\AuthorityEvaluationSnapshot;
use Illuminate\Support\Str;

/**
 * Reusable Finance ActionEligibilityEvaluator.
 * ADR-005 §8 — implements ACC-REG P19 Mandatory Authority Conjunction.
 *
 * Every material eligibility decision must be bound to an immutable evaluation snapshot.
 * ALLOW is valid only when every mandatory applicable gate returns PASS.
 */
class ActionEligibilityEvaluator
{
    /** @var AuthorityGate[] */
    private array $mandatoryGates = [];

    public function __construct(
        private readonly StateTransitionService $transitionService,
    ) {
        // Register mandatory gates (ADR-005 §8 — every mandatory gate must PASS)
        $this->registerMandatoryGate(new TenantActiveGate);
        $this->registerMandatoryGate(new PeriodOpenGate);
        $this->registerMandatoryGate(new ConcurrencyGate);
        $this->registerMandatoryGate(new IdempotencyGate);
        $this->registerMandatoryGate(new AssuranceGate);
        $this->registerMandatoryGate(new ControlAccountGate);
        $this->registerMandatoryGate(new TaxGate);
        $this->registerMandatoryGate(new \App\Accounting\Services\Gates\TaxPeriodLockGate);
        $this->registerMandatoryGate(new WorkflowGate);
        $this->registerMandatoryGate(new ApprovalGate);
        $this->registerMandatoryGate(new EntitlementGate);
        $this->registerMandatoryGate(new EntityAccessGate);
        $this->registerMandatoryGate(new SoDGate);

        // Register mandatory permission gate (ADR-005 §8.4 — P19)
        // PermissionGate is mandatory — failure to resolve is fatal
        $this->registerMandatoryGate(app(PermissionGate::class));
    }

    /**
     * Register a mandatory authority gate.
     */
    public function registerMandatoryGate(AuthorityGate $gate): void
    {
        $this->mandatoryGates[$gate->gateCode()] = $gate;
    }

    /**
     * Evaluate all gates for a given action.
     *
     * @param  string  $action  The requested action (e.g., 'POST_JOURNAL', 'REVERSE_ENTRY')
     * @param  string  $recordType  The aggregate type being acted upon
     * @param  string  $recordId  The aggregate ID
     * @param  array  $context  Additional context (tenant, actor, period, etc.)
     * @param  bool  $requireSnapshot  Whether to persist an evaluation snapshot
     */
    public function evaluate(
        string $action,
        string $recordType,
        string $recordId,
        array $context = [],
        bool $requireSnapshot = true,
    ): AuthorityEvaluationResult {
        $gateResults = [];
        $finalResult = EligibilityResult::ALLOW;

        // Evaluate mandatory gates
        foreach ($this->mandatoryGates as $gate) {
            try {
                $result = $gate->evaluate($context);
                $gateResults[] = $result;

                if ($result->result === \App\Accounting\Enums\GateResult::BLOCK) {
                    $finalResult = EligibilityResult::BLOCK;
                } elseif ($result->result === \App\Accounting\Enums\GateResult::ERROR && $finalResult !== EligibilityResult::BLOCK) {
                    $finalResult = EligibilityResult::ERROR;
                }
            } catch (\Exception $e) {
                $gateResults[] = GateEvaluationResult::error(
                    $gate->gateCode(),
                    $gate->gateOwner(),
                    'GATE_EVALUATION_ERROR',
                );
                if ($finalResult !== EligibilityResult::BLOCK) {
                    $finalResult = EligibilityResult::ERROR;
                }
            }
        }

        // Build gate results array for snapshot
        $gateResultsArray = array_map(fn (GateEvaluationResult $r) => $r->toArray(), $gateResults);

        // Determine the blocking reason
        $blockingReason = null;
        if ($finalResult === EligibilityResult::BLOCK) {
            foreach ($gateResults as $result) {
                if ($result->result === \App\Accounting\Enums\GateResult::BLOCK && $result->reasonCode) {
                    $blockingReason = ReasonCode::tryFrom($result->reasonCode);
                    break;
                }
            }
        }

        // Persist snapshot if required
        $snapshotId = null;
        if ($requireSnapshot) {
            // FAIL-CLOSED: tenant_id and actor_id must be real, not fabricated.
            // Missing identity → BLOCK with canonical reason, no silent ALLOW.
            $tenantId = $context['tenant_id'] ?? null;
            $actorId = $context['actor_id'] ?? null;

            if (! $tenantId || ! $actorId) {
                $finalResult = EligibilityResult::BLOCK;
                $blockingReason = ReasonCode::MISSING_PERMISSION_CONTEXT;

                return new AuthorityEvaluationResult(
                    result: $finalResult,
                    gateResults: $gateResults,
                    blockingReason: $blockingReason,
                    snapshotId: null,
                );
            }

            // Use deterministic UUID v5 for actor_id so it's traceable
            // (numeric user IDs mapped to UUID column type).
            $actorUuid = is_numeric($actorId)
                ? \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'actor:'.$actorId)->toString()
                : (string) $actorId;

            // Issue found during #280 verification: numeric entity ids feed a
            // uuid column — map them with the same deterministic uuid5
            // convention used for actors (and by TaxDeterminationService).
            $entityId = $context['entity_id'] ?? null;

            if (is_string($entityId) && $entityId !== '' && ! \Illuminate\Support\Str::isUuid($entityId)) {
                $entityId = \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'legal_entity:'.$entityId)->toString();
            }

            $snapshot = AuthorityEvaluationSnapshot::create([
                'action_eligibility_evaluation_id' => Str::uuid()->toString(),
                'requested_action' => $action,
                'record_type' => $recordType,
                'record_id' => $recordId,
                'record_version' => $context['current_record_version'] ?? null,
                'tenant_id' => (string) $tenantId,
                'entity_id' => $entityId,
                'actor_id' => $actorUuid,
                'context_snapshot_hash' => hash('sha256', json_encode($context)),
                'policy_versions' => $this->buildPolicyVersions(),
                'state_versions' => $this->buildStateVersions($context),
                'gate_results' => $gateResultsArray,
                'final_result' => $finalResult->value,
                'valid_until' => now()->addMinutes(5),
                'correlation_id' => $context['correlation_id'] ?? null,
            ]);
            $snapshotId = $snapshot->action_eligibility_evaluation_id;
        }

        return new AuthorityEvaluationResult(
            result: $finalResult,
            gateResults: $gateResults,
            blockingReason: $blockingReason,
            snapshotId: $snapshotId,
        );
    }

    private function buildPolicyVersions(): array
    {
        return [
            'evaluator_version' => '1.0',
            'mandatory_gates' => array_keys($this->mandatoryGates),
        ];
    }

    private function buildStateVersions(array $context): array
    {
        return [
            'period_version' => $context['period_version'] ?? null,
            'aggregate_version' => $context['current_record_version'] ?? null,
        ];
    }
}

/**
 * Result of a full authority eligibility evaluation.
 */
class AuthorityEvaluationResult
{
    public function __construct(
        public readonly EligibilityResult $result,
        public readonly array $gateResults = [],
        public readonly ?ReasonCode $blockingReason = null,
        public readonly ?string $snapshotId = null,
    ) {}

    public function isAllowed(): bool
    {
        return $this->result === EligibilityResult::ALLOW;
    }

    public function isBlocked(): bool
    {
        return $this->result === EligibilityResult::BLOCK;
    }

    public function hasError(): bool
    {
        return $this->result === EligibilityResult::ERROR;
    }
}

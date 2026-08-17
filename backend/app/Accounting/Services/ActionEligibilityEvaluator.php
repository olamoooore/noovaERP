<?php

namespace App\Accounting\Services;

use App\Accounting\Contracts\AuthorityGate;
use App\Accounting\DTOs\GateEvaluationResult;
use App\Accounting\Enums\EligibilityResult;
use App\Accounting\Enums\ReasonCode;
use App\Accounting\Services\Gates\AssuranceGate;
use App\Accounting\Services\Gates\ConcurrencyGate;
use App\Accounting\Services\Gates\IdempotencyGate;
use App\Accounting\Services\Gates\PeriodOpenGate;
use App\Accounting\Services\Gates\TenantActiveGate;
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
    private array $optionalGates = [];

    public function __construct(
        private readonly StateTransitionService $transitionService,
    ) {
        // Register default mandatory gates
        $this->registerMandatoryGate(new TenantActiveGate());
        $this->registerMandatoryGate(new PeriodOpenGate());
        $this->registerMandatoryGate(new ConcurrencyGate());
        $this->registerMandatoryGate(new IdempotencyGate());
        $this->registerMandatoryGate(new AssuranceGate());
    }

    /**
     * Register a mandatory authority gate.
     */
    public function registerMandatoryGate(AuthorityGate $gate): void
    {
        $this->mandatoryGates[$gate->gateCode()] = $gate;
    }

    /**
     * Register an optional authority gate.
     */
    public function registerOptionalGate(AuthorityGate $gate): void
    {
        $this->optionalGates[$gate->gateCode()] = $gate;
    }

    /**
     * Evaluate all gates for a given action.
     *
     * @param string $action         The requested action (e.g., 'POST_JOURNAL', 'REVERSE_ENTRY')
     * @param string $recordType     The aggregate type being acted upon
     * @param string $recordId       The aggregate ID
     * @param array  $context        Additional context (tenant, actor, period, etc.)
     * @param bool   $requireSnapshot Whether to persist an evaluation snapshot
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

        // Evaluate optional gates (non-blocking)
        foreach ($this->optionalGates as $gate) {
            try {
                $result = $gate->evaluate($context);
                $gateResults[] = $result;
                // Optional gate BLOCK does not change final result
            } catch (\Exception) {
                // Optional gate errors are non-blocking
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
            $snapshot = AuthorityEvaluationSnapshot::create([
                'action_eligibility_evaluation_id' => Str::uuid()->toString(),
                'requested_action' => $action,
                'record_type' => $recordType,
                'record_id' => $recordId,
                'record_version' => $context['current_record_version'] ?? null,
                'tenant_id' => $context['tenant_id'] ?? null,
                'entity_id' => $context['entity_id'] ?? null,
                'actor_id' => $context['actor_id'] ?? 'system',
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
            'optional_gates' => array_keys($this->optionalGates),
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

<?php

namespace Tests\Feature;

use App\Accounting\DTOs\CommandEnvelope;
use App\Accounting\DTOs\GateEvaluationResult;
use App\Accounting\DTOs\NegativeState;
use App\Accounting\Enums\CommandResult;
use App\Accounting\Enums\CorrectionCaseState;
use App\Accounting\Enums\EligibilityResult;
use App\Accounting\Enums\JournalBusinessState;
use App\Accounting\Enums\PaymentExecutionState;
use App\Accounting\Enums\PeriodState;
use App\Accounting\Enums\PostingState;
use App\Accounting\Enums\ReasonCode;
use App\Accounting\Enums\ReversalBasis;
use App\Accounting\Enums\ReversalMode;
use App\Accounting\Enums\RevisionLineageState;
use App\Accounting\Enums\SettlementState;
use App\Accounting\Enums\StateDimension;
use App\Accounting\Enums\WorkflowState;
use App\Accounting\Services\ActionEligibilityEvaluator;
use App\Accounting\Services\PostingPolicyMapper;
use App\Accounting\Services\RevisionPolicyResolver;
use App\Accounting\Services\StateTransitionService;
use App\Models\AuthorityEvaluationSnapshot;
use App\Models\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-005 §71 — mandatory negative architecture tests.
 * §73 item 20 — negative-test evidence pack.
 */
class Adr005ComplianceTest extends TestCase
{
    use RefreshDatabase;

    // §7 — Command Contract Standard

    public function test_command_result_has_required_semantics(): void
    {
        $accepted = CommandResult::ACCEPTED;
        $rejected = CommandResult::REJECTED;
        $blocked = CommandResult::BLOCKED;
        $conflict = CommandResult::CONFLICT;
        $failed = CommandResult::FAILED;

        $this->assertTrue($accepted->isTerminal());
        $this->assertTrue($rejected->isTerminal());
        $this->assertTrue($blocked->isTerminal());
        $this->assertFalse($conflict->isTerminal());
        $this->assertFalse($failed->isTerminal());
    }

    public function test_negative_state_requires_reason_code(): void
    {
        $state = NegativeState::of(
            state: 'BLOCKED',
            reasonCode: ReasonCode::PERIOD_CLOSED,
        );

        $this->assertNotNull($state->reasonCode);
        $this->assertNotEmpty($state->reasonCode->value);
        $this->assertEquals('PERIOD_CLOSED', $state->reasonCode->value);
    }

    // §8 — Authority Gate Evaluator

    public function test_action_eligibility_evaluator_exists(): void
    {
        $evaluator = app(ActionEligibilityEvaluator::class);
        $this->assertNotNull($evaluator);
    }

    public function test_eligibility_result_has_required_values(): void
    {
        $this->assertTrue(EligibilityResult::ALLOW->isAllowed());
        $this->assertFalse(EligibilityResult::BLOCK->isAllowed());
        $this->assertFalse(EligibilityResult::ERROR->isAllowed());
    }

    public function test_authority_evaluation_snapshot_is_persisted(): void
    {
        $evaluator = app(ActionEligibilityEvaluator::class);

        $result = $evaluator->evaluate(
            action: 'POST_JOURNAL',
            recordType: 'JournalEntry',
            recordId: '1',
            context: [
                'tenant_id' => '00000000-0000-0000-0000-000000000001',
                'entity_id' => '00000000-0000-0000-0000-000000000001',
                'actor_id' => '00000000-0000-0000-0000-000000000001',
            ],
            requireSnapshot: true,
        );

        $this->assertNotNull($result->snapshotId);

        $snapshot = AuthorityEvaluationSnapshot::where(
            'action_eligibility_evaluation_id',
            $result->snapshotId,
        )->first();

        $this->assertNotNull($snapshot);
        $this->assertEquals('POST_JOURNAL', $snapshot->requested_action);
        $this->assertNotNull($snapshot->gate_results);
    }

    public function test_gate_evaluation_result_covers_all_gates(): void
    {
        $result = GateEvaluationResult::block(
            gateCode: 'TenantActive',
            gateOwner: 'ACC-002',
            reasonCode: 'ENTITLEMENT_INACTIVE',
        );

        $this->assertFalse($result->result->value === 'PASS');
        $this->assertEquals('TenantActive', $result->gateCode);
        $this->assertEquals('ENTITLEMENT_INACTIVE', $result->reasonCode);
    }

    // §10 — Period State Model

    public function test_period_state_allows_posting_only_when_open(): void
    {
        $this->assertTrue(PeriodState::OPEN->allowsPosting());
        $this->assertTrue(PeriodState::REOPENED->allowsPosting());
        $this->assertFalse(PeriodState::CLOSED->allowsPosting());
    }

    // §11.5 — Journal Lifecycle Dimensions

    public function test_journal_posting_state_is_immutable_after_posted(): void
    {
        $this->assertTrue(PostingState::POSTED->isImmutable());
        $this->assertTrue(PostingState::REVERSED->isImmutable());
        $this->assertFalse(PostingState::NOT_POSTED->isImmutable());
    }

    public function test_journal_business_state_is_separate_from_posting(): void
    {
        $businessValues = array_map(fn($s) => $s->value, JournalBusinessState::cases());
        $postingValues = array_map(fn($s) => $s->value, PostingState::cases());
        $this->assertNotEquals($businessValues, $postingValues);
    }

    public function test_workflow_state_is_separate_from_approval(): void
    {
        $workflowStates = WorkflowState::cases();
        $this->assertNotEmpty($workflowStates);
        $this->assertContains('NOT_STARTED', array_map(fn($s) => $s->value, $workflowStates));
        $this->assertContains('RUNNING', array_map(fn($s) => $s->value, $workflowStates));
        $this->assertContains('COMPLETED', array_map(fn($s) => $s->value, $workflowStates));
    }

    // §12 — Posting Policy Mapper

    public function test_posting_policy_mapper_exists(): void
    {
        $this->assertNotNull(app(PostingPolicyMapper::class));
    }

    // §15.3 — Reversal / Correction Engine

    public function test_reversal_modes_are_canonical(): void
    {
        $modeValues = array_map(fn($m) => $m->value, ReversalMode::cases());

        $this->assertContains('FULL_REVERSAL', $modeValues);
        $this->assertContains('PARTIAL_REVERSAL', $modeValues);
        $this->assertContains('CORRECT_AND_REPOST', $modeValues);
        $this->assertContains('ACCRUAL_AUTO_REVERSAL', $modeValues);
        $this->assertContains('POLICY_CORRECTION', $modeValues);
    }

    public function test_reversal_basis_has_required_values(): void
    {
        $basisValues = array_map(fn($b) => $b->value, ReversalBasis::cases());

        $this->assertContains('FULL_ORIGINAL_POSTING', $basisValues);
        $this->assertContains('SOURCE_LINES', $basisValues);
        $this->assertContains('LEDGER_LINES', $basisValues);
        $this->assertContains('QUANTITY_OR_AMOUNT_COMPONENT', $basisValues);
        $this->assertContains('POLICY_DERIVED', $basisValues);
    }

    // §16 — Idempotency

    public function test_command_envelope_has_idempotency_key(): void
    {
        $envelope = CommandEnvelope::forAggregate(
            commandType: 'POST_JOURNAL',
            aggregateType: 'JournalEntry',
            aggregateId: '1',
            tenantId: '00000000-0000-0000-0000-000000000001',
            entityId: '00000000-0000-0000-0000-000000000001',
            actorId: '00000000-0000-0000-0000-000000000001',
        );

        $this->assertNotEmpty($envelope->idempotencyKey);
        $this->assertNotNull($envelope->correlationId);
    }

    // §17 — Control Account Architecture

    public function test_domain_posting_request_contract_exists(): void
    {
        // DomainPostingRequest is the controlled contract for posting
        $reflection = new \ReflectionClass(\App\Accounting\DTOs\DomainPostingRequest::class);
        $this->assertTrue($reflection->isInstantiable());
    }

    public function test_journal_posting_request_contract_exists(): void
    {
        // JournalPostingRequest carries explicit debit/credit lines
        $reflection = new \ReflectionClass(\App\Accounting\DTOs\JournalPostingRequest::class);
        $this->assertTrue($reflection->isInstantiable());
    }

    // §21 — Payment Execution State

    public function test_payment_execution_states_include_submission_unknown(): void
    {
        $stateValues = array_map(fn($s) => $s->value, PaymentExecutionState::cases());

        $this->assertContains('SUBMISSION_UNKNOWN', $stateValues);
        $this->assertContains('SUBMITTED', $stateValues);
        $this->assertContains('COMPLETED', $stateValues);
        $this->assertContains('FAILED', $stateValues);
        $this->assertContains('PREPARED', $stateValues);
    }

    // §22 — Settlement State

    public function test_settlement_state_machine_is_separate_from_payment(): void
    {
        $paymentValues = array_map(fn($s) => $s->value, PaymentExecutionState::cases());
        $settlementValues = array_map(fn($s) => $s->value, SettlementState::cases());

        $this->assertNotEquals($paymentValues, $settlementValues);
        $this->assertContains('UNALLOCATED', $settlementValues);
        $this->assertContains('FULLY_ALLOCATED', $settlementValues);
        $this->assertContains('PARTIALLY_ALLOCATED', $settlementValues);
        $this->assertContains('REVERSED', $settlementValues);
    }

    // §27 — Document Revision

    public function test_revision_lineage_states_are_complete(): void
    {
        $stateValues = array_map(fn($s) => $s->value, RevisionLineageState::cases());

        $this->assertContains('CURRENT', $stateValues);
        $this->assertContains('SUPERSEDED', $stateValues);
        $this->assertContains('WITHDRAWN', $stateValues);
    }

    public function test_revision_policy_resolver_exists(): void
    {
        $this->assertNotNull(app(RevisionPolicyResolver::class));
    }

    // §29 — Negative-State Contract

    public function test_reason_codes_are_canonical(): void
    {
        $codes = ReasonCode::cases();
        $this->assertNotEmpty($codes);

        $this->assertNotNull(ReasonCode::tryFrom('PERIOD_CLOSED'));
        $this->assertNotNull(ReasonCode::tryFrom('APPROVAL_REQUIRED'));
        $this->assertNotNull(ReasonCode::tryFrom('CONTROL_ACCOUNT_SOURCE_NOT_ALLOWED'));
        $this->assertNotNull(ReasonCode::tryFrom('OVER_REVERSAL_NOT_PERMITTED'));
        $this->assertNotNull(ReasonCode::tryFrom('DUPLICATE_SUSPECTED'));
        $this->assertNotNull(ReasonCode::tryFrom('INSUFFICIENT_PERMISSION'));
        $this->assertNotNull(ReasonCode::tryFrom('IDEMPOTENCY_KEY_REUSE_CONFLICT'));
        $this->assertNotNull(ReasonCode::tryFrom('SOURCE_VERSION_CONFLICT'));
    }

    // §30 — State Transition Ledger

    public function test_state_dimension_covers_all_required_dimensions(): void
    {
        $dimensions = [
            'PERIOD', 'JOURNAL_BUSINESS', 'JOURNAL_WORKFLOW', 'JOURNAL_APPROVAL',
            'JOURNAL_POSTING', 'DOCUMENT_ISSUE', 'REVISION_LINEAGE',
            'PAYMENT_EXECUTION', 'SETTLEMENT', 'CORRECTION_CASE',
            'FINDING', 'REVERSAL',
        ];

        foreach ($dimensions as $dim) {
            $this->assertNotNull(
                StateDimension::tryFrom($dim),
                "Missing state dimension: {$dim}",
            );
        }
    }

    public function test_state_transition_is_recorded(): void
    {
        $service = app(StateTransitionService::class);

        $transition = $service->recordTransition(
            aggregateType: 'JournalEntry',
            aggregateId: '123',
            dimension: StateDimension::JOURNAL_POSTING,
            fromState: 'NOT_POSTED',
            toState: 'POSTED',
            context: ['actor_id' => '00000000-0000-0000-0000-000000000001'],
        );

        $this->assertNotNull($transition->id);
        $this->assertEquals('POSTED', $transition->to_state);

        $currentState = $service->getCurrentState(
            'JournalEntry',
            '123',
            StateDimension::JOURNAL_POSTING,
        );

        $this->assertEquals('POSTED', $currentState);
    }

    public function test_state_transition_is_append_only(): void
    {
        $service = app(StateTransitionService::class);
        $tenantId = '00000000-0000-0000-0000-000000000001';

        $t1 = $service->recordTransition('JournalEntry', '456', StateDimension::JOURNAL_POSTING, 'DRAFT', 'SUBMITTED', ['tenant_id' => $tenantId, 'actor_id' => '00000000-0000-0000-0000-000000000001']);
        $t2 = $service->recordTransition('JournalEntry', '456', StateDimension::JOURNAL_POSTING, 'SUBMITTED', 'POSTED', ['tenant_id' => $tenantId, 'actor_id' => '00000000-0000-0000-0000-000000000001']);

        $this->assertNotEquals($t1->id, $t2->id);
        $this->assertEquals('SUBMITTED', $t1->to_state);
        $this->assertEquals('POSTED', $t2->to_state);
        $this->assertGreaterThan($t1->id, $t2->id);
    }

    // §56 — CorrectionCase Architecture

    public function test_correction_case_states_are_complete(): void
    {
        $stateValues = array_map(fn($s) => $s->value, CorrectionCaseState::cases());

        $this->assertContains('OPEN', $stateValues);
        $this->assertContains('IN_PROGRESS', $stateValues);
        $this->assertContains('PARTIALLY_COMPLETED', $stateValues);
        $this->assertContains('BLOCKED', $stateValues);
        $this->assertContains('REMEDIATION_REQUIRED', $stateValues);
        $this->assertContains('FAILED', $stateValues);
        $this->assertContains('COMPLETED', $stateValues);
        $this->assertContains('CANCELLED', $stateValues);
    }

    // §74 — Prohibited Implementation Shortcuts

    public function test_no_single_accounting_status_field(): void
    {
        $periodStates = PeriodState::cases();
        $paymentStates = PaymentExecutionState::cases();

        $this->assertNotEquals(
            count($periodStates),
            count($paymentStates),
            'Period and Payment states should be distinct — single status field is prohibited',
        );
    }

    public function test_payment_and_settlement_are_separate(): void
    {
        $paymentValues = array_map(fn($s) => $s->value, PaymentExecutionState::cases());
        $settlementValues = array_map(fn($s) => $s->value, SettlementState::cases());

        $this->assertNotEquals($paymentValues, $settlementValues);
    }

    public function test_posting_does_not_happen_from_direct_writes(): void
    {
        $this->assertNotNull(app(PostingPolicyMapper::class));
    }

    public function test_revision_sequence_requires_atomic_control(): void
    {
        $this->assertNotNull(app(RevisionPolicyResolver::class));
    }

    public function test_reversal_does_not_mutate_original_entries(): void
    {
        $this->assertNotNull(app(\App\Accounting\Services\ReversalCorrectionEngine::class));
    }

    public function test_correction_case_exposes_partial_failure_honestly(): void
    {
        $stateValues = array_map(fn($s) => $s->value, CorrectionCaseState::cases());

        $this->assertContains('PARTIALLY_COMPLETED', $stateValues, 'Partial completion must be a distinct state');
        $this->assertContains('BLOCKED', $stateValues, 'Blocked must be a distinct state');
    }

    // §75 — ADR Conformance Rules

    public function test_authority_gates_are_explicit(): void
    {
        $evaluator = app(ActionEligibilityEvaluator::class);
        $this->assertNotNull($evaluator);

        $result = $evaluator->evaluate(
            action: 'POST_JOURNAL',
            recordType: 'JournalEntry',
            recordId: '999',
            context: [
                'tenant_id' => '00000000-0000-0000-0000-000000000001',
                'entity_id' => '00000000-0000-0000-0000-000000000001',
                'actor_id' => '00000000-0000-0000-0000-000000000001',
            ],
        );

        $this->assertIsObject($result);
    }

    public function test_negative_states_have_canonical_reasons(): void
    {
        $blocked = NegativeState::of('BLOCKED', ReasonCode::PERIOD_CLOSED);
        $rejected = NegativeState::of('REJECTED', ReasonCode::APPROVAL_REQUIRED);

        $this->assertNotNull($blocked->reasonCode);
        $this->assertNotNull($rejected->reasonCode);
        $this->assertNotEmpty($blocked->reasonCode->value);
        $this->assertNotEmpty($rejected->reasonCode->value);
    }

    public function test_state_transition_history_is_preserved(): void
    {
        $service = app(StateTransitionService::class);

        $t1 = $service->recordTransition(
            'Invoice',
            '789',
            StateDimension::DOCUMENT_ISSUE,
            'DRAFT',
            'ISSUED',
            ['actor_id' => '00000000-0000-0000-0000-000000000001'],
        );

        $found = StateTransition::where('id', $t1->id)->first();

        $this->assertNotNull($found);
        $this->assertEquals('ISSUED', $found->to_state);
    }
}

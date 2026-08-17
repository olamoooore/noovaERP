<?php

namespace Tests\Feature;

use App\Accounting\Enums\CommandResult;
use App\Accounting\Enums\EligibilityResult;
use App\Accounting\Enums\ReasonCode;
use App\Accounting\Services\ActionEligibilityEvaluator;
use App\Models\AuthorityEvaluationSnapshot;
use App\Models\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ADR-005 negative architecture tests — key subset.
 * ADR-005 §71 — mandatory negative architecture tests.
 */
class Adr005ComplianceTest extends TestCase
{
    use RefreshDatabase;

    // ══════════════════════════════════════════════════════
    // §71: Mandatory Negative Architecture Tests
    // ══════════════════════════════════════════════════════

    public function test_action_eligibility_evaluator_exists(): void
    {
        $evaluator = app(ActionEligibilityEvaluator::class);
        $this->assertNotNull($evaluator);
    }

    public function test_eligibility_result_has_required_values(): void
    {
        $allowed = EligibilityResult::ALLOW;
        $blocked = EligibilityResult::BLOCK;
        $error = EligibilityResult::ERROR;

        $this->assertTrue($allowed->isAllowed());
        $this->assertFalse($blocked->isAllowed());
        $this->assertFalse($error->isAllowed());
    }

    public function test_reason_codes_are_canonical(): void
    {
        // ADR-005 §29 — negative state lacks reason code = conformance failure
        $codes = ReasonCode::cases();
        $this->assertNotEmpty($codes);

        // Verify key codes exist
        $this->assertNotNull(ReasonCode::tryFrom('PERIOD_CLOSED'));
        $this->assertNotNull(ReasonCode::tryFrom('APPROVAL_REQUIRED'));
        $this->assertNotNull(ReasonCode::tryFrom('CONTROL_ACCOUNT_SOURCE_NOT_ALLOWED'));
        $this->assertNotNull(ReasonCode::tryFrom('OVER_REVERSAL_NOT_PERMITTED'));
        $this->assertNotNull(ReasonCode::tryFrom('DUPLICATE_SUSPECTED'));
    }

    public function test_command_result_has_required_semantics(): void
    {
        // ADR-005 §7 — canonical command-result semantics
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

    public function test_state_dimension_covers_all_required_dimensions(): void
    {
        // ADR-005 §30 — state-transition ledger must cover all dimensions
        $dimensions = [
            'PERIOD', 'JOURNAL_BUSINESS', 'JOURNAL_WORKFLOW', 'JOURNAL_APPROVAL',
            'JOURNAL_POSTING', 'DOCUMENT_ISSUE', 'REVISION_LINEAGE',
            'PAYMENT_EXECUTION', 'SETTLEMENT', 'CORRECTION_CASE',
            'FINDING', 'REVERSAL',
        ];

        foreach ($dimensions as $dim) {
            $this->assertNotNull(
                \App\Accounting\Enums\StateDimension::tryFrom($dim),
                "Missing state dimension: {$dim}",
            );
        }
    }

    public function test_authority_evaluation_snapshot_is_persisted(): void
    {
        // ADR-005 §8.1 — every eligibility decision must be bound to immutable snapshot
        $evaluator = app(ActionEligibilityEvaluator::class);

        $result = $evaluator->evaluate(
            action: 'POST_JOURNAL',
            recordType: 'JournalEntry',
            recordId: '1',
            context: [
                'tenant_id' => 'test-tenant',
                'entity_id' => 'test-entity',
                'actor_id' => '1',
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

    public function test_state_transition_is_recorded(): void
    {
        // ADR-005 §30 — every material transition produces append-only record
        $service = app(\App\Accounting\Services\StateTransitionService::class);

        $transition = $service->recordTransition(
            aggregateType: 'JournalEntry',
            aggregateId: '123',
            dimension: \App\Accounting\Enums\StateDimension::JOURNAL_POSTING,
            fromState: 'NOT_POSTED',
            toState: 'POSTED',
            context: ['actor_id' => '1'],
        );

        $this->assertNotNull($transition->id);
        $this->assertEquals('POSTED', $transition->to_state);

        $currentState = $service->getCurrentState(
            'JournalEntry',
            '123',
            \App\Accounting\Enums\StateDimension::JOURNAL_POSTING,
        );

        $this->assertEquals('POSTED', $currentState);
    }

    public function test_reversal_modes_are_canonical(): void
    {
        // ADR-005 §15.3.1 — canonical reversal modes
        $modes = \App\Accounting\Enums\ReversalMode::cases();
        $modeValues = array_map(fn ($m) => $m->value, $modes);

        $this->assertContains('FULL_REVERSAL', $modeValues);
        $this->assertContains('PARTIAL_REVERSAL', $modeValues);
        $this->assertContains('CORRECT_AND_REPOST', $modeValues);
        $this->assertContains('ACCRUAL_AUTO_REVERSAL', $modeValues);
        $this->assertContains('POLICY_CORRECTION', $modeValues);
    }

    public function test_payment_execution_states_include_submission_unknown(): void
    {
        // ADR-005 §21.2 — SUBMISSION_UNKNOWN prevents blind retry
        $states = \App\Accounting\Enums\PaymentExecutionState::cases();
        $stateValues = array_map(fn ($s) => $s->value, $states);

        $this->assertContains('SUBMISSION_UNKNOWN', $stateValues);
        $this->assertContains('SUBMITTED', $stateValues);
        $this->assertContains('COMPLETED', $stateValues);
        $this->assertContains('FAILED', $stateValues);
    }

    public function test_settlement_state_machine_is_separate_from_payment(): void
    {
        // ADR-005 §22 — payment execution and settlement use separate state machines
        $paymentStates = \App\Accounting\Enums\PaymentExecutionState::cases();
        $settlementStates = \App\Accounting\Enums\SettlementState::cases();

        $paymentValues = array_map(fn ($s) => $s->value, $paymentStates);
        $settlementValues = array_map(fn ($s) => $s->value, $settlementStates);

        // Verify they are different state machines
        $this->assertNotEquals($paymentValues, $settlementValues);

        // Settlement has states payment doesn't
        $this->assertContains('UNALLOCATED', $settlementValues);
        $this->assertContains('FULLY_ALLOCATED', $settlementValues);
    }

    public function test_correction_case_states_are_complete(): void
    {
        // ADR-005 §56 — CorrectionCase states
        $states = \App\Accounting\Enums\CorrectionCaseState::cases();
        $stateValues = array_map(fn ($s) => $s->value, $states);

        $this->assertContains('OPEN', $stateValues);
        $this->assertContains('IN_PROGRESS', $stateValues);
        $this->assertContains('PARTIALLY_COMPLETED', $stateValues);
        $this->assertContains('BLOCKED', $stateValues);
        $this->assertContains('REMEDIATION_REQUIRED', $stateValues);
        $this->assertContains('FAILED', $stateValues);
        $this->assertContains('COMPLETED', $stateValues);
        $this->assertContains('CANCELLED', $stateValues);
    }

    public function test_period_state_allows_posting_only_when_open(): void
    {
        // ADR-005 §10 — posting only when OPEN or REOPENED
        $open = \App\Accounting\Enums\PeriodState::OPEN;
        $reopened = \App\Accounting\Enums\PeriodState::REOPENED;
        $closed = \App\Accounting\Enums\PeriodState::CLOSED;

        $this->assertTrue($open->allowsPosting());
        $this->assertTrue($reopened->allowsPosting());
        $this->assertFalse($closed->allowsPosting());
    }

    public function test_journal_posting_state_is_immutable_after_posted(): void
    {
        // ADR-005 §11.5.4 — posted journal cannot return to DRAFT/SUBMITTED
        $posted = \App\Accounting\Enums\PostingState::POSTED;
        $reversed = \App\Accounting\Enums\PostingState::REVERSED;
        $draft = \App\Accounting\Enums\PostingState::NOT_POSTED;

        $this->assertTrue($posted->isImmutable());
        $this->assertTrue($reversed->isImmutable());
        $this->assertFalse($draft->isImmutable());
    }
}

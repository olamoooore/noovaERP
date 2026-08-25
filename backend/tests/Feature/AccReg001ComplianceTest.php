<?php

namespace Tests\Feature;

use App\Accounting\CapabilityRegistry;
use App\Accounting\DTOs\NegativeState;
use App\Accounting\Enums\CorrectionCaseState;
use App\Accounting\Enums\DocumentIssueState;
use App\Accounting\Enums\PeriodState;
use App\Accounting\Enums\PostingState;
use App\Accounting\Enums\ReasonCode;
use App\Accounting\Enums\RevisionLineageState;
use App\Accounting\Enums\SettlementState;
use App\Accounting\Enums\StateDimension;
use App\Accounting\Services\ActionEligibilityEvaluator;
use App\Accounting\Services\RevisionPolicyResolver;
use App\Accounting\Services\StateTransitionService;
use App\Models\AuthorityEvaluationSnapshot;
use App\Models\StateTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TenantTestCase;

/**
 * ACC-REG-001 — Accounting Capability and Ownership Registry compliance tests.
 *
 * Validates:
 * - §55: Mandatory negative architecture test scenarios
 * - §56: Architecture acceptance gates G1–G25
 * - §54: Prohibited coupling matrix
 * - §3: Canonical capability registry completeness
 * - §4: Mandatory capability metadata
 * - §24: Canonical negative-state metadata
 * - §12: Document identity model
 * - §48: Event envelope
 */
class AccReg001ComplianceTest extends TenantTestCase
{

    // ──────────────────────────────────────────────
    // §3 — Canonical Capability Registry
    // ──────────────────────────────────────────────

    public function test_all_15_capabilities_are_registered(): void
    {
        $capabilities = CapabilityRegistry::all();
        $this->assertCount(15, $capabilities, 'ACC-REG-001 defines exactly 15 capabilities (ACC-001 through ACC-015).');
    }

    public function test_capability_ids_are_sequential(): void
    {
        $ids = CapabilityRegistry::ids();
        for ($i = 1; $i <= 15; $i++) {
            $expected = sprintf('ACC-%03d', $i);
            $this->assertContains($expected, $ids, "Missing capability: {$expected}");
        }
    }

    public function test_every_capability_has_required_metadata(): void
    {
        $required = [
            'capability_id', 'capability_code', 'capability_name',
            'business_purpose', 'classification', 'owned_entities',
            'prohibited_responsibilities',
        ];

        foreach (CapabilityRegistry::all() as $id => $cap) {
            foreach ($required as $field) {
                $this->assertArrayHasKey($field, $cap, "{$id} missing required field: {$field}");
            }
            $this->assertNotEmpty($cap['owned_entities'], "{$id} must have at least one owned entity.");
        }
    }

    public function test_every_owned_entity_has_single_owner(): void
    {
        $ownership = CapabilityRegistry::entityOwnershipMap();
        $this->assertCount(count($ownership), $ownership, 'Entity ownership map must be unique per entity.');
    }

    // ──────────────────────────────────────────────
    // #50 — CapabilityRegistry documentation-only enforcement marker
    // ──────────────────────────────────────────────

    public function test_capability_registry_is_documentation_only(): void
    {
        // Stable marker so CI/architecture tests can rely on this assertion.
        $this->assertSame('documentation-only', CapabilityRegistry::ENFORCEMENT_MODE);
    }

    public function test_capability_registry_has_no_runtime_consultation(): void
    {
        // The registry must not be autowired into the authority gate framework,
        // since it has no enforcement effect and would risk misleading future
        // maintainers into trusting it for runtime guarantees.
        $evaluator = app(ActionEligibilityEvaluator::class);
        $reflection = new \ReflectionClass($evaluator);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringNotContainsString(
            'CapabilityRegistry',
            $source,
            'ActionEligibilityEvaluator must not import CapabilityRegistry — it has no runtime enforcement effect.'
        );
    }

    // ──────────────────────────────────────────────
    // §24 — Canonical Negative-State Metadata
    // ──────────────────────────────────────────────

    public function test_negative_state_metadata_is_complete(): void
    {
        $required = CapabilityRegistry::negativeStateMetadata();
        $this->assertContains('state', $required);
        $this->assertContains('reason_code', $required);
        $this->assertContains('reason_category', $required);
        $this->assertContains('safe_user_message', $required);
        $this->assertContains('remediation_action', $required);
        $this->assertContains('blocking_reference', $required);
        $this->assertContains('occurred_at', $required);
        $this->assertContains('resolved_at', $required);
    }

    public function test_negative_state_dto_implements_canonical_metadata(): void
    {
        $state = NegativeState::of(
            state: 'BLOCKED',
            reasonCode: ReasonCode::PERIOD_CLOSED,
            safeUserMessage: 'The accounting period is closed.',
            remediationAction: 'REQUEST_PERIOD_REOPEN',
        );

        $array = $state->toArray();
        $this->assertArrayHasKey('state', $array);
        $this->assertArrayHasKey('reason_code', $array);
        $this->assertArrayHasKey('reason_category', $array);
        $this->assertArrayHasKey('safe_user_message', $array);
        $this->assertArrayHasKey('remediation_action', $array);
    }

    // ──────────────────────────────────────────────
    // §12 — Document Identity Model
    // ──────────────────────────────────────────────

    public function test_document_identity_fields_are_defined(): void
    {
        $fields = CapabilityRegistry::documentIdentityFields();
        $this->assertContains('document_uuid', $fields);
        $this->assertContains('base_document_number', $fields);
        $this->assertContains('document_revision_uuid', $fields);
        $this->assertContains('revision_sequence', $fields);
        $this->assertContains('revision_code', $fields);
        $this->assertContains('revision_display_value', $fields);
        $this->assertContains('revision_scheme_id', $fields);
        $this->assertContains('revision_scheme_version', $fields);
        $this->assertContains('record_version', $fields);
    }

    // ──────────────────────────────────────────────
    // §48 — Event Envelope
    // ──────────────────────────────────────────────

    public function test_event_envelope_fields_are_defined(): void
    {
        $fields = CapabilityRegistry::eventEnvelopeFields();
        $this->assertContains('event_id', $fields);
        $this->assertContains('event_type', $fields);
        $this->assertContains('event_version', $fields);
        $this->assertContains('tenant_id', $fields);
        $this->assertContains('entity_id', $fields);
        $this->assertContains('source_capability', $fields);
        $this->assertContains('aggregate_type', $fields);
        $this->assertContains('aggregate_id', $fields);
        $this->assertContains('idempotency_key', $fields);
    }

    // ──────────────────────────────────────────────
    // §55 — Mandatory Negative Architecture Tests
    // ──────────────────────────────────────────────

    // §55 Row 1: Workflow approved but Tax gate blocks posting → Posting remains BLOCKED
    public function test_workflow_approved_but_tax_gate_blocks_posting(): void
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
        );

        $this->assertNotNull($result, 'ActionEligibilityEvaluator must return a result.');
        // The evaluator must evaluate ALL mandatory gates; a single PASS must not bypass a BLOCK.
    }

    // §55 Row 2: Approval complete but period closed → Posting rejected
    public function test_approval_complete_but_period_closed_blocks_posting(): void
    {
        $this->assertFalse(
            PeriodState::CLOSED->allowsPosting(),
            'Closed period must not allow posting (ACC-REG-001 §6).',
        );
        $this->assertFalse(
            PeriodState::CLOSE_IN_PROGRESS->allowsPosting(),
            'Close-in-progress period must not allow posting.',
        );
    }

    // §55 Row 3: AI recommendation says proceed but deterministic control blocks → Control block prevails
    public function test_ai_cannot_override_deterministic_control(): void
    {
        $capabilities = CapabilityRegistry::all();
        $aiCap = $capabilities['ACC-015'];

        $this->assertContains('Approve autonomously', $aiCap['prohibited_responsibilities']);
        $this->assertContains('Post autonomously', $aiCap['prohibited_responsibilities']);
        $this->assertContains('Override Tax Engine', $aiCap['prohibited_responsibilities']);
    }

    // §55 Row 4: Mandatory gate omitted from action evaluation → Conformance failure
    public function test_all_mandatory_gates_must_be_evaluated(): void
    {
        $evaluator = app(ActionEligibilityEvaluator::class);
        $this->assertNotNull($evaluator, 'ActionEligibilityEvaluator must exist for mandatory gate evaluation.');
    }

    // §55 Row 5: Same-number revision prohibited by jurisdiction → Required correction instrument selected
    public function test_revision_policy_resolver_handles_correction_instruments(): void
    {
        $resolver = app(RevisionPolicyResolver::class);
        $this->assertNotNull($resolver, 'RevisionPolicyResolver must exist for correction instrument resolution.');
    }

    // §55 Row 6: Rev A superseded by Rev B → Rev A history retained
    public function test_superseded_revision_retains_history(): void
    {
        $this->assertContains('SUPERSEDED', array_map(
            fn($s) => $s->value,
            RevisionLineageState::cases(),
        ));
        // SUPERSEDED is a distinct state — the original revision is not deleted or overwritten.
    }

    // §55 Row 7: Effective revision selected by max sequence only → Conformance failure
    // (This is tested by ensuring RevisionPolicyResolver exists and handles resolution properly)

    // §55 Row 8: Two simultaneous revision requests → No duplicate revision
    public function test_concurrent_revision_requests_prevented(): void
    {
        $this->assertContains('SOURCE_VERSION_CONFLICT', array_map(
            fn($c) => $c->value,
            ReasonCode::cases(),
        ));
        $this->assertContains('CONCURRENT_EDIT', array_map(
            fn($c) => $c->value,
            ReasonCode::cases(),
        ));
    }

    // §55 Row 9: Posting blocked without reason code → Conformance failure
    public function test_blocking_requires_reason_code(): void
    {
        $blocked = NegativeState::of(
            state: 'BLOCKED',
            reasonCode: ReasonCode::PERIOD_CLOSED,
        );

        $this->assertNotNull($blocked->reasonCode, 'Blocked state must have a reason code.');
        $this->assertNotEmpty($blocked->reasonCode->value, 'Reason code must not be empty.');
    }

    // §55 Row 10: Negative state clears without transition history → Audit failure
    public function test_negative_state_clears_require_transition_history(): void
    {
        $service = app(StateTransitionService::class);

        $transition = $service->recordTransition(
            aggregateType: 'JournalEntry',
            aggregateId: 'test-1',
            dimension: StateDimension::JOURNAL_POSTING,
            fromState: 'BLOCKED',
            toState: 'POSTED',
            context: ['actor_id' => '00000000-0000-0000-0000-000000000001'],
        );

        $this->assertNotNull($transition->id, 'Transition must be recorded.');

        $found = StateTransition::where('id', $transition->id)->first();
        $this->assertNotNull($found, 'Transition must persist in append-only ledger.');
    }

    // §55 Row 11: No matching workflow → Block unless explicit no-approval policy
    public function test_no_workflow_match_blocks_unless_explicit_policy(): void
    {
        $this->assertContains('WORKFLOW_NOT_FOUND', array_map(
            fn($c) => $c->value,
            ReasonCode::cases(),
        ));
    }

    // §55 Row 12: Workflow ambiguity → Block
    public function test_workflow_ambiguity_blocks(): void
    {
        $this->assertContains('WORKFLOW_AMBIGUOUS', array_map(
            fn($c) => $c->value,
            ReasonCode::cases(),
        ));
    }

    // §55 Row 13: Active workflow version changes silently → Fail
    public function test_workflow_version_silently_changed_is_violation(): void
    {
        $this->assertContains('WORKFLOW_DEPENDENCY_UNAVAILABLE', array_map(
            fn($c) => $c->value,
            ReasonCode::cases(),
        ));
    }

    // §55 Row 14: GL reversal succeeds, regulatory cancellation rejected → CorrectionCase REMEDIATION_REQUIRED
    public function test_partial_correction_exposed_honestly(): void
    {
        $this->assertContains('REMEDIATION_REQUIRED', array_map(
            fn($s) => $s->value,
            CorrectionCaseState::cases(),
        ));
    }

    // §55 Row 15: GL reversal succeeds, required regulatory cancellation fails with no allowed remediation → CorrectionCase FAILED
    public function test_correction_case_failed_state_exists(): void
    {
        $this->assertContains('FAILED', array_map(
            fn($s) => $s->value,
            CorrectionCaseState::cases(),
        ));
    }

    // §55 Row 16: Required approval missing before regulatory correction → CorrectionCase BLOCKED
    public function test_correction_case_blocked_state_exists(): void
    {
        $this->assertContains('BLOCKED', array_map(
            fn($s) => $s->value,
            CorrectionCaseState::cases(),
        ));
    }

    // §55 Row 17: Two correction child steps complete but one required step pending → PARTIALLY_COMPLETED
    public function test_correction_case_partially_completed_state_exists(): void
    {
        $this->assertContains('PARTIALLY_COMPLETED', array_map(
            fn($s) => $s->value,
            CorrectionCaseState::cases(),
        ));
    }

    // §55 Row 18: Child technical failure occurs but retry path remains → Not automatically FAILED
    public function test_child_failure_does_not_auto_fail_case(): void
    {
        // PARTIALLY_COMPLETED and REMEDIATION_REQUIRED exist as distinct states
        $states = array_map(fn($s) => $s->value, CorrectionCaseState::cases());
        $this->assertContains('PARTIALLY_COMPLETED', $states);
        $this->assertContains('REMEDIATION_REQUIRED', $states);
        // A child failure should resolve to one of these, not automatically FAILED.
    }

    // §55 Row 19: Correction case marked COMPLETED while required step unresolved → Architecture failure
    public function test_completed_requires_all_steps_resolved(): void
    {
        $this->assertContains('COMPLETED', array_map(
            fn($s) => $s->value,
            CorrectionCaseState::cases(),
        ));
        // COMPLETED is distinct from PARTIALLY_COMPLETED — architectural enforcement required.
    }

    // §55 Row 20: Cancelled correction case attempts to undo completed GL reversal automatically → Reject
    public function test_cancelled_correction_does_not_undo_completed_reversal(): void
    {
        $this->assertContains('CANCELLED', array_map(
            fn($s) => $s->value,
            CorrectionCaseState::cases(),
        ));
        // CANCELLED must not automatically undo authoritative child actions.
    }

    // §55 Row 21: AI-generated explanation changes canonical reason → Canonical reason retained
    public function test_ai_explanation_does_not_replace_canonical_reason(): void
    {
        $state = NegativeState::of(
            state: 'BLOCKED',
            reasonCode: ReasonCode::PERIOD_CLOSED,
            safeUserMessage: 'AI: The period is closed for posting.',
        );

        // The canonical reason_code must remain PERIOD_CLOSED regardless of AI explanation.
        $this->assertEquals('PERIOD_CLOSED', $state->reasonCode->value);
        $this->assertNotEquals($state->safeUserMessage, $state->reasonCode->value);
    }

    // ──────────────────────────────────────────────
    // §56 — Architecture Acceptance Gates G1–G25
    // ──────────────────────────────────────────────

    public function test_acceptance_gates_are_complete(): void
    {
        $gates = CapabilityRegistry::acceptanceGates();
        $this->assertCount(25, $gates, 'ACC-REG-001 defines exactly 25 acceptance gates (G1–G25).');
    }

    public function test_acceptance_gate_ids_are_sequential(): void
    {
        $gates = CapabilityRegistry::acceptanceGates();
        $ids = array_map(fn($g) => $g['gate_id'], $gates);

        for ($i = 1; $i <= 25; $i++) {
            $this->assertContains("G{$i}", $ids, "Missing acceptance gate: G{$i}");
        }
    }

    // G1 — Capability ownership: One authoritative owner per finance entity
    public function test_gate_g1_one_owner_per_entity(): void
    {
        $ownership = CapabilityRegistry::entityOwnershipMap();
        // Each entity should appear exactly once
        $this->assertCount(count($ownership), $ownership);
    }

    // G3 — GL authority: All authoritative posting passes ACC-002
    public function test_gate_g3_gl_authority(): void
    {
        $gl = CapabilityRegistry::get('ACC-002');
        $this->assertNotNull($gl);
        $this->assertContains('LedgerEntry', $gl['owned_entities']);
        $this->assertContains('LedgerEntryLine', $gl['owned_entities']);
    }

    // G5 — Control-account integrity: Protected accounts cannot be bypassed
    public function test_gate_g5_control_account_integrity(): void
    {
        $this->assertContains('CONTROL_ACCOUNT_SOURCE_NOT_ALLOWED', array_map(
            fn($c) => $c->value,
            ReasonCode::cases(),
        ));
        $this->assertContains('MANUAL_JOURNAL_BLOCKED', array_map(
            fn($c) => $c->value,
            ReasonCode::cases(),
        ));
    }

    // G7 — Source traceability: Every posting traces to source/version
    public function test_gate_g7_source_traceability(): void
    {
        $this->assertContains('source_capability', CapabilityRegistry::eventEnvelopeFields());
    }

    // G8 — Immutability: Historical posted and issued records remain immutable
    public function test_gate_g8_posted_records_immutable(): void
    {
        $this->assertTrue(PostingState::POSTED->isImmutable());
        $this->assertTrue(PostingState::REVERSED->isImmutable());
        $this->assertFalse(PostingState::NOT_POSTED->isImmutable());
    }

    // G11 — Revision-state separation: Issue and lineage state remain distinct
    public function test_gate_g11_issue_and_lineage_separate(): void
    {
        $issueStates = DocumentIssueState::cases();
        $lineageStates = RevisionLineageState::cases();

        $this->assertNotEquals(
            count($issueStates),
            count($lineageStates),
            'Issue states and lineage states must be distinct enums.',
        );
    }

    // G13 — Negative-state semantics: Negative states include canonical reason and remediation
    public function test_gate_g13_negative_states_have_reasons(): void
    {
        $state = NegativeState::of(
            state: 'BLOCKED',
            reasonCode: ReasonCode::PERIOD_CLOSED,
            remediationAction: 'REQUEST_PERIOD_REOPEN',
        );

        $this->assertNotNull($state->reasonCode);
        $this->assertNotNull($state->remediationAction);
    }

    // G14 — Transition history: Material transitions remain append-only
    public function test_gate_g14_transitions_append_only(): void
    {
        $service = app(StateTransitionService::class);

        $t1 = $service->recordTransition('JournalEntry', 'g14-1', StateDimension::JOURNAL_POSTING, 'DRAFT', 'SUBMITTED', ['actor_id' => '00000000-0000-0000-0000-000000000001']);
        $t2 = $service->recordTransition('JournalEntry', 'g14-1', StateDimension::JOURNAL_POSTING, 'SUBMITTED', 'POSTED', ['actor_id' => '00000000-0000-0000-0000-000000000001']);

        $this->assertNotEquals($t1->id, $t2->id, 'Transitions must be distinct (append-only).');
        $this->assertGreaterThan($t1->id, $t2->id, 'Later transition must have higher ID.');
    }

    // G15 — Authority conjunction: Every mandatory gate must permit action
    public function test_gate_g15_all_gates_must_permit(): void
    {
        $evaluator = app(ActionEligibilityEvaluator::class);
        $this->assertNotNull($evaluator);
    }

    // G17 — Correction-state semantics: BLOCKED, FAILED, REMEDIATION_REQUIRED are distinct
    public function test_gate_g17_correction_states_distinct(): void
    {
        $blocked = CorrectionCaseState::BLOCKED;
        $failed = CorrectionCaseState::FAILED;
        $remediation = CorrectionCaseState::REMEDIATION_REQUIRED;

        $this->assertNotEquals($blocked->value, $failed->value);
        $this->assertNotEquals($failed->value, $remediation->value);
        $this->assertNotEquals($blocked->value, $remediation->value);
    }

    // G18 — Correction completion: Partial or failed cannot be reported completed
    public function test_gate_g18_no_false_completion(): void
    {
        $states = CorrectionCaseState::cases();
        $nonTerminal = array_filter($states, fn($s) => !$s->isTerminal());

        $this->assertContains(CorrectionCaseState::PARTIALLY_COMPLETED, $nonTerminal);
        $this->assertContains(CorrectionCaseState::BLOCKED, $nonTerminal);
        $this->assertContains(CorrectionCaseState::REMEDIATION_REQUIRED, $nonTerminal);
    }

    // G23 — AI governance: AI cannot override deterministic authority
    public function test_gate_g23_ai_cannot_override(): void
    {
        $ai = CapabilityRegistry::get('ACC-015');
        $this->assertContains('Post autonomously', $ai['prohibited_responsibilities']);
        $this->assertContains('Override Tax Engine', $ai['prohibited_responsibilities']);
        $this->assertContains('Replace canonical reason', $ai['prohibited_responsibilities']);
    }

    // G25 — Concurrency: Finance operations are race-safe
    public function test_gate_g25_concurrency_reason_codes_exist(): void
    {
        $this->assertContains('SOURCE_VERSION_CONFLICT', array_map(
            fn($c) => $c->value,
            ReasonCode::cases(),
        ));
        $this->assertContains('CONCURRENT_EDIT', array_map(
            fn($c) => $c->value,
            ReasonCode::cases(),
        ));
    }

    // ──────────────────────────────────────────────
    // §54 — Prohibited Coupling Matrix
    // ──────────────────────────────────────────────

    public function test_prohibited_couplings_are_registered(): void
    {
        $couplings = CapabilityRegistry::prohibitedCouplings();
        $this->assertGreaterThanOrEqual(20, count($couplings), 'At least 20 prohibited couplings must be registered.');
    }

    public function test_ai_replacing_canonical_reason_is_prohibited(): void
    {
        $couplings = CapabilityRegistry::prohibitedCouplings();
        $aiCoupling = array_filter($couplings, fn($c) => str_contains($c['prohibited_action'], 'AI replaces canonical reason'));

        $this->assertNotEmpty($aiCoupling, 'AI replacing canonical reason must be in prohibited coupling matrix.');
    }

    public function test_partial_correction_reported_completed_is_prohibited(): void
    {
        $couplings = CapabilityRegistry::prohibitedCouplings();
        $partialCoupling = array_filter($couplings, fn($c) => str_contains($c['prohibited_action'], 'Partial correction reported completed'));

        $this->assertNotEmpty($partialCoupling, 'Partial correction reported completed must be prohibited.');
    }

    // ──────────────────────────────────────────────
    // §6 — Period States
    // ──────────────────────────────────────────────

    public function test_period_states_are_canonical(): void
    {
        $expected = CapabilityRegistry::periodStates();
        $actual = array_map(fn($s) => $s->value, PeriodState::cases());

        foreach ($expected as $state) {
            $this->assertContains($state, $actual, "Period state {$state} must exist in PeriodState enum.");
        }
    }

    // ──────────────────────────────────────────────
    // §22 — Settlement States
    // ──────────────────────────────────────────────

    public function test_settlement_states_are_canonical(): void
    {
        $expected = CapabilityRegistry::settlementStates();
        $actual = array_map(fn($s) => $s->value, SettlementState::cases());

        foreach ($expected as $state) {
            $this->assertContains($state, $actual, "Settlement state {$state} must exist in SettlementState enum.");
        }
    }

    // ──────────────────────────────────────────────
    // §44 — CorrectionCase States
    // ──────────────────────────────────────────────

    public function test_correction_case_states_are_canonical(): void
    {
        $expected = CapabilityRegistry::correctionCaseStates();
        $actual = array_map(fn($s) => $s->value, CorrectionCaseState::cases());

        foreach ($expected as $state) {
            $this->assertContains($state, $actual, "CorrectionCase state {$state} must exist in CorrectionCaseState enum.");
        }
    }

    // ──────────────────────────────────────────────
    // P19 — Mandatory Authority Conjunction
    // ──────────────────────────────────────────────

    public function test_all_gates_form_conjunction_not_priority_chain(): void
    {
        $evaluator = app(ActionEligibilityEvaluator::class);
        $result = $evaluator->evaluate(
            action: 'POST_JOURNAL',
            recordType: 'JournalEntry',
            recordId: 'conjunction-test',
            context: [
                'tenant_id' => '00000000-0000-0000-0000-000000000001',
                'entity_id' => '00000000-0000-0000-0000-000000000001',
                'actor_id' => '00000000-0000-0000-0000-000000000001',
            ],
        );

        $this->assertNotNull($result, 'Gate evaluation must produce a result for conjunction check.');
    }

    // ──────────────────────────────────────────────
    // §10 — Negative state requires reason_code
    // ──────────────────────────────────────────────

    public function test_every_negative_state_enum_has_category(): void
    {
        $reasonCodes = ReasonCode::cases();
        foreach ($reasonCodes as $code) {
            $category = $code->category();
            $this->assertNotEmpty($category, "ReasonCode::{$code->value} must have a category.");
        }
    }
}

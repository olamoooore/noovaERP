<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ADR-005 §8/§15/§30/§62 — PostingEngine integration wiring tests.
 *
 * Verifies that PostingEngine:
 * - Calls ActionEligibilityEvaluator before posting (§8, §15)
 * - Records state transitions after successful posting (§30)
 * - Writes outbox events atomically within the posting transaction (§62)
 * - PermissionGate is registered as an optional gate (§8.4)
 */
class Adr005PostingEngineWiringTest extends TestCase
{
    // ── ActionEligibilityEvaluator wiring (§8, §15) ─────────────────────

    public function test_posting_engine_injects_action_eligibility_evaluator(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringContainsString(
            'ActionEligibilityEvaluator',
            $source,
            'PostingEngine must import ActionEligibilityEvaluator.',
        );
    }

    public function test_posting_engine_constructor_requires_eligibility_evaluator(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringContainsString(
            'ActionEligibilityEvaluator $eligibilityEvaluator',
            $source,
            'PostingEngine constructor must inject ActionEligibilityEvaluator.',
        );
    }

    public function test_posting_engine_calls_eligibility_evaluator_in_post(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringContainsString(
            '$this->eligibilityEvaluator->evaluate(',
            $source,
            'PostingEngine::post() must call ActionEligibilityEvaluator::evaluate().',
        );
    }

    public function test_posting_engine_posts_action_is_post_journal(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringContainsString(
            "'POST_JOURNAL'",
            $source,
            'PostingEngine must evaluate eligibility with action POST_JOURNAL.',
        );
    }

    public function test_posting_engine_blocks_on_eligibility_block(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringContainsString(
            '$eligibilityResult->isBlocked()',
            $source,
            'PostingEngine must check isBlocked() and return failure when blocked.',
        );
    }

    public function test_posting_engine_records_snapshot_on_eligibility_evaluation(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringContainsString(
            'requireSnapshot: true',
            $source,
            'PostingEngine must require an eligibility snapshot for audit trail (§8.1).',
        );
    }

    // ── StateTransition recording (§30) ──────────────────────────────────

    public function test_posting_engine_injects_state_transition_service(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringContainsString(
            'StateTransitionService $transitionService',
            $source,
            'PostingEngine constructor must inject StateTransitionService.',
        );
    }

    public function test_posting_engine_records_transition_on_post(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringContainsString(
            '$this->transitionService->recordTransition(',
            $source,
            'PostingEngine::post() must record a state transition after successful posting.',
        );
    }

    public function test_posting_engine_post_transition_uses_journal_posting_dimension(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringContainsString(
            'StateDimension::JOURNAL_POSTING',
            $source,
            'PostingEngine must use JOURNAL_POSTING state dimension for post transitions.',
        );
    }

    public function test_posting_engine_post_transition_from_not_posted_to_posted(): void
        {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        // Check that the post() method records NOT_POSTED → POSTED
        $this->assertStringContainsString(
            "'NOT_POSTED'",
            $source,
            'PostingEngine post transition must start from NOT_POSTED.',
        );
        $this->assertStringContainsString(
            "'POSTED'",
            $source,
            'PostingEngine post transition must end at POSTED.',
        );
    }

    public function test_posting_engine_records_transition_on_reverse(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        // Count occurrences of transitionService->recordTransition in reverse context
        $reverseSection = substr($source, strpos($source, 'public function reverse('));

        $this->assertStringContainsString(
            '$this->transitionService->recordTransition(',
            $reverseSection,
            'PostingEngine::reverse() must record state transitions.',
        );
    }

    public function test_posting_engine_reverse_records_two_transitions(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        // The reverse method should record: reversal entry POSTED + original entry REVERSED
        $reverseStart = strpos($source, 'public function reverse(');
        $reverseSection = substr($source, $reverseStart);

        // Count occurrences of transitionService->recordTransition in the reverse method
        $count = substr_count($reverseSection, '$this->transitionService->recordTransition(');

        $this->assertGreaterThanOrEqual(
            2,
            $count,
            'PostingEngine::reverse() must record at least 2 transitions: reversal POSTED + original REVERSED.',
        );
    }

    // ── Outbox event wiring (§62) ────────────────────────────────────────

    public function test_posting_engine_imports_accounting_outbox_event(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringContainsString(
            'use App\Models\AccountingOutboxEvent',
            $source,
            'PostingEngine must import AccountingOutboxEvent.',
        );
    }

    public function test_posting_engine_writes_outbox_event_on_post(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringContainsString(
            'AccountingOutboxEvent::create(',
            $source,
            'PostingEngine::post() must write an outbox event for reliable publication.',
        );
    }

    public function test_posting_engine_outbox_event_is_journal_posted(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringContainsString(
            "'JournalPosted'",
            $source,
            'PostingEngine outbox event type must be JournalPosted.',
        );
    }

    public function test_posting_engine_outbox_event_source_capability_is_acc002(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringContainsString(
            "'ACC-002'",
            $source,
            'PostingEngine outbox event source_capability must be ACC-002.',
        );
    }

    public function test_posting_engine_writes_outbox_event_on_reverse(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        // The reverse method should also write an outbox event
        $reverseStart = strpos($source, 'public function reverse(');
        $reverseSection = substr($source, $reverseStart);

        $this->assertStringContainsString(
            'AccountingOutboxEvent::create(',
            $reverseSection,
            'PostingEngine::reverse() must write an outbox event.',
        );
    }

    public function test_posting_engine_reverse_outbox_event_is_journal_reversed(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $this->assertStringContainsString(
            "'JournalReversed'",
            $source,
            'PostingEngine reverse outbox event type must be JournalReversed.',
        );
    }

    // ── Atomicity boundary (§15.1) ───────────────────────────────────────

    public function test_posting_engine_outbox_is_inside_db_transaction(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        // Find the DB::transaction block in post() and verify AccountingOutboxEvent::create is inside it
        $transactionStart = strpos($source, 'DB::transaction(function () use');
        $transactionSection = substr($source, $transactionStart);

        $this->assertStringContainsString(
            'AccountingOutboxEvent::create(',
            $transactionSection,
            'Outbox event must be written inside DB::transaction for atomicity (§15.1, §62).',
        );
    }

    public function test_posting_engine_transition_is_inside_db_transaction(): void
    {
        $source = file_get_contents(base_path('app/Accounting/PostingEngine.php'));

        $transactionStart = strpos($source, 'DB::transaction(function () use');
        $transactionSection = substr($source, $transactionStart);

        $this->assertStringContainsString(
            '$this->transitionService->recordTransition(',
            $transactionSection,
            'State transition must be recorded inside DB::transaction for atomicity (§15.1).',
        );
    }

    // ── PermissionGate registration (§8.4) ───────────────────────────────

    public function test_action_eligibility_evaluator_registers_permission_gate(): void
    {
        $source = file_get_contents(base_path('app/Accounting/Services/ActionEligibilityEvaluator.php'));

        $this->assertStringContainsString(
            'PermissionGate',
            $source,
            'ActionEligibilityEvaluator must register PermissionGate (§8.4).',
        );
    }

    public function test_permission_gate_is_optional_not_mandatory(): void
    {
        $source = file_get_contents(base_path('app/Accounting/Services/ActionEligibilityEvaluator.php'));

        $this->assertStringContainsString(
            'registerOptionalGate(app(PermissionGate::class))',
            $source,
            'PermissionGate should be registered as optional gate (foundation phase).',
        );
    }
}

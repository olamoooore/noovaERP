<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Platform\Workflow\WorkflowEngine;
use App\Platform\Workflow\WorkflowSchema;
use App\Platform\Workflow\WorkflowLog;
use App\Platform\Workflow\StatusTransition;
use Tests\TenantTestCase;

/**
 * WorkflowEngineTest — tests for the platform-wide Workflow Status Engine (Pillar 6).
 */
class WorkflowEngineTest extends TenantTestCase
{
    protected string $tenantUuid;
    protected WorkflowEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = \Illuminate\Support\Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);
        $this->engine = app(WorkflowEngine::class);
    }

    // ─── Schema Registration ─────────────────────────────────────

    public function test_register_schema_persists_transitions(): void
    {
        $transitions = [
            StatusTransition::make('draft', 'submitted', 'submit', 'Submit for Approval'),
            StatusTransition::make('submitted', 'approved', 'approve', 'Approve', 'invoices.approve'),
            StatusTransition::make('submitted', 'rejected', 'reject', 'Reject', 'invoices.reject'),
            StatusTransition::make('approved', 'paid', 'pay', 'Mark as Paid'),
        ];

        $schema = $this->engine->registerSchema('invoice', 'draft', $transitions, $this->tenantUuid);

        $this->assertNotNull($schema);
        $this->assertSame('draft', $schema->initial_status);
        $this->assertCount(4, $schema->transitions);
    }

    // ─── Transition Evaluation ───────────────────────────────────

    public function test_can_transition_valid_action(): void
    {
        $this->registerInvoiceSchema();

        $result = $this->engine->canTransition(
            'invoice', 'INV-001', 'submit', $this->tenantUuid, null, 'draft'
        );

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['reason']);
    }

    public function test_can_transition_invalid_action(): void
    {
        $this->registerInvoiceSchema();

        $result = $this->engine->canTransition(
            'invoice', 'INV-001', 'approve', $this->tenantUuid, null, 'draft'
        );

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('not available', $result['reason']);
    }

    public function test_transition_applies_new_status(): void
    {
        $this->registerInvoiceSchema();

        $result = $this->engine->transition(
            'invoice', 'INV-001', 'submit', $this->tenantUuid, null, 'draft'
        );

        $this->assertTrue($result['success']);
        $this->assertSame('submitted', $result['new_status']);
    }

    public function test_transition_rejects_invalid_action(): void
    {
        $this->registerInvoiceSchema();

        $result = $this->engine->transition(
            'invoice', 'INV-001', 'approve', $this->tenantUuid, null, 'draft'
        );

        $this->assertFalse($result['success']);
        $this->assertNull($result['new_status']);
        $this->assertStringContainsString('not available', $result['reason']);
    }

    public function test_full_lifecycle_draft_to_paid(): void
    {
        $this->registerInvoiceSchema();

        // draft → submitted
        $r1 = $this->engine->transition('invoice', 'INV-001', 'submit', $this->tenantUuid, null, 'draft');
        $this->assertTrue($r1['success']);
        $this->assertSame('submitted', $r1['new_status']);

        // submitted → approved
        $r2 = $this->engine->transition('invoice', 'INV-001', 'approve', $this->tenantUuid, null, 'submitted');
        $this->assertTrue($r2['success']);
        $this->assertSame('approved', $r2['new_status']);

        // approved → paid
        $r3 = $this->engine->transition('invoice', 'INV-001', 'pay', $this->tenantUuid, null, 'approved');
        $this->assertTrue($r3['success']);
        $this->assertSame('paid', $r3['new_status']);
    }

    // ─── Available Actions ───────────────────────────────────────

    public function test_get_available_actions_for_status(): void
    {
        $this->registerInvoiceSchema();

        $actions = $this->engine->getAvailableActions('invoice', 'draft', $this->tenantUuid);

        // submit + cancel (wildcard)
        $this->assertCount(2, $actions);
        $actionNames = array_column($actions, 'action');
        $this->assertContains('submit', $actionNames);
        $this->assertContains('cancel', $actionNames);
    }

    public function test_get_available_actions_submitted_status(): void
    {
        $this->registerInvoiceSchema();

        $actions = $this->engine->getAvailableActions('invoice', 'submitted', $this->tenantUuid);

        // approve + reject + cancel (wildcard)
        $this->assertCount(3, $actions);
        $actionNames = array_column($actions, 'action');
        $this->assertContains('approve', $actionNames);
        $this->assertContains('reject', $actionNames);
        $this->assertContains('cancel', $actionNames);
    }

    // ─── Audit Log ───────────────────────────────────────────────

    public function test_transition_logs_to_workflow_log(): void
    {
        $this->registerInvoiceSchema();

        $this->engine->transition('invoice', 'INV-001', 'submit', $this->tenantUuid, null, 'draft');

        $log = WorkflowLog::where('tenant_id', $this->tenantUuid)->first();
        $this->assertNotNull($log);
        $this->assertSame('invoice', $log->entity_type);
        $this->assertSame('INV-001', $log->entity_id);
        $this->assertSame('draft', $log->old_status);
        $this->assertSame('submitted', $log->new_status);
        $this->assertSame('submit', $log->action);
        $this->assertSame('applied', $log->result);
    }

    public function test_rejected_transition_logged(): void
    {
        $this->registerInvoiceSchema();

        $this->engine->transition('invoice', 'INV-001', 'approve', $this->tenantUuid, null, 'draft');

        $log = WorkflowLog::where('tenant_id', $this->tenantUuid)->first();
        $this->assertSame('rejected', $log->result);
        $this->assertNotNull($log->reason);
    }

    public function test_get_log_for_entity(): void
    {
        $this->registerInvoiceSchema();

        $this->engine->transition('invoice', 'INV-001', 'submit', $this->tenantUuid, null, 'draft');
        $this->engine->transition('invoice', 'INV-001', 'approve', $this->tenantUuid, null, 'submitted');

        $logs = $this->engine->getLog('invoice', 'INV-001', $this->tenantUuid);
        $this->assertCount(2, $logs);
    }

    // ─── No Schema ───────────────────────────────────────────────

    public function test_can_transition_without_schema_returns_false(): void
    {
        $result = $this->engine->canTransition(
            'unknown_entity', 'E-001', 'submit', $this->tenantUuid, null, 'draft'
        );

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('No workflow schema', $result['reason']);
    }

    public function test_get_available_actions_without_schema_returns_empty(): void
    {
        $actions = $this->engine->getAvailableActions('unknown_entity', 'draft', $this->tenantUuid);
        $this->assertCount(0, $actions);
    }

    // ─── Helper ──────────────────────────────────────────────────

    private function registerInvoiceSchema(): void
    {
        $this->engine->registerSchema('invoice', 'draft', [
            StatusTransition::make('draft', 'submitted', 'submit', 'Submit for Approval'),
            StatusTransition::make('submitted', 'approved', 'approve', 'Approve', 'invoices.approve'),
            StatusTransition::make('submitted', 'rejected', 'reject', 'Reject', 'invoices.reject'),
            StatusTransition::make('approved', 'paid', 'pay', 'Mark as Paid'),
            StatusTransition::make('*', 'cancelled', 'cancel', 'Cancel'),
        ], $this->tenantUuid);
    }
}

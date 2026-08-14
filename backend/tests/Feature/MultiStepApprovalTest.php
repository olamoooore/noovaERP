<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Accounting\ApprovalInstance;
use App\Models\Accounting\ApprovalWorkflow;
use App\Models\Accounting\ApprovalWorkflowStep;
use App\Models\User;
use App\Services\MultiStepApprovalService;
use Spatie\Permission\Models\Role;
use Tests\TenantTestCase;

class MultiStepApprovalTest extends TenantTestCase
{
    protected string $tenantUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantUuid = \Illuminate\Support\Str::uuid()->toString();
        $this->bindTenantContext($this->tenantUuid);

        Role::firstOrCreate(['name' => 'manager']);
        Role::firstOrCreate(['name' => 'supervisor']);
        Role::firstOrCreate(['name' => 'cfo']);
    }

    private function createManager(): User
    {
        $user = User::factory()->create();
        $user->assignRole('manager');

        return $user;
    }

    private function createSupervisor(): User
    {
        $user = User::factory()->create();
        $user->assignRole('supervisor');

        return $user;
    }

    private function createCfo(): User
    {
        $user = User::factory()->create();
        $user->assignRole('cfo');

        return $user;
    }

    private function createWorkflow(): ApprovalWorkflow
    {
        $workflow = ApprovalWorkflow::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => tenant()->id,
            'name' => 'Bill Approval',
            'code' => 'BILL_APPROVAL',
            'entity_type' => 'bill',
            'is_active' => true,
        ]);

        ApprovalWorkflowStep::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => tenant()->id,
            'workflow_id' => $workflow->id,
            'step_order' => 1,
            'step_name' => 'Manager Review',
            'required_role' => 'manager',
            'is_active' => true,
        ]);

        ApprovalWorkflowStep::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => tenant()->id,
            'workflow_id' => $workflow->id,
            'step_order' => 2,
            'step_name' => 'Supervisor Approval',
            'required_role' => 'supervisor',
            'amount_threshold' => 1000,
            'is_active' => true,
        ]);

        ApprovalWorkflowStep::create([
            'id' => \Illuminate\Support\Str::uuid()->toString(),
            'tenant_id' => tenant()->id,
            'workflow_id' => $workflow->id,
            'step_order' => 3,
            'step_name' => 'CFO Approval',
            'required_role' => 'cfo',
            'amount_threshold' => 5000,
            'is_active' => true,
        ]);

        return $workflow;
    }

    // ─── Positive Tests ──────────────────────────────────────────

    public function test_initiate_workflow(): void
    {
        $this->createWorkflow();
        $manager = $this->createManager();

        $service = app(MultiStepApprovalService::class);
        $instance = $service->initiate(
            'BILL_APPROVAL',
            'bill',
            'bill-123',
            2500.00,
            (string) $manager->id,
            'Test bill'
        );

        $this->assertEquals('in_progress', $instance->status);
        $this->assertEquals(2, $instance->total_steps); // manager + supervisor (amount < 5000)
        $this->assertEquals(1, $instance->current_step);
    }

    public function test_approve_step_advances_workflow(): void
    {
        $this->createWorkflow();
        $manager1 = $this->createManager(); // initiator
        $manager2 = $this->createManager(); // approver for step 1
        $supervisor = $this->createSupervisor();

        $service = app(MultiStepApprovalService::class);
        $instance = $service->initiate(
            'BILL_APPROVAL',
            'bill',
            'bill-123',
            2500.00,
            (string) $manager1->id
        );

        // Another manager approves step 1 (not the initiator)
        $instance = $service->approveStep(
            $instance->id,
            1,
            (string) $manager2->id,
            'Looks good'
        );

        $this->assertEquals('in_progress', $instance->status);
        $this->assertEquals(2, $instance->current_step);
        $this->assertEquals(1, $instance->completed_steps);
    }

    public function test_complete_workflow_approves(): void
    {
        $this->createWorkflow();
        $manager1 = $this->createManager(); // initiator
        $manager2 = $this->createManager(); // approver for step 1
        $supervisor = $this->createSupervisor();

        $service = app(MultiStepApprovalService::class);
        $instance = $service->initiate(
            'BILL_APPROVAL',
            'bill',
            'bill-123',
            2500.00,
            (string) $manager1->id
        );

        $service->approveStep($instance->id, 1, (string) $manager2->id);
        $instance = $service->approveStep($instance->id, 2, (string) $supervisor->id);

        $this->assertEquals('approved', $instance->status);
    }

    public function test_reject_step_rejects_workflow(): void
    {
        $this->createWorkflow();
        $manager1 = $this->createManager(); // initiator
        $manager2 = $this->createManager(); // approver

        $service = app(MultiStepApprovalService::class);
        $instance = $service->initiate(
            'BILL_APPROVAL',
            'bill',
            'bill-123',
            2500.00,
            (string) $manager1->id
        );

        $instance = $service->rejectStep(
            $instance->id,
            1,
            (string) $manager2->id,
            'Amount too high'
        );

        $this->assertEquals('rejected', $instance->status);
    }

    public function test_high_amount_requires_cfo(): void
    {
        $this->createWorkflow();
        $manager1 = $this->createManager(); // initiator
        $manager2 = $this->createManager(); // approver for step 1
        $supervisor = $this->createSupervisor();
        $cfo = $this->createCfo();

        $service = app(MultiStepApprovalService::class);
        $instance = $service->initiate(
            'BILL_APPROVAL',
            'bill',
            'bill-123',
            7500.00,
            (string) $manager1->id
        );

        $this->assertEquals(3, $instance->total_steps);

        $service->approveStep($instance->id, 1, (string) $manager2->id);
        $service->approveStep($instance->id, 2, (string) $supervisor->id);
        $instance = $service->approveStep($instance->id, 3, (string) $cfo->id);

        $this->assertEquals('approved', $instance->status);
    }

    public function test_self_approval_rejected(): void
    {
        $this->createWorkflow();
        $manager = $this->createManager();

        $service = app(MultiStepApprovalService::class);
        $instance = $service->initiate(
            'BILL_APPROVAL',
            'bill',
            'bill-123',
            2500.00,
            (string) $manager->id
        );

        $this->expectException(\DomainException::class);
        $service->approveStep($instance->id, 1, (string) $manager->id);
    }

    public function test_wrong_role_rejected(): void
    {
        $this->createWorkflow();
        $manager = $this->createManager();
        $supervisor = $this->createSupervisor();

        $service = app(MultiStepApprovalService::class);
        $instance = $service->initiate(
            'BILL_APPROVAL',
            'bill',
            'bill-123',
            2500.00,
            (string) $manager->id
        );

        $this->expectException(\DomainException::class);
        $service->approveStep($instance->id, 1, (string) $supervisor->id);
    }

    public function test_duplicate_instance_rejected(): void
    {
        $this->createWorkflow();
        $manager = $this->createManager();

        $service = app(MultiStepApprovalService::class);
        $service->initiate('BILL_APPROVAL', 'bill', 'bill-123', 2500.00, (string) $manager->id);

        $this->expectException(\DomainException::class);
        $service->initiate('BILL_APPROVAL', 'bill', 'bill-123', 2500.00, (string) $manager->id);
    }
}

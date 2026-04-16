<?php

namespace Modules\HRM\Tests\Feature;

use App\Models\User;
use Modules\HRM\Models\Employee;
use Modules\HRM\Models\LeaveRequest;
use Modules\HRM\Tests\HrmTestCase;

class LeaveRequestApiTest extends HrmTestCase
{

    protected User $user;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user     = User::factory()->create();
        $this->employee = Employee::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    }

    public function test_can_list_leave_requests(): void
    {
        LeaveRequest::factory()->count(2)->create(['employee_id' => $this->employee->id]);

        $response = $this->getJson('/api/v1/hrm/leave-requests');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }

    public function test_can_create_leave_request(): void
    {
        $payload = [
            'employee_id' => $this->employee->id,
            'start_date'  => '2026-04-01',
            'end_date'    => '2026-04-03',
            'leave_type'  => 'sick',
            'message'     => 'Not feeling well',
        ];

        $response = $this->postJson('/api/v1/hrm/leave-requests', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.leave_type', 'sick');
        $response->assertJsonPath('data.status', 'pending');
    }

    public function test_end_date_must_be_on_or_after_start_date(): void
    {
        $response = $this->postJson('/api/v1/hrm/leave-requests', [
            'employee_id' => $this->employee->id,
            'start_date'  => '2026-04-05',
            'end_date'    => '2026-04-01',
            'leave_type'  => 'sick',
            'message'     => 'Test',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end_date']);
    }

    public function test_can_approve_pending_leave_request(): void
    {
        $leave = LeaveRequest::factory()->create([
            'employee_id' => $this->employee->id,
            'status'      => 'pending',
        ]);

        $response = $this->postJson("/api/v1/hrm/leave-requests/{$leave->id}/approve", [
            'feedback' => 'Approved.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'approved');
    }

    public function test_cannot_approve_already_approved_request(): void
    {
        $leave = LeaveRequest::factory()->create([
            'employee_id' => $this->employee->id,
            'status'      => 'approved',
        ]);

        $response = $this->postJson("/api/v1/hrm/leave-requests/{$leave->id}/approve");

        $response->assertStatus(422);
        $response->assertJsonPath('message', "Cannot approve a leave request that is already 'approved'.");
    }

    public function test_can_reject_pending_leave_request(): void
    {
        $leave = LeaveRequest::factory()->create([
            'employee_id' => $this->employee->id,
            'status'      => 'pending',
        ]);

        $response = $this->postJson("/api/v1/hrm/leave-requests/{$leave->id}/reject", [
            'feedback' => 'Not approved this period.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'rejected');
    }

    public function test_cannot_reject_already_rejected_request(): void
    {
        $leave = LeaveRequest::factory()->create([
            'employee_id' => $this->employee->id,
            'status'      => 'rejected',
        ]);

        $response = $this->postJson("/api/v1/hrm/leave-requests/{$leave->id}/reject");

        $response->assertStatus(422);
    }

    public function test_can_filter_by_status(): void
    {
        LeaveRequest::factory()->create(['employee_id' => $this->employee->id, 'status' => 'pending']);
        LeaveRequest::factory()->create(['employee_id' => $this->employee->id, 'status' => 'approved']);

        $response = $this->getJson('/api/v1/hrm/leave-requests?status=pending');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }
}

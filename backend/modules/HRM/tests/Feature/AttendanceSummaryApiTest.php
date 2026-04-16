<?php

namespace Modules\HRM\Tests\Feature;

use App\Models\User;
use Modules\HRM\Models\AttendanceDailySummary;
use Modules\HRM\Models\Employee;
use Modules\HRM\Tests\HrmTestCase;

class AttendanceSummaryApiTest extends HrmTestCase
{
    private User $user;
    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user     = User::factory()->create();
        $this->employee = Employee::factory()->create(['status' => 'active']);
        $this->actingAs($this->user, 'sanctum');
    }

    public function test_can_filter_summaries_by_status(): void
    {
        AttendanceDailySummary::create([
            'employee_id'    => $this->employee->id,
            'date'           => '2026-04-01',
            'first_check_in' => '08:00:00',
            'status'         => 'present',
        ]);
        AttendanceDailySummary::create([
            'employee_id'    => $this->employee->id,
            'date'           => '2026-04-02',
            'first_check_in' => null,
            'status'         => 'absent',
        ]);

        $response = $this->getJson('/api/v1/hrm/attendance-summaries?status=absent');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('absent', $data[0]['status']);
    }

    public function test_can_filter_summaries_with_has_exception(): void
    {
        AttendanceDailySummary::create([
            'employee_id'    => $this->employee->id,
            'date'           => '2026-04-01',
            'first_check_in' => '08:00:00',
            'status'         => 'present',
            'has_exception'  => false,
            'exception_type' => 'none',
        ]);
        AttendanceDailySummary::create([
            'employee_id'    => $this->employee->id,
            'date'           => '2026-04-02',
            'first_check_in' => null,
            'status'         => 'partial',
            'has_exception'  => true,
            'exception_type' => 'missing_check_in',
        ]);

        $response = $this->getJson('/api/v1/hrm/attendance-summaries?has_exception=1');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertTrue($data[0]['has_exception']);
        $this->assertEquals('missing_check_in', $data[0]['exception_type']);
    }

    public function test_can_show_single_summary(): void
    {
        $summary = AttendanceDailySummary::create([
            'employee_id'    => $this->employee->id,
            'date'           => '2026-04-01',
            'first_check_in' => '08:00:00',
            'last_check_out' => '17:00:00',
            'worked_minutes' => 540,
            'status'         => 'present',
        ]);

        $response = $this->getJson("/api/v1/hrm/attendance-summaries/{$summary->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $summary->id);
        $response->assertJsonPath('data.status', 'present');
        $response->assertJsonPath('data.worked_minutes', 540);
    }
}

<?php

namespace Modules\HRM\Tests\Feature;

use App\Models\User;
use Modules\HRM\Models\Attendance;
use Modules\HRM\Models\Employee;
use Modules\HRM\Tests\HrmTestCase;

class AttendanceApiTest extends HrmTestCase
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

    public function test_can_list_attendance(): void
    {
        Attendance::factory()->count(3)->create(['employee_id' => $this->employee->id]);

        $response = $this->getJson('/api/v1/hrm/attendance');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
    }

    public function test_can_filter_attendance_by_employee(): void
    {
        $other = Employee::factory()->create();
        Attendance::factory()->create(['employee_id' => $this->employee->id, 'date' => '2026-03-01']);
        Attendance::factory()->create(['employee_id' => $other->id, 'date' => '2026-03-01']);

        $response = $this->getJson("/api/v1/hrm/attendance?employee_id={$this->employee->id}");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_can_create_attendance_record(): void
    {
        $payload = [
            'employee_id' => $this->employee->id,
            'date'        => '2026-03-30',
            'check_in'    => '09:00',
            'check_out'   => '18:00',
        ];

        $response = $this->postJson('/api/v1/hrm/attendance', $payload);

        $response->assertStatus(201);
        $response->assertJsonPath('data.check_in', '09:00');
        $response->assertJsonPath('data.check_out', '18:00');
        $response->assertJsonPath('data.duration', '9h');
    }

    public function test_checkout_must_be_after_checkin(): void
    {
        $response = $this->postJson('/api/v1/hrm/attendance', [
            'employee_id' => $this->employee->id,
            'date'        => '2026-03-30',
            'check_in'    => '18:00',
            'check_out'   => '09:00',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['check_out']);
    }

    public function test_can_update_attendance(): void
    {
        $attendance = Attendance::factory()->create([
            'employee_id' => $this->employee->id,
            'check_in'    => '09:00',
        ]);

        $response = $this->putJson("/api/v1/hrm/attendance/{$attendance->id}", [
            'check_out' => '17:00',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.check_out', '17:00');
    }

    public function test_can_delete_attendance(): void
    {
        $attendance = Attendance::factory()->create(['employee_id' => $this->employee->id]);

        $response = $this->deleteJson("/api/v1/hrm/attendance/{$attendance->id}");

        $response->assertStatus(204);
    }

    public function test_create_requires_employee_date_and_checkin(): void
    {
        $response = $this->postJson('/api/v1/hrm/attendance', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['employee_id', 'date', 'check_in']);
    }
}

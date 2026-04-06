<?php

namespace Modules\HRM\Tests\Feature;

use App\Models\User;
use Modules\HRM\Models\Employee;
use Modules\HRM\Models\AttendanceEvent;
use Modules\HRM\Models\AttendanceDailySummary;
use Modules\HRM\Tests\HrmTestCase;

class AttendanceEventApiTest extends HrmTestCase
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

    // ── Ingestion ─────────────────────────────────────────────────────────────

    public function test_can_ingest_check_in_event(): void
    {
        $response = $this->postJson('/api/v1/hrm/attendance-events', [
            'employee_id'     => $this->employee->id,
            'event_type'      => 'check_in',
            'captured_at'     => '2026-04-01 08:00:00',
            'source_event_id' => 'device-001-20260401-0800',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.event_type', 'check_in');
        $response->assertJsonPath('data.status', 'processed');

        // Summary row created
        $this->assertDatabaseHas('hrm_attendance_daily_summaries', [
            'employee_id'    => $this->employee->id,
            'date'           => '2026-04-01',
            'first_check_in' => '08:00:00',
        ]);
    }

    public function test_can_ingest_check_out_and_worked_minutes_calculated(): void
    {
        // Check-in first
        AttendanceEvent::create([
            'employee_id'     => $this->employee->id,
            'event_type'      => 'check_in',
            'captured_at'     => '2026-04-01 08:00:00',
            'source_event_id' => 'src-checkin-001',
            'status'          => 'pending',
        ]);
        AttendanceDailySummary::create([
            'employee_id'    => $this->employee->id,
            'date'           => '2026-04-01',
            'first_check_in' => '08:00:00',
            'status'         => 'present',
        ]);

        // Check-out
        $response = $this->postJson('/api/v1/hrm/attendance-events', [
            'employee_id'     => $this->employee->id,
            'event_type'      => 'check_out',
            'captured_at'     => '2026-04-01 17:00:00',
            'source_event_id' => 'src-checkout-001',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.event_type', 'check_out');

        $summary = AttendanceDailySummary::where('employee_id', $this->employee->id)
            ->where('date', '2026-04-01')
            ->first();

        $this->assertEquals('17:00:00', $summary->last_check_out);
        $this->assertEquals(540, $summary->worked_minutes); // 9 hours = 540 minutes
    }

    public function test_duplicate_source_event_id_returns_409(): void
    {
        AttendanceEvent::create([
            'employee_id'     => $this->employee->id,
            'event_type'      => 'check_in',
            'captured_at'     => '2026-04-01 08:00:00',
            'source_event_id' => 'unique-event-abc',
            'status'          => 'processed',
        ]);

        $response = $this->postJson('/api/v1/hrm/attendance-events', [
            'employee_id'     => $this->employee->id,
            'event_type'      => 'check_in',
            'captured_at'     => '2026-04-01 08:00:00',
            'source_event_id' => 'unique-event-abc', // duplicate
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Event already ingested (duplicate source_event_id).');
    }

    public function test_invalid_employee_id_returns_422(): void
    {
        $response = $this->postJson('/api/v1/hrm/attendance-events', [
            'employee_id'     => 99999,
            'event_type'      => 'check_in',
            'captured_at'     => '2026-04-01 08:00:00',
            'source_event_id' => 'src-bad-employee',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['employee_id']);
    }

    // ── Listing ───────────────────────────────────────────────────────────────

    public function test_can_list_attendance_events(): void
    {
        AttendanceEvent::create([
            'employee_id'     => $this->employee->id,
            'event_type'      => 'check_in',
            'captured_at'     => '2026-04-01 08:00:00',
            'source_event_id' => 'list-test-001',
            'status'          => 'processed',
        ]);

        $response = $this->getJson('/api/v1/hrm/attendance-events');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta']);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_can_list_attendance_summaries(): void
    {
        AttendanceDailySummary::create([
            'employee_id'    => $this->employee->id,
            'date'           => '2026-04-01',
            'first_check_in' => '08:00:00',
            'status'         => 'present',
        ]);

        $response = $this->getJson('/api/v1/hrm/attendance-summaries');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta']);
        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
    }

    public function test_can_filter_events_by_status(): void
    {
        AttendanceEvent::create([
            'employee_id'     => $this->employee->id,
            'event_type'      => 'check_in',
            'captured_at'     => '2026-04-01 08:00:00',
            'source_event_id' => 'filter-status-processed-001',
            'status'          => 'processed',
        ]);
        AttendanceEvent::create([
            'employee_id'     => $this->employee->id,
            'event_type'      => 'check_out',
            'captured_at'     => '2026-04-01 17:00:00',
            'source_event_id' => 'filter-status-pending-001',
            'status'          => 'pending',
        ]);

        $response = $this->getJson('/api/v1/hrm/attendance-events?status=processed');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('processed', $data[0]['status']);
    }

    public function test_can_filter_events_by_event_type(): void
    {
        AttendanceEvent::create([
            'employee_id'     => $this->employee->id,
            'event_type'      => 'check_in',
            'captured_at'     => '2026-04-01 08:00:00',
            'source_event_id' => 'filter-type-in-001',
            'status'          => 'processed',
        ]);
        AttendanceEvent::create([
            'employee_id'     => $this->employee->id,
            'event_type'      => 'check_out',
            'captured_at'     => '2026-04-01 17:00:00',
            'source_event_id' => 'filter-type-out-001',
            'status'          => 'processed',
        ]);

        $response = $this->getJson('/api/v1/hrm/attendance-events?event_type=check_in');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('check_in', $data[0]['event_type']);
    }
}

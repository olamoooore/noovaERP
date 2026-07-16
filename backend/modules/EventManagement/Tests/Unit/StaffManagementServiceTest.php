<?php

namespace Modules\EventManagement\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Exceptions\EventManagementException;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Services\StaffManagementService;
use Modules\EventManagement\Services\TenantSchemaManager;
use Tests\TestCase;

/**
 * StaffManagementService Unit Tests
 *
 * Tests the core functionality of the StaffManagementService including
 * staff assignments, zone access control, scheduling, and performance tracking.
 */
class StaffManagementServiceTest extends TestCase
{
    use RefreshDatabase;

    private StaffManagementService $service;

    private AuditService $auditService;

    private TenantSchemaManager $tenantManager;

    private string $testTenantId = 'test-tenant-001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->auditService = $this->createMock(AuditService::class);
        $this->tenantManager = $this->createMock(TenantSchemaManager::class);

        $this->tenantManager->method('getCurrentTenantId')
            ->willReturn($this->testTenantId);

        $this->service = new StaffManagementService(
            $this->auditService,
            $this->tenantManager
        );
    }

    public function test_assign_staff_to_event_creates_assignment()
    {
        // Create test event
        $event = Event::factory()->create([
            'tenant_id' => $this->testTenantId,
            'title' => 'Test Concert',
            'status' => 'active',
        ]);

        // Create operational zone
        DB::table('operational_zones')->insert([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'zone_code' => 'VIP',
            'zone_name' => 'VIP Section',
            'zone_type' => 'vip',
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $staffUserId = 'staff-001';
        $assignmentData = [
            'role' => 'coordinator',
            'zone' => 'VIP',
            'responsibilities' => 'Manage VIP guest experience',
            'permissions' => ['zone_access_VIP', 'guest_management'],
            'shift_start' => now()->addHours(2),
            'shift_end' => now()->addHours(8),
            'is_lead' => true,
            'can_override' => true,
            'contact_number' => '+1234567890',
            'emergency_contact' => '+0987654321',
            'special_instructions' => 'VIP coordinator with override privileges',
        ];

        // Mock audit service
        $this->auditService->expects($this->once())
            ->method('logActivity')
            ->with('staff_assigned', $this->anything(), $this->anything());

        // Assign staff
        $assignmentId = $this->service->assignStaffToEvent($event->id, $staffUserId, $assignmentData);

        // Assertions
        $this->assertIsInt($assignmentId);
        $this->assertGreaterThan(0, $assignmentId);

        // Verify assignment was created in database
        $assignment = DB::table('staff_assignments')->where('id', $assignmentId)->first();
        $this->assertNotNull($assignment);
        $this->assertEquals($this->testTenantId, $assignment->tenant_id);
        $this->assertEquals($event->id, $assignment->event_id);
        $this->assertEquals($staffUserId, $assignment->staff_user_id);
        $this->assertEquals('coordinator', $assignment->role);
        $this->assertEquals('VIP', $assignment->zone);
        $this->assertEquals('Manage VIP guest experience', $assignment->responsibilities);
        $this->assertTrue($assignment->is_lead);
        $this->assertTrue($assignment->can_override);
        $this->assertEquals('+1234567890', $assignment->contact_number);
        $this->assertEquals('assigned', $assignment->status);

        // Verify permissions are stored as JSON
        $permissions = json_decode($assignment->permissions, true);
        $this->assertContains('zone_access_VIP', $permissions);
        $this->assertContains('guest_management', $permissions);
    }

    public function test_assign_staff_throws_exception_for_duplicate_assignment()
    {
        // Create test event
        $event = Event::factory()->create([
            'tenant_id' => $this->testTenantId,
            'title' => 'Test Event',
            'status' => 'active',
        ]);

        $staffUserId = 'staff-002';
        $assignmentData = [
            'role' => 'security',
            'responsibilities' => 'Security duties',
        ];

        // Create first assignment
        DB::table('staff_assignments')->insert([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'staff_user_id' => $staffUserId,
            'role' => 'security',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Expect exception for duplicate assignment
        $this->expectException(EventManagementException::class);
        $this->expectExceptionMessage('Staff member staff-002 is already assigned to role security for this event');

        $this->service->assignStaffToEvent($event->id, $staffUserId, $assignmentData);
    }

    public function test_check_in_out_staff_updates_status_and_timestamps()
    {
        // Create test assignment
        $assignmentId = DB::table('staff_assignments')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'event_id' => 1,
            'staff_user_id' => 'staff-003',
            'role' => 'usher',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Mock audit service
        $this->auditService->expects($this->exactly(2))
            ->method('logActivity')
            ->withConsecutive(
                ['staff_check_in_out', $this->anything(), $this->anything()],
                ['staff_check_in_out', $this->anything(), $this->anything()]
            );

        // Test check-in
        $result = $this->service->checkInOutStaff($assignmentId, 'check_in');
        $this->assertTrue($result);

        // Verify check-in was recorded
        $assignment = DB::table('staff_assignments')->where('id', $assignmentId)->first();
        $this->assertEquals('checked_in', $assignment->status);
        $this->assertNotNull($assignment->checked_in_at);

        // Test check-out
        $result = $this->service->checkInOutStaff($assignmentId, 'check_out');
        $this->assertTrue($result);

        // Verify check-out was recorded
        $assignment = DB::table('staff_assignments')->where('id', $assignmentId)->first();
        $this->assertEquals('checked_out', $assignment->status);
        $this->assertNotNull($assignment->checked_out_at);
    }

    public function test_check_in_staff_throws_exception_when_already_checked_in()
    {
        // Create test assignment that's already checked in
        $assignmentId = DB::table('staff_assignments')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'event_id' => 1,
            'staff_user_id' => 'staff-004',
            'role' => 'technical',
            'status' => 'checked_in',
            'checked_in_at' => now()->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(EventManagementException::class);
        $this->expectExceptionMessage('Staff member is already checked in');

        $this->service->checkInOutStaff($assignmentId, 'check_in');
    }

    public function test_update_staff_status_changes_status()
    {
        // Create test assignment
        $assignmentId = DB::table('staff_assignments')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'event_id' => 1,
            'staff_user_id' => 'staff-005',
            'role' => 'coordinator',
            'status' => 'checked_in',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Mock audit service
        $this->auditService->expects($this->once())
            ->method('logActivity')
            ->with('staff_status_updated', $this->anything(), $this->anything());

        // Update status to active
        $result = $this->service->updateStaffStatus($assignmentId, 'active');
        $this->assertTrue($result);

        // Verify status was updated
        $assignment = DB::table('staff_assignments')->where('id', $assignmentId)->first();
        $this->assertEquals('active', $assignment->status);
    }

    public function test_update_staff_status_throws_exception_for_invalid_status()
    {
        // Create test assignment
        $assignmentId = DB::table('staff_assignments')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'event_id' => 1,
            'staff_user_id' => 'staff-006',
            'role' => 'security',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(EventManagementException::class);
        $this->expectExceptionMessage('Invalid status: invalid_status');

        $this->service->updateStaffStatus($assignmentId, 'invalid_status');
    }

    public function test_get_event_staff_assignments_returns_filtered_results()
    {
        $eventId = 1;

        // Create multiple staff assignments
        DB::table('staff_assignments')->insert([
            [
                'tenant_id' => $this->testTenantId,
                'event_id' => $eventId,
                'staff_user_id' => 'staff-007',
                'role' => 'coordinator',
                'zone' => 'VIP',
                'status' => 'active',
                'is_lead' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->testTenantId,
                'event_id' => $eventId,
                'staff_user_id' => 'staff-008',
                'role' => 'security',
                'zone' => 'MAIN',
                'status' => 'checked_in',
                'is_lead' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->testTenantId,
                'event_id' => $eventId,
                'staff_user_id' => 'staff-009',
                'role' => 'coordinator',
                'zone' => 'BACKSTAGE',
                'status' => 'absent',
                'is_lead' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Test without filters
        $allAssignments = $this->service->getEventStaffAssignments($eventId);
        $this->assertCount(3, $allAssignments);

        // Test with role filter
        $coordinatorAssignments = $this->service->getEventStaffAssignments($eventId, ['role' => 'coordinator']);
        $this->assertCount(2, $coordinatorAssignments);

        // Test with status filter
        $activeAssignments = $this->service->getEventStaffAssignments($eventId, ['status' => 'active']);
        $this->assertCount(1, $activeAssignments);
        $this->assertEquals('staff-007', $activeAssignments->first()->staff_user_id);

        // Test with lead filter
        $leadAssignments = $this->service->getEventStaffAssignments($eventId, ['is_lead' => true]);
        $this->assertCount(1, $leadAssignments);
        $this->assertEquals('coordinator', $leadAssignments->first()->role);
    }

    public function test_calculate_staff_performance_returns_metrics()
    {
        $staffUserId = 'staff-010';

        // Create test events
        $event1 = Event::factory()->create([
            'tenant_id' => $this->testTenantId,
            'title' => 'Event 1',
            'event_date' => now()->subDays(30),
        ]);

        $event2 = Event::factory()->create([
            'tenant_id' => $this->testTenantId,
            'title' => 'Event 2',
            'event_date' => now()->subDays(15),
        ]);

        // Create staff assignments with performance data
        DB::table('staff_assignments')->insert([
            [
                'tenant_id' => $this->testTenantId,
                'event_id' => $event1->id,
                'staff_user_id' => $staffUserId,
                'role' => 'coordinator',
                'zone' => 'VIP',
                'status' => 'checked_out',
                'is_lead' => true,
                'performance_rating' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->testTenantId,
                'event_id' => $event2->id,
                'staff_user_id' => $staffUserId,
                'role' => 'security',
                'zone' => 'MAIN',
                'status' => 'checked_out',
                'is_lead' => false,
                'performance_rating' => 4,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Calculate performance
        $performance = $this->service->calculateStaffPerformance($staffUserId);

        // Assertions
        $this->assertEquals($staffUserId, $performance['staff_user_id']);
        $this->assertEquals(2, $performance['total_assignments']);
        $this->assertEquals(4.5, $performance['average_rating']); // (5 + 4) / 2
        $this->assertEquals(100.0, $performance['attendance_rate']); // No absences
        $this->assertEquals(1, $performance['leadership_assignments']);
        $this->assertEquals(50.0, $performance['leadership_rate']); // 1 out of 2
        $this->assertContains('coordinator', $performance['roles_performed']);
        $this->assertContains('security', $performance['roles_performed']);
        $this->assertContains('VIP', $performance['zones_worked']);
        $this->assertContains('MAIN', $performance['zones_worked']);
        $this->assertCount(2, $performance['recent_assignments']);
    }

    public function test_get_staff_zone_access_returns_permissions()
    {
        $staffUserId = 'staff-011';
        $eventId = 1;

        // Create staff assignments with different zone access
        DB::table('staff_assignments')->insert([
            [
                'tenant_id' => $this->testTenantId,
                'event_id' => $eventId,
                'staff_user_id' => $staffUserId,
                'role' => 'coordinator',
                'zone' => 'VIP',
                'status' => 'active',
                'is_lead' => true,
                'can_override' => true,
                'permissions' => json_encode(['zone_access_VIP', 'guest_management', 'override_capacity']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->testTenantId,
                'event_id' => $eventId,
                'staff_user_id' => $staffUserId,
                'role' => 'security',
                'zone' => 'MAIN',
                'status' => 'active',
                'is_lead' => false,
                'can_override' => false,
                'permissions' => json_encode(['zone_access_MAIN', 'incident_reporting']),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Get zone access
        $access = $this->service->getStaffZoneAccess($staffUserId, $eventId);

        // Assertions
        $this->assertEquals($staffUserId, $access['staff_user_id']);
        $this->assertEquals($eventId, $access['event_id']);
        $this->assertTrue($access['has_override_capability']);
        $this->assertTrue($access['is_lead_anywhere']);

        // Check VIP zone access
        $this->assertArrayHasKey('VIP', $access['zone_access']);
        $vipAccess = $access['zone_access']['VIP'];
        $this->assertEquals('coordinator', $vipAccess['role']);
        $this->assertTrue($vipAccess['is_lead']);
        $this->assertTrue($vipAccess['can_override']);
        $this->assertContains('zone_access_VIP', $vipAccess['permissions']);
        $this->assertContains('guest_management', $vipAccess['permissions']);

        // Check MAIN zone access
        $this->assertArrayHasKey('MAIN', $access['zone_access']);
        $mainAccess = $access['zone_access']['MAIN'];
        $this->assertEquals('security', $mainAccess['role']);
        $this->assertFalse($mainAccess['is_lead']);
        $this->assertFalse($mainAccess['can_override']);

        // Check global permissions
        $this->assertContains('zone_access_VIP', $access['global_permissions']);
        $this->assertContains('zone_access_MAIN', $access['global_permissions']);
        $this->assertContains('guest_management', $access['global_permissions']);
        $this->assertContains('incident_reporting', $access['global_permissions']);
    }

    public function test_generate_staff_scheduling_report_provides_comprehensive_data()
    {
        $eventId = 1;

        // Create diverse staff assignments
        DB::table('staff_assignments')->insert([
            [
                'tenant_id' => $this->testTenantId,
                'event_id' => $eventId,
                'staff_user_id' => 'staff-012',
                'role' => 'coordinator',
                'zone' => 'VIP',
                'status' => 'active',
                'is_lead' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->testTenantId,
                'event_id' => $eventId,
                'staff_user_id' => 'staff-013',
                'role' => 'coordinator',
                'zone' => 'MAIN',
                'status' => 'checked_in',
                'is_lead' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->testTenantId,
                'event_id' => $eventId,
                'staff_user_id' => 'staff-014',
                'role' => 'security',
                'zone' => 'ENTRANCE',
                'status' => 'absent',
                'is_lead' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->testTenantId,
                'event_id' => $eventId,
                'staff_user_id' => 'staff-015',
                'role' => 'security',
                'zone' => 'MAIN',
                'status' => 'active',
                'is_lead' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Generate report
        $report = $this->service->generateStaffSchedulingReport($eventId);

        // Assertions
        $this->assertEquals($eventId, $report['event_id']);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('role_breakdown', $report);
        $this->assertArrayHasKey('zone_breakdown', $report);
        $this->assertArrayHasKey('coverage_gaps', $report);
        $this->assertArrayHasKey('generated_at', $report);

        // Check summary
        $summary = $report['summary'];
        $this->assertEquals(4, $summary['total_assigned']);
        $this->assertEquals(2, $summary['total_checked_in']); // active + checked_in
        $this->assertEquals(1, $summary['total_absent']);
        $this->assertEquals(75.0, $summary['attendance_rate']); // 3 out of 4 not absent
        $this->assertEquals(2, $summary['total_leads']);
        $this->assertEquals(2, $summary['roles_covered']); // coordinator, security
        $this->assertEquals(3, $summary['zones_covered']); // VIP, MAIN, ENTRANCE

        // Check role breakdown
        $roleBreakdown = collect($report['role_breakdown']);
        $coordinatorRole = $roleBreakdown->firstWhere('role', 'coordinator');
        $this->assertEquals(2, $coordinatorRole['total_assigned']);
        $this->assertEquals(1, $coordinatorRole['leads']);
        $this->assertEquals(2, $coordinatorRole['checked_in']); // active + checked_in
        $this->assertEquals(0, $coordinatorRole['absent']);

        $securityRole = $roleBreakdown->firstWhere('role', 'security');
        $this->assertEquals(2, $securityRole['total_assigned']);
        $this->assertEquals(1, $securityRole['leads']);
        $this->assertEquals(1, $securityRole['absent']);

        // Check zone breakdown
        $zoneBreakdown = collect($report['zone_breakdown']);
        $mainZone = $zoneBreakdown->firstWhere('zone', 'MAIN');
        $this->assertEquals(2, $mainZone['total_assigned']);
        $this->assertEquals(2, $mainZone['roles_present']); // coordinator, security
        $this->assertEquals(1, $mainZone['leads']);
    }

    public function test_remove_staff_assignment_soft_deletes_record()
    {
        // Create test assignment
        $assignmentId = DB::table('staff_assignments')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'event_id' => 1,
            'staff_user_id' => 'staff-016',
            'role' => 'usher',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Mock audit service
        $this->auditService->expects($this->once())
            ->method('logActivity')
            ->with('staff_assignment_removed', $this->anything(), $this->anything());

        // Remove assignment
        $result = $this->service->removeStaffAssignment($assignmentId, 'Staff no longer available');
        $this->assertTrue($result);

        // Verify soft delete
        $assignment = DB::table('staff_assignments')->where('id', $assignmentId)->first();
        $this->assertNotNull($assignment->deleted_at);
    }

    public function test_calculate_staff_performance_handles_empty_history()
    {
        $staffUserId = 'staff-nonexistent';

        // Calculate performance for staff with no assignments
        $performance = $this->service->calculateStaffPerformance($staffUserId);

        // Assertions
        $this->assertEquals($staffUserId, $performance['staff_user_id']);
        $this->assertEquals(0, $performance['total_assignments']);
        $this->assertNull($performance['average_rating']);
        $this->assertNull($performance['attendance_rate']);
        $this->assertEquals(0, $performance['leadership_assignments']);
        $this->assertEmpty($performance['roles_performed']);
        $this->assertEmpty($performance['zones_worked']);
    }
}

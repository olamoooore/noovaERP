<?php

namespace Modules\EventManagement\Tests\Unit;

use Tests\TestCase;
use Modules\EventManagement\Services\EventOperationsService;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Services\TenantSchemaManager;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\Ticket;
use Modules\EventManagement\Exceptions\EventManagementException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * EventOperationsService Unit Tests
 * 
 * Tests the core functionality of the EventOperationsService including
 * live dashboard data, real-time monitoring, and operational management.
 */
class EventOperationsServiceTest extends TestCase
{
    use RefreshDatabase;

    private EventOperationsService $service;
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
        
        $this->service = new EventOperationsService(
            $this->auditService,
            $this->tenantManager
        );
    }

    public function test_get_live_event_dashboard_returns_comprehensive_data()
    {
        // Create test event
        $event = Event::factory()->create([
            'tenant_id' => $this->testTenantId,
            'title' => 'Test Concert',
            'status' => 'active'
        ]);

        // Create test bookings and tickets
        $booking = Booking::factory()->create([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'status' => 'confirmed'
        ]);

        Ticket::factory()->count(5)->create([
            'booking_id' => $booking->id,
            'status' => 'active'
        ]);

        // Create operational zones
        DB::table('operational_zones')->insert([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'zone_code' => 'VIP',
            'zone_name' => 'VIP Section',
            'zone_type' => 'vip',
            'max_capacity' => 100,
            'current_occupancy' => 50,
            'occupancy_percentage' => 50.0,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Create check-in counters
        DB::table('check_in_counters')->insert([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'counter_code' => 'A1',
            'counter_name' => 'Main Entrance A1',
            'counter_type' => 'general',
            'current_queue_length' => 5,
            'total_processed' => 25,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Create staff assignments
        DB::table('staff_assignments')->insert([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'staff_user_id' => 'staff-001',
            'role' => 'coordinator',
            'zone' => 'VIP',
            'status' => 'active',
            'is_lead' => true,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Mock audit service
        $this->auditService->expects($this->once())
                          ->method('logActivity')
                          ->with('operations_dashboard_access', $this->anything(), $this->anything());

        // Get dashboard data
        $dashboardData = $this->service->getLiveEventDashboard($event->id);

        // Assertions
        $this->assertIsArray($dashboardData);
        $this->assertEquals($event->id, $dashboardData['event_id']);
        $this->assertEquals('Test Concert', $dashboardData['event_title']);
        $this->assertEquals('active', $dashboardData['event_status']);
        
        // Check that all required sections are present
        $this->assertArrayHasKey('check_in_metrics', $dashboardData);
        $this->assertArrayHasKey('zone_status', $dashboardData);
        $this->assertArrayHasKey('staff_status', $dashboardData);
        $this->assertArrayHasKey('queue_status', $dashboardData);
        $this->assertArrayHasKey('recent_incidents', $dashboardData);
        $this->assertArrayHasKey('current_operational_status', $dashboardData);
        $this->assertArrayHasKey('last_updated', $dashboardData);

        // Verify check-in metrics structure
        $checkInMetrics = $dashboardData['check_in_metrics'];
        $this->assertArrayHasKey('total_tickets', $checkInMetrics);
        $this->assertArrayHasKey('checked_in_tickets', $checkInMetrics);
        $this->assertArrayHasKey('pending_check_ins', $checkInMetrics);
        $this->assertArrayHasKey('check_in_rate', $checkInMetrics);

        // Verify queue status structure
        $queueStatus = $dashboardData['queue_status'];
        $this->assertArrayHasKey('total_queue_length', $queueStatus);
        $this->assertArrayHasKey('active_counters', $queueStatus);
        $this->assertArrayHasKey('total_counters', $queueStatus);
        $this->assertEquals(5, $queueStatus['total_queue_length']);
        $this->assertEquals(1, $queueStatus['total_counters']);
    }

    public function test_update_zone_occupancy_correctly_calculates_percentage()
    {
        // Create test zone
        $zoneId = DB::table('operational_zones')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'event_id' => 1,
            'zone_code' => 'GA',
            'zone_name' => 'General Admission',
            'zone_type' => 'standing',
            'max_capacity' => 200,
            'current_occupancy' => 100,
            'occupancy_percentage' => 50.0,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Mock audit service
        $this->auditService->expects($this->once())
                          ->method('logActivity')
                          ->with('zone_occupancy_update', $this->anything(), $this->anything());

        // Update occupancy by +50
        $result = $this->service->updateZoneOccupancy($zoneId, 50);

        // Assertions
        $this->assertEquals($zoneId, $result['zone_id']);
        $this->assertEquals(150, $result['current_occupancy']);
        $this->assertEquals(75.0, $result['occupancy_percentage']);
        $this->assertEquals('open', $result['status']); // Should still be open at 75%
        $this->assertEquals(200, $result['max_capacity']);

        // Verify database was updated
        $updatedZone = DB::table('operational_zones')->where('id', $zoneId)->first();
        $this->assertEquals(150, $updatedZone->current_occupancy);
        $this->assertEquals(75.0, $updatedZone->occupancy_percentage);
    }

    public function test_update_zone_occupancy_changes_status_when_full()
    {
        // Create test zone near capacity
        $zoneId = DB::table('operational_zones')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'event_id' => 1,
            'zone_code' => 'VIP',
            'zone_name' => 'VIP Section',
            'zone_type' => 'vip',
            'max_capacity' => 100,
            'current_occupancy' => 95,
            'occupancy_percentage' => 95.0,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Mock audit service
        $this->auditService->expects($this->once())
                          ->method('logActivity');

        // Update occupancy to reach 100%
        $result = $this->service->updateZoneOccupancy($zoneId, 5);

        // Assertions
        $this->assertEquals(100, $result['current_occupancy']);
        $this->assertEquals(100.0, $result['occupancy_percentage']);
        $this->assertEquals('full', $result['status']); // Should change to full
    }

    public function test_update_counter_queue_processes_check_ins()
    {
        // Create test counter
        $counterId = DB::table('check_in_counters')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'event_id' => 1,
            'counter_code' => 'B2',
            'counter_name' => 'Entrance B2',
            'counter_type' => 'general',
            'current_queue_length' => 10,
            'total_processed' => 50,
            'checkins_per_hour' => 30,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Mock audit service
        $this->auditService->expects($this->once())
                          ->method('logActivity')
                          ->with('counter_queue_update', $this->anything(), $this->anything());

        // Process 3 people (decrease queue by 3)
        $result = $this->service->updateCounterQueue($counterId, -3);

        // Assertions
        $this->assertEquals($counterId, $result['counter_id']);
        $this->assertEquals(7, $result['current_queue_length']);
        $this->assertEquals(53, $result['total_processed']); // 50 + 3
        $this->assertArrayHasKey('checkins_per_hour', $result);

        // Verify database was updated
        $updatedCounter = DB::table('check_in_counters')->where('id', $counterId)->first();
        $this->assertEquals(7, $updatedCounter->current_queue_length);
        $this->assertEquals(53, $updatedCounter->total_processed);
        $this->assertNotNull($updatedCounter->last_activity_at);
    }

    public function test_log_incident_creates_incident_record()
    {
        $eventId = 1;
        $incidentData = [
            'incident_type' => 'security',
            'severity' => 'high',
            'priority' => 'urgent',
            'title' => 'Unauthorized Access Attempt',
            'description' => 'Someone tried to access VIP area without proper credentials',
            'reported_by' => 'security-001',
            'zone_id' => 1,
            'people_affected' => 0,
            'metadata' => ['location' => 'VIP entrance', 'time' => '14:30']
        ];

        // Mock audit service
        $this->auditService->expects($this->once())
                          ->method('logActivity')
                          ->with('incident_logged', $this->anything(), $this->anything());

        // Log incident
        $incidentId = $this->service->logIncident($eventId, $incidentData);

        // Assertions
        $this->assertIsInt($incidentId);
        $this->assertGreaterThan(0, $incidentId);

        // Verify incident was created in database
        $incident = DB::table('incident_logs')->where('id', $incidentId)->first();
        $this->assertNotNull($incident);
        $this->assertEquals($this->testTenantId, $incident->tenant_id);
        $this->assertEquals($eventId, $incident->event_id);
        $this->assertEquals('security', $incident->incident_type);
        $this->assertEquals('high', $incident->severity);
        $this->assertEquals('urgent', $incident->priority);
        $this->assertEquals('Unauthorized Access Attempt', $incident->title);
        $this->assertEquals('security-001', $incident->reported_by);
        $this->assertEquals('open', $incident->status);
        $this->assertNotNull($incident->reported_at);
    }

    public function test_update_event_status_creates_status_log()
    {
        $eventId = 1;
        $statusType = 'security_status';
        $newStatus = 'elevated';
        $statusData = [
            'description' => 'Security level elevated due to incident',
            'changed_by' => 'security-manager-001',
            'reason' => 'Unauthorized access attempt',
            'impact_level' => 'medium',
            'attendees_affected' => 100,
            'requires_action' => true,
            'recommended_actions' => 'Increase security patrols in VIP area'
        ];

        // Mock audit service
        $this->auditService->expects($this->once())
                          ->method('logActivity')
                          ->with('event_status_changed', $this->anything(), $this->anything());

        // Update status
        $statusLogId = $this->service->updateEventStatus($eventId, $statusType, $newStatus, $statusData);

        // Assertions
        $this->assertIsInt($statusLogId);
        $this->assertGreaterThan(0, $statusLogId);

        // Verify status log was created
        $statusLog = DB::table('event_status_logs')->where('id', $statusLogId)->first();
        $this->assertNotNull($statusLog);
        $this->assertEquals($this->testTenantId, $statusLog->tenant_id);
        $this->assertEquals($eventId, $statusLog->event_id);
        $this->assertEquals($statusType, $statusLog->status_type);
        $this->assertEquals($newStatus, $statusLog->current_status);
        $this->assertEquals('Security level elevated due to incident', $statusLog->status_description);
        $this->assertEquals('security-manager-001', $statusLog->changed_by);
        $this->assertEquals('medium', $statusLog->impact_level);
        $this->assertEquals(100, $statusLog->attendees_affected);
        $this->assertTrue($statusLog->requires_action);
        $this->assertNotNull($statusLog->status_changed_at);
    }

    public function test_generate_operational_report_includes_all_sections()
    {
        // Create test event
        $event = Event::factory()->create([
            'tenant_id' => $this->testTenantId,
            'title' => 'Test Event Report',
            'status' => 'completed'
        ]);

        // Create some test data for the report
        DB::table('incident_logs')->insert([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'incident_type' => 'technical',
            'severity' => 'medium',
            'title' => 'Sound System Issue',
            'description' => 'Microphone feedback in main stage',
            'reported_by' => 'tech-001',
            'reported_at' => now()->subHours(2),
            'resolved_at' => now()->subHour(),
            'status' => 'resolved',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('staff_assignments')->insert([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'staff_user_id' => 'staff-002',
            'role' => 'technical',
            'status' => 'checked_out',
            'performance_rating' => 4,
            'created_at' => now(),
            'updated_at' => now()
        ]);

        DB::table('operational_zones')->insert([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'zone_code' => 'MAIN',
            'zone_name' => 'Main Stage',
            'zone_type' => 'seating',
            'max_capacity' => 500,
            'current_occupancy' => 450,
            'occupancy_percentage' => 90.0,
            'status' => 'closed',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Generate report
        $report = $this->service->generateOperationalReport($event->id);

        // Assertions
        $this->assertIsArray($report);
        $this->assertEquals($event->id, $report['event_id']);
        $this->assertEquals('Test Event Report', $report['event_title']);
        $this->assertArrayHasKey('report_generated_at', $report);
        $this->assertArrayHasKey('incident_summary', $report);
        $this->assertArrayHasKey('staff_performance', $report);
        $this->assertArrayHasKey('zone_utilization', $report);
        $this->assertArrayHasKey('recommendations', $report);

        // Verify recommendations are generated
        $this->assertIsArray($report['recommendations']);
    }

    public function test_update_zone_occupancy_throws_exception_for_invalid_zone()
    {
        $this->expectException(EventManagementException::class);
        $this->expectExceptionMessage('Zone not found: 999');

        $this->service->updateZoneOccupancy(999, 10);
    }

    public function test_update_counter_queue_throws_exception_for_invalid_counter()
    {
        $this->expectException(EventManagementException::class);
        $this->expectExceptionMessage('Counter not found: 999');

        $this->service->updateCounterQueue(999, -5);
    }

    public function test_update_zone_occupancy_prevents_negative_occupancy()
    {
        // Create test zone with low occupancy
        $zoneId = DB::table('operational_zones')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'event_id' => 1,
            'zone_code' => 'TEST',
            'zone_name' => 'Test Zone',
            'zone_type' => 'general',
            'max_capacity' => 100,
            'current_occupancy' => 5,
            'occupancy_percentage' => 5.0,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        // Mock audit service
        $this->auditService->expects($this->once())
                          ->method('logActivity');

        // Try to decrease occupancy by more than current occupancy
        $result = $this->service->updateZoneOccupancy($zoneId, -10);

        // Should not go below 0
        $this->assertEquals(0, $result['current_occupancy']);
        $this->assertEquals(0.0, $result['occupancy_percentage']);
    }
}
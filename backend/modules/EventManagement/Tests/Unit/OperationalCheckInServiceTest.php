<?php

namespace Modules\EventManagement\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Exceptions\EventManagementException;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\BookingAttendee;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\Ticket;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Services\EventOperationsService;
use Modules\EventManagement\Services\OperationalCheckInService;
use Modules\EventManagement\Services\TenantSchemaManager;
use Modules\EventManagement\Services\TicketService;
use Tests\TestCase;

/**
 * OperationalCheckInService Unit Tests
 *
 * Tests the operational check-in functionality including single check-in,
 * bulk check-in, queue management, and capacity monitoring.
 */
class OperationalCheckInServiceTest extends TestCase
{
    use RefreshDatabase;

    private OperationalCheckInService $service;

    private TicketService $ticketService;

    private EventOperationsService $operationsService;

    private AuditService $auditService;

    private TenantSchemaManager $tenantManager;

    private string $testTenantId = 'test-tenant-001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->ticketService = $this->createMock(TicketService::class);
        $this->operationsService = $this->createMock(EventOperationsService::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->tenantManager = $this->createMock(TenantSchemaManager::class);

        $this->tenantManager->method('getCurrentTenantId')
            ->willReturn($this->testTenantId);

        $this->service = new OperationalCheckInService(
            $this->ticketService,
            $this->operationsService,
            $this->auditService,
            $this->tenantManager
        );
    }

    public function test_check_in_ticket_successfully_processes_valid_ticket()
    {
        // Create test data
        $event = Event::factory()->create([
            'tenant_id' => $this->testTenantId,
            'title' => 'Test Concert',
        ]);

        $booking = Booking::factory()->create([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'status' => 'confirmed',
        ]);

        $attendee = BookingAttendee::factory()->create([
            'booking_id' => $booking->id,
            'attendee_name' => 'John Doe',
        ]);

        $ticket = Ticket::factory()->create([
            'ticket_code' => 'TK12345678',
            'booking_id' => $booking->id,
            'attendee_id' => $attendee->id,
            'status' => 'active',
        ]);

        // Create counter
        $counterId = DB::table('check_in_counters')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'counter_code' => 'A1',
            'counter_name' => 'Main Entrance A1',
            'counter_type' => 'general',
            'status' => 'active',
            'current_queue_length' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create zone
        $zoneId = DB::table('operational_zones')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'zone_code' => 'MAIN',
            'zone_name' => 'Main Area',
            'zone_type' => 'general',
            'max_capacity' => 1000,
            'current_occupancy' => 500,
            'occupancy_percentage' => 50.0,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Mock ticket service validation
        $this->ticketService->expects($this->once())
            ->method('validateTicket')
            ->with('TK12345678')
            ->willReturn([
                'valid' => true,
                'ticket' => $ticket,
                'event' => $event,
                'attendee' => $attendee,
                'message' => 'Valid ticket',
            ]);

        // Mock operations service calls
        $this->operationsService->expects($this->once())
            ->method('updateCounterQueue')
            ->with($counterId, -1);

        $this->operationsService->expects($this->once())
            ->method('updateZoneOccupancy')
            ->with($zoneId, 1);

        // Mock audit service
        $this->auditService->expects($this->once())
            ->method('logActivity')
            ->with('operational_ticket_checkin', $this->anything(), $this->anything());

        // Perform check-in
        $result = $this->service->checkInTicket('TK12345678', $counterId, [
            'zone_code' => 'MAIN',
            'staff_id' => 'staff-001',
        ]);

        // Assertions
        $this->assertTrue($result['success']);
        $this->assertEquals('TK12345678', $result['ticket_code']);
        $this->assertEquals('John Doe', $result['attendee_name']);
        $this->assertEquals('Test Concert', $result['event_title']);
        $this->assertEquals('A1', $result['counter_code']);
        $this->assertEquals('MAIN', $result['zone_code']);
        $this->assertEquals('Check-in successful', $result['message']);

        // Verify ticket was updated
        $ticket->refresh();
        $this->assertEquals('checked_in', $ticket->status);
        $this->assertNotNull($ticket->checked_in_at);
        $this->assertEquals($counterId, $ticket->checked_in_counter_id);
        $this->assertEquals('MAIN', $ticket->checked_in_zone);
        $this->assertEquals('staff-001', $ticket->checked_in_by);
    }

    public function test_check_in_ticket_throws_exception_for_invalid_ticket()
    {
        // Create counter
        $counterId = DB::table('check_in_counters')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'event_id' => 1,
            'counter_code' => 'A1',
            'counter_name' => 'Main Entrance A1',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Mock ticket service validation
        $this->ticketService->expects($this->once())
            ->method('validateTicket')
            ->with('INVALID123')
            ->willReturn([
                'valid' => false,
                'message' => 'Ticket not found',
            ]);

        $this->expectException(EventManagementException::class);
        $this->expectExceptionMessage('Invalid ticket: Ticket not found');

        $this->service->checkInTicket('INVALID123', $counterId);
    }

    public function test_check_in_ticket_throws_exception_for_already_checked_in_ticket()
    {
        // Create test data
        $event = Event::factory()->create([
            'tenant_id' => $this->testTenantId,
            'title' => 'Test Event',
        ]);

        $booking = Booking::factory()->create([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
        ]);

        $attendee = BookingAttendee::factory()->create([
            'booking_id' => $booking->id,
        ]);

        $ticket = Ticket::factory()->create([
            'ticket_code' => 'TK87654321',
            'booking_id' => $booking->id,
            'attendee_id' => $attendee->id,
            'status' => 'checked_in',
        ]);

        $counterId = DB::table('check_in_counters')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'counter_code' => 'A1',
            'counter_name' => 'Main Entrance A1',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Mock ticket service validation
        $this->ticketService->expects($this->once())
            ->method('validateTicket')
            ->willReturn([
                'valid' => true,
                'ticket' => $ticket,
                'event' => $event,
                'attendee' => $attendee,
            ]);

        $this->expectException(EventManagementException::class);
        $this->expectExceptionMessage('Ticket TK87654321 is already checked in');

        $this->service->checkInTicket('TK87654321', $counterId);
    }

    public function test_bulk_check_in_processes_multiple_tickets()
    {
        // Create test data
        $event = Event::factory()->create([
            'tenant_id' => $this->testTenantId,
            'title' => 'Test Event',
        ]);

        $booking = Booking::factory()->create([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
        ]);

        $attendee1 = BookingAttendee::factory()->create(['booking_id' => $booking->id]);
        $attendee2 = BookingAttendee::factory()->create(['booking_id' => $booking->id]);

        $ticket1 = Ticket::factory()->create([
            'ticket_code' => 'TK11111111',
            'booking_id' => $booking->id,
            'attendee_id' => $attendee1->id,
            'status' => 'active',
        ]);

        $ticket2 = Ticket::factory()->create([
            'ticket_code' => 'TK22222222',
            'booking_id' => $booking->id,
            'attendee_id' => $attendee2->id,
            'status' => 'active',
        ]);

        $counterId = DB::table('check_in_counters')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'counter_code' => 'A1',
            'counter_name' => 'Main Entrance A1',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Mock ticket service validations
        $this->ticketService->expects($this->exactly(2))
            ->method('validateTicket')
            ->willReturnCallback(function ($ticketCode) use ($ticket1, $ticket2, $event, $attendee1, $attendee2) {
                if ($ticketCode === 'TK11111111') {
                    return [
                        'valid' => true,
                        'ticket' => $ticket1,
                        'event' => $event,
                        'attendee' => $attendee1,
                    ];
                } elseif ($ticketCode === 'TK22222222') {
                    return [
                        'valid' => true,
                        'ticket' => $ticket2,
                        'event' => $event,
                        'attendee' => $attendee2,
                    ];
                }
            });

        // Mock operations service calls
        $this->operationsService->expects($this->exactly(2))
            ->method('updateCounterQueue')
            ->with($counterId, -1);

        // Mock audit service calls
        $this->auditService->expects($this->exactly(3)) // 2 individual + 1 bulk
            ->method('logActivity');

        // Perform bulk check-in
        $result = $this->service->bulkCheckIn(['TK11111111', 'TK22222222'], $counterId, [
            'staff_id' => 'staff-001',
        ]);

        // Assertions
        $this->assertEquals(2, $result['total_processed']);
        $this->assertEquals(2, $result['successful']);
        $this->assertEquals(0, $result['failed']);
        $this->assertEquals(100.0, $result['success_rate']);
        $this->assertCount(2, $result['results']);
        $this->assertTrue($result['results'][0]['success']);
        $this->assertTrue($result['results'][1]['success']);
    }

    public function test_check_in_by_booking_reference_processes_group()
    {
        // Create test data
        $event = Event::factory()->create([
            'tenant_id' => $this->testTenantId,
            'title' => 'Test Event',
        ]);

        $booking = Booking::factory()->create([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'booking_reference' => 'BK123456',
        ]);

        $attendee1 = BookingAttendee::factory()->create(['booking_id' => $booking->id]);
        $attendee2 = BookingAttendee::factory()->create(['booking_id' => $booking->id]);

        $ticket1 = Ticket::factory()->create([
            'ticket_code' => 'TK11111111',
            'booking_id' => $booking->id,
            'attendee_id' => $attendee1->id,
            'status' => 'active',
        ]);

        $ticket2 = Ticket::factory()->create([
            'ticket_code' => 'TK22222222',
            'booking_id' => $booking->id,
            'attendee_id' => $attendee2->id,
            'status' => 'active',
        ]);

        $counterId = DB::table('check_in_counters')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'counter_code' => 'A1',
            'counter_name' => 'Main Entrance A1',
            'counter_type' => 'group',
            'supports_group_checkin' => true,
            'max_group_size' => 10,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Mock ticket service validations
        $this->ticketService->expects($this->exactly(2))
            ->method('validateTicket')
            ->willReturnCallback(function ($ticketCode) use ($ticket1, $ticket2, $event, $attendee1, $attendee2) {
                if ($ticketCode === 'TK11111111') {
                    return [
                        'valid' => true,
                        'ticket' => $ticket1,
                        'event' => $event,
                        'attendee' => $attendee1,
                    ];
                } elseif ($ticketCode === 'TK22222222') {
                    return [
                        'valid' => true,
                        'ticket' => $ticket2,
                        'event' => $event,
                        'attendee' => $attendee2,
                    ];
                }
            });

        // Mock operations service calls
        $this->operationsService->expects($this->exactly(2))
            ->method('updateCounterQueue');

        // Mock audit service calls
        $this->auditService->expects($this->exactly(3)) // 2 individual + 1 bulk
            ->method('logActivity');

        // Perform group check-in
        $result = $this->service->checkInByBookingReference('BK123456', $counterId, [
            'staff_id' => 'staff-001',
        ]);

        // Assertions
        $this->assertEquals('BK123456', $result['booking_reference']);
        $this->assertEquals(2, $result['group_size']);
        $this->assertArrayHasKey('bulk_result', $result);
        $this->assertEquals(2, $result['bulk_result']['successful']);
    }

    public function test_get_check_in_statistics_returns_accurate_data()
    {
        $eventId = 1;

        // Create test bookings and tickets
        $booking1 = Booking::factory()->create([
            'tenant_id' => $this->testTenantId,
            'event_id' => $eventId,
            'status' => 'confirmed',
        ]);

        $booking2 = Booking::factory()->create([
            'tenant_id' => $this->testTenantId,
            'event_id' => $eventId,
            'status' => 'confirmed',
        ]);

        // Create tickets - some checked in, some not
        Ticket::factory()->count(3)->create([
            'booking_id' => $booking1->id,
            'status' => 'active',
        ]);

        Ticket::factory()->count(2)->create([
            'booking_id' => $booking2->id,
            'status' => 'checked_in',
            'checked_in_at' => now()->subMinutes(30),
        ]);

        // Get statistics
        $stats = $this->service->getCheckInStatistics($eventId);

        // Assertions
        $this->assertEquals($eventId, $stats['event_id']);
        $this->assertEquals(5, $stats['total_tickets']);
        $this->assertEquals(2, $stats['checked_in_tickets']);
        $this->assertEquals(3, $stats['pending_check_ins']);
        $this->assertEquals(40.0, $stats['check_in_rate']); // 2/5 * 100
        $this->assertEquals(2, $stats['recent_velocity']); // Both checked in within last hour
        $this->assertArrayHasKey('counter_breakdown', $stats);
        $this->assertArrayHasKey('zone_breakdown', $stats);
        $this->assertArrayHasKey('last_updated', $stats);
    }

    public function test_get_queue_management_data_provides_comprehensive_info()
    {
        $eventId = 1;

        // Create test counters with different queue lengths
        DB::table('check_in_counters')->insert([
            [
                'tenant_id' => $this->testTenantId,
                'event_id' => $eventId,
                'counter_code' => 'A1',
                'counter_name' => 'Entrance A1',
                'status' => 'active',
                'current_queue_length' => 5,
                'average_processing_time' => 2.0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->testTenantId,
                'event_id' => $eventId,
                'counter_code' => 'A2',
                'counter_name' => 'Entrance A2',
                'status' => 'busy',
                'current_queue_length' => 20,
                'average_processing_time' => 2.5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->testTenantId,
                'event_id' => $eventId,
                'counter_code' => 'B1',
                'counter_name' => 'Entrance B1',
                'status' => 'closed',
                'current_queue_length' => 0,
                'average_processing_time' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Get queue management data
        $queueData = $this->service->getQueueManagementData($eventId);

        // Assertions
        $this->assertEquals($eventId, $queueData['event_id']);
        $this->assertEquals(25, $queueData['total_queue_length']); // 5 + 20 + 0
        $this->assertEquals(1, $queueData['active_counters']); // Only A1 is active
        $this->assertEquals(1, $queueData['busy_counters']); // A2 is busy
        $this->assertEquals(3, $queueData['total_counters']);
        $this->assertGreaterThan(0, $queueData['average_wait_time']);
        $this->assertArrayHasKey('bottlenecks', $queueData);
        $this->assertArrayHasKey('recommendations', $queueData);
        $this->assertCount(3, $queueData['counter_details']);

        // Check that A2 is identified as a bottleneck (queue > 15)
        $bottlenecks = collect($queueData['bottlenecks']);
        $this->assertTrue($bottlenecks->contains('counter_code', 'A2'));
    }

    public function test_monitor_zone_capacity_identifies_alerts()
    {
        $eventId = 1;

        // Create zones with different occupancy levels
        DB::table('operational_zones')->insert([
            [
                'tenant_id' => $this->testTenantId,
                'event_id' => $eventId,
                'zone_code' => 'VIP',
                'zone_name' => 'VIP Section',
                'zone_type' => 'vip',
                'max_capacity' => 100,
                'current_occupancy' => 100,
                'occupancy_percentage' => 100.0,
                'status' => 'full',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->testTenantId,
                'event_id' => $eventId,
                'zone_code' => 'MAIN',
                'zone_name' => 'Main Area',
                'zone_type' => 'general',
                'max_capacity' => 1000,
                'current_occupancy' => 950,
                'occupancy_percentage' => 95.0,
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'tenant_id' => $this->testTenantId,
                'event_id' => $eventId,
                'zone_code' => 'MERCH',
                'zone_name' => 'Merchandise Area',
                'zone_type' => 'merchandise',
                'max_capacity' => 200,
                'current_occupancy' => 100,
                'occupancy_percentage' => 50.0,
                'status' => 'open',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Monitor zone capacity
        $capacityData = $this->service->monitorZoneCapacity($eventId);

        // Assertions
        $this->assertEquals($eventId, $capacityData['event_id']);
        $this->assertEquals(3, $capacityData['total_zones']);
        $this->assertEquals(1, $capacityData['zones_at_capacity']); // VIP is at 100%
        $this->assertEquals(2, $capacityData['zones_nearly_full']); // VIP and MAIN >= 90%
        $this->assertCount(2, $capacityData['capacity_alerts']); // VIP critical, MAIN warning

        // Check alerts
        $alerts = collect($capacityData['capacity_alerts']);
        $vipAlert = $alerts->firstWhere('zone_code', 'VIP');
        $mainAlert = $alerts->firstWhere('zone_code', 'MAIN');

        $this->assertEquals('critical', $vipAlert['alert_level']);
        $this->assertEquals('warning', $mainAlert['alert_level']);

        // Check zone status
        $zoneStatus = collect($capacityData['zone_status']);
        $vipStatus = $zoneStatus->firstWhere('zone_code', 'VIP');
        $mainStatus = $zoneStatus->firstWhere('zone_code', 'MAIN');
        $merchStatus = $zoneStatus->firstWhere('zone_code', 'MERCH');

        $this->assertEquals('full', $vipStatus['occupancy_status']);
        $this->assertEquals('nearly_full', $mainStatus['occupancy_status']);
        $this->assertEquals('normal', $merchStatus['occupancy_status']);
    }

    public function test_emergency_check_in_override_works_with_proper_permissions()
    {
        // Create test data
        $event = Event::factory()->create([
            'tenant_id' => $this->testTenantId,
            'title' => 'Test Event',
        ]);

        $booking = Booking::factory()->create([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
        ]);

        $ticket = Ticket::factory()->create([
            'ticket_code' => 'TK99999999',
            'booking_id' => $booking->id,
            'status' => 'revoked', // Normally can't check in revoked tickets
        ]);

        $counterId = DB::table('check_in_counters')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'counter_code' => 'A1',
            'counter_name' => 'Main Entrance A1',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create staff assignment with override permission
        DB::table('staff_assignments')->insert([
            'tenant_id' => $this->testTenantId,
            'event_id' => $event->id,
            'staff_user_id' => 'staff-override',
            'role' => 'manager',
            'status' => 'active',
            'can_override' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Mock audit service
        $this->auditService->expects($this->once())
            ->method('logActivity')
            ->with('emergency_checkin_override', $this->anything(), $this->anything());

        // Perform emergency override
        $result = $this->service->emergencyCheckInOverride(
            'TK99999999',
            $counterId,
            'Customer lost original ticket',
            ['staff_id' => 'staff-override']
        );

        // Assertions
        $this->assertTrue($result['success']);
        $this->assertEquals('TK99999999', $result['ticket_code']);
        $this->assertEquals('revoked', $result['previous_status']);
        $this->assertEquals('Customer lost original ticket', $result['override_reason']);
        $this->assertEquals('Emergency override successful', $result['message']);

        // Verify ticket was updated
        $ticket->refresh();
        $this->assertEquals('checked_in', $ticket->status);
        $this->assertTrue($ticket->is_override);
        $this->assertEquals('Customer lost original ticket', $ticket->override_reason);
        $this->assertEquals('staff-override', $ticket->checked_in_by);
    }

    public function test_emergency_override_throws_exception_without_permissions()
    {
        $ticket = Ticket::factory()->create([
            'ticket_code' => 'TK88888888',
            'status' => 'revoked',
        ]);

        $counterId = DB::table('check_in_counters')->insertGetId([
            'tenant_id' => $this->testTenantId,
            'event_id' => 1,
            'counter_code' => 'A1',
            'counter_name' => 'Main Entrance A1',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // No staff assignment with override permission exists

        $this->expectException(EventManagementException::class);
        $this->expectExceptionMessage('Staff member does not have override permissions');

        $this->service->emergencyCheckInOverride(
            'TK88888888',
            $counterId,
            'Emergency override',
            ['staff_id' => 'staff-no-permission']
        );
    }
}

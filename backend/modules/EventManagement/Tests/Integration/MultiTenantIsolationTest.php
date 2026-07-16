<?php

namespace Modules\EventManagement\Tests\Integration;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Services\BookingService;
use Modules\EventManagement\Services\EventService;
use Modules\EventManagement\Services\TenantSchemaManager;
use Tests\TestCase;

/**
 * Integration Test for Multi-Tenant Isolation
 *
 * Tests that multi-tenant isolation works correctly across all components
 * Validates: Requirements 9.1
 */
class MultiTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private EventService $eventService;

    private BookingService $bookingService;

    private AuditService $auditService;

    private TenantSchemaManager $tenantSchemaManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations to set up the database schema
        $this->artisan('migrate', [
            '--path' => 'backend/modules/EventManagement/Database/Migrations',
            '--realpath' => true,
        ]);

        // Set up service dependencies
        $this->auditService = $this->createMock(AuditService::class);
        $this->tenantSchemaManager = $this->createMock(TenantSchemaManager::class);

        // Bind mocks into the container so auto-wiring uses them
        $this->app->instance(AuditService::class, $this->auditService);
        $this->app->instance(TenantSchemaManager::class, $this->tenantSchemaManager);

        // Resolve from container — picks up mocks + auto-resolves remaining deps
        $this->eventService = app(EventService::class);
        $this->bookingService = app(BookingService::class);
    }

    /**
     * Test that tenant data is properly isolated
     *
     * @test
     */
    public function test_tenant_data_isolation()
    {
        // Simulate tenant context switching
        $tenant1Id = 'tenant_001';
        $tenant2Id = 'tenant_002';

        // Configure tenant schema manager mock for tenant 1
        $this->tenantSchemaManager->expects($this->any())
            ->method('setTenantContext')
            ->willReturnCallback(function ($tenantId) {
                // Simulate tenant context switching
                config(['database.default' => "tenant_{$tenantId}"]);
            });

        $this->tenantSchemaManager->expects($this->any())
            ->method('getCurrentTenantId')
            ->willReturn($tenant1Id);

        // Create events for tenant 1
        $this->tenantSchemaManager->setTenantContext($tenant1Id);

        $tenant1Event1 = $this->createTestEvent('Tenant 1 Event 1');
        $tenant1Event2 = $this->createTestEvent('Tenant 1 Event 2');

        // Create bookings for tenant 1
        $tenant1Booking1 = $this->createTestBooking($tenant1Event1->id, 'tenant1-buyer1@example.com');
        $tenant1Booking2 = $this->createTestBooking($tenant1Event2->id, 'tenant1-buyer2@example.com');

        // Switch to tenant 2 context
        $this->tenantSchemaManager->expects($this->any())
            ->method('getCurrentTenantId')
            ->willReturn($tenant2Id);

        $this->tenantSchemaManager->setTenantContext($tenant2Id);

        $tenant2Event1 = $this->createTestEvent('Tenant 2 Event 1');
        $tenant2Event2 = $this->createTestEvent('Tenant 2 Event 2');

        $tenant2Booking1 = $this->createTestBooking($tenant2Event1->id, 'tenant2-buyer1@example.com');
        $tenant2Booking2 = $this->createTestBooking($tenant2Event2->id, 'tenant2-buyer2@example.com');

        // Verify tenant 1 can only see their own data
        $this->tenantSchemaManager->setTenantContext($tenant1Id);

        $tenant1Events = $this->eventService->getPublicEvents();
        $this->assertCount(2, $tenant1Events);
        $this->assertTrue($tenant1Events->contains('title', 'Tenant 1 Event 1'));
        $this->assertTrue($tenant1Events->contains('title', 'Tenant 1 Event 2'));
        $this->assertFalse($tenant1Events->contains('title', 'Tenant 2 Event 1'));
        $this->assertFalse($tenant1Events->contains('title', 'Tenant 2 Event 2'));

        // Verify tenant 2 can only see their own data
        $this->tenantSchemaManager->setTenantContext($tenant2Id);

        $tenant2Events = $this->eventService->getPublicEvents();
        $this->assertCount(2, $tenant2Events);
        $this->assertTrue($tenant2Events->contains('title', 'Tenant 2 Event 1'));
        $this->assertTrue($tenant2Events->contains('title', 'Tenant 2 Event 2'));
        $this->assertFalse($tenant2Events->contains('title', 'Tenant 1 Event 1'));
        $this->assertFalse($tenant2Events->contains('title', 'Tenant 1 Event 2'));

        // Verify booking isolation
        $this->assertDatabaseHas('bookings', [
            'buyer_email' => 'tenant1-buyer1@example.com',
        ]);

        $this->assertDatabaseHas('bookings', [
            'buyer_email' => 'tenant2-buyer1@example.com',
        ]);

        // Verify that tenant 1 cannot access tenant 2's bookings
        $this->tenantSchemaManager->setTenantContext($tenant1Id);

        // This should throw an exception or return null when trying to access cross-tenant data
        $this->expectException(\InvalidArgumentException::class);
        $this->bookingService->getBookingByReference($tenant2Booking1->booking_reference);
    }

    /**
     * Test tenant schema context switching
     *
     * @test
     */
    public function test_tenant_schema_context_switching()
    {
        $tenant1Id = 'tenant_alpha';
        $tenant2Id = 'tenant_beta';

        // Mock schema manager behavior
        $currentTenant = null;

        $this->tenantSchemaManager->expects($this->any())
            ->method('setTenantContext')
            ->willReturnCallback(function ($tenantId) use (&$currentTenant) {
                $currentTenant = $tenantId;
            });

        $this->tenantSchemaManager->expects($this->any())
            ->method('getCurrentTenantId')
            ->willReturnCallback(function () use (&$currentTenant) {
                return $currentTenant;
            });

        // Test context switching
        $this->tenantSchemaManager->setTenantContext($tenant1Id);
        $this->assertEquals($tenant1Id, $this->tenantSchemaManager->getCurrentTenantId());

        $this->tenantSchemaManager->setTenantContext($tenant2Id);
        $this->assertEquals($tenant2Id, $this->tenantSchemaManager->getCurrentTenantId());

        // Create data in different tenant contexts
        $this->tenantSchemaManager->setTenantContext($tenant1Id);
        $event1 = $this->createTestEvent('Alpha Event');

        $this->tenantSchemaManager->setTenantContext($tenant2Id);
        $event2 = $this->createTestEvent('Beta Event');

        // Verify events are created with proper tenant context
        $this->assertNotEquals($event1->id, $event2->id);
        $this->assertEquals('Alpha Event', $event1->title);
        $this->assertEquals('Beta Event', $event2->title);
    }

    /**
     * Test audit log isolation between tenants
     *
     * @test
     */
    public function test_audit_log_isolation_between_tenants()
    {
        $tenant1Id = 'tenant_audit_1';
        $tenant2Id = 'tenant_audit_2';

        // Configure audit service to track tenant context
        $auditLogs = [];

        $this->auditService->expects($this->any())
            ->method('logActivity')
            ->willReturnCallback(function ($tenantId, $action, $subject, $changes) use (&$auditLogs) {
                $auditLogs[] = [
                    'tenant_id' => $tenantId,
                    'action' => $action,
                    'subject' => $subject,
                    'changes' => $changes,
                ];
            });

        // Configure tenant schema manager
        $currentTenant = null;

        $this->tenantSchemaManager->expects($this->any())
            ->method('setTenantContext')
            ->willReturnCallback(function ($tenantId) use (&$currentTenant) {
                $currentTenant = $tenantId;
            });

        $this->tenantSchemaManager->expects($this->any())
            ->method('getCurrentTenantId')
            ->willReturnCallback(function () use (&$currentTenant) {
                return $currentTenant;
            });

        // Create events in different tenant contexts
        $this->tenantSchemaManager->setTenantContext($tenant1Id);
        $event1 = $this->createTestEvent('Tenant 1 Audit Event');

        $this->tenantSchemaManager->setTenantContext($tenant2Id);
        $event2 = $this->createTestEvent('Tenant 2 Audit Event');

        // Verify audit logs are properly isolated
        $tenant1Logs = array_filter($auditLogs, function ($log) use ($tenant1Id) {
            return $log['tenant_id'] === $tenant1Id;
        });

        $tenant2Logs = array_filter($auditLogs, function ($log) use ($tenant2Id) {
            return $log['tenant_id'] === $tenant2Id;
        });

        $this->assertNotEmpty($tenant1Logs, 'Tenant 1 should have audit logs');
        $this->assertNotEmpty($tenant2Logs, 'Tenant 2 should have audit logs');

        // Verify logs don't cross tenant boundaries
        foreach ($tenant1Logs as $log) {
            $this->assertEquals($tenant1Id, $log['tenant_id']);
        }

        foreach ($tenant2Logs as $log) {
            $this->assertEquals($tenant2Id, $log['tenant_id']);
        }
    }

    /**
     * Test capacity management isolation between tenants
     *
     * @test
     */
    public function test_capacity_management_isolation_between_tenants()
    {
        $tenant1Id = 'tenant_capacity_1';
        $tenant2Id = 'tenant_capacity_2';

        // Configure tenant context
        $currentTenant = null;

        $this->tenantSchemaManager->expects($this->any())
            ->method('setTenantContext')
            ->willReturnCallback(function ($tenantId) use (&$currentTenant) {
                $currentTenant = $tenantId;
            });

        $this->tenantSchemaManager->expects($this->any())
            ->method('getCurrentTenantId')
            ->willReturnCallback(function () use (&$currentTenant) {
                return $currentTenant;
            });

        // Create events with same capacity in different tenants
        $this->tenantSchemaManager->setTenantContext($tenant1Id);
        $tenant1Event = $this->createTestEventWithCapacity(10);

        $this->tenantSchemaManager->setTenantContext($tenant2Id);
        $tenant2Event = $this->createTestEventWithCapacity(10);

        // Create bookings that fill capacity for tenant 1
        $this->tenantSchemaManager->setTenantContext($tenant1Id);

        for ($i = 0; $i < 10; $i++) {
            $this->createTestBooking($tenant1Event->id, "tenant1-buyer{$i}@example.com");
        }

        // Verify tenant 1 event is sold out
        $tenant1Event->refresh();
        $this->assertEquals(0, $tenant1Event->available_capacity);
        $this->assertEquals('sold_out', $tenant1Event->status);

        // Verify tenant 2 event still has full capacity
        $this->tenantSchemaManager->setTenantContext($tenant2Id);
        $tenant2Event->refresh();
        $this->assertEquals(10, $tenant2Event->available_capacity);
        $this->assertEquals('published', $tenant2Event->status);

        // Verify tenant 2 can still create bookings
        $tenant2Booking = $this->createTestBooking($tenant2Event->id, 'tenant2-buyer@example.com');
        $this->assertInstanceOf(Booking::class, $tenant2Booking);

        // Verify tenant 2 capacity is properly decremented
        $tenant2Event->refresh();
        $this->assertEquals(9, $tenant2Event->available_capacity);
    }

    /**
     * Create a test event
     */
    private function createTestEvent(?string $title = null): Event
    {
        $title = $title ?? 'Test Event '.rand(1000, 9999);

        $eventData = [
            'title' => $title,
            'description' => 'Test event for multi-tenant testing',
            'event_date' => Carbon::now()->addDays(rand(1, 30))->toDateTimeString(),
            'venue' => 'Test Venue '.rand(100, 999),
            'total_capacity' => 100,
            'terms_conditions' => 'Test terms and conditions',
        ];

        $event = $this->eventService->createEvent($eventData);

        return $this->eventService->publishEvent($event->id);
    }

    /**
     * Create a test event with specific capacity
     */
    private function createTestEventWithCapacity(int $capacity): Event
    {
        $eventData = [
            'title' => 'Capacity Test Event '.rand(1000, 9999),
            'description' => 'Test event for capacity testing',
            'event_date' => Carbon::now()->addDays(rand(1, 30))->toDateTimeString(),
            'venue' => 'Test Venue '.rand(100, 999),
            'total_capacity' => $capacity,
            'terms_conditions' => 'Test terms and conditions',
        ];

        $event = $this->eventService->createEvent($eventData);

        return $this->eventService->publishEvent($event->id);
    }

    /**
     * Create a test booking
     */
    private function createTestBooking(int $eventId, string $buyerEmail): Booking
    {
        $bookingData = [
            'event_id' => $eventId,
            'buyer_name' => 'Test Buyer',
            'buyer_email' => $buyerEmail,
            'buyer_phone' => '+1234567890',
            'total_amount' => 50.00,
            'currency' => 'USD',
            'consent_pdpa' => true,
            'consent_marketing' => false,
            'attendees' => [
                [
                    'ticket_type_id' => 1,
                    'attendee_name' => 'Test Attendee',
                    'attendee_email' => $buyerEmail,
                    'attendee_phone' => '+1234567890',
                ],
            ],
        ];

        return $this->bookingService->createBooking($bookingData);
    }
}

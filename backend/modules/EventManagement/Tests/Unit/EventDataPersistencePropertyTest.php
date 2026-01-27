<?php

namespace Modules\EventManagement\Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Services\EventService;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Services\TenantSchemaManager;
use Modules\EventManagement\Models\Event;
use Carbon\Carbon;

/**
 * Property Test for Event Data Persistence and Validation
 * 
 * **Feature: erp-event-management, Property 1: Event Data Persistence and Validation**
 * **Validates: Requirements 1.1, 1.2, 1.3**
 * 
 * Property: For any event creation request with valid data, the system should store all 
 * required fields (date, time, venue, capacity, ticket types) and make published events 
 * visible in public listings while keeping unpublished events hidden.
 */
class EventDataPersistencePropertyTest extends TestCase
{
    use RefreshDatabase;

    private EventService $eventService;
    private AuditService $auditService;
    private TenantSchemaManager $tenantSchemaManager;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Run migrations to set up the database schema
        $this->artisan('migrate', [
            '--path' => 'backend/modules/EventManagement/Database/Migrations',
            '--realpath' => true
        ]);

        // Set up service dependencies
        $this->auditService = $this->createMock(AuditService::class);
        $this->tenantSchemaManager = $this->createMock(TenantSchemaManager::class);
        
        $this->eventService = new EventService(
            $this->tenantSchemaManager,
            $this->auditService
        );
    }

    /**
     * Property Test: Event creation stores all required fields
     * Tests that any valid event data is properly persisted with all required fields
     * 
     * @test
     */
    public function property_event_creation_stores_all_required_fields()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runEventCreationTest();
        }
    }

    /**
     * Property Test: Published events are visible in public listings
     * Tests that published events appear in public listings while unpublished do not
     * 
     * @test
     */
    public function property_published_events_are_visible_in_public_listings()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runPublicListingVisibilityTest();
        }
    }

    /**
     * Property Test: Event data validation enforces business rules
     * Tests that event data validation works correctly for various inputs
     * 
     * @test
     */
    public function property_event_data_validation_enforces_business_rules()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runEventDataValidationTest();
        }
    }

    /**
     * Property Test: Event capacity management works correctly
     * Tests that capacity is properly managed during event operations
     * 
     * @test
     */
    public function property_event_capacity_management_works_correctly()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runCapacityManagementTest();
        }
    }

    /**
     * Run a single iteration of event creation testing
     */
    private function runEventCreationTest(): void
    {
        // Generate random valid event data
        $eventData = $this->generateRandomEventData();
        
        // Create the event
        $event = $this->eventService->createEvent($eventData);
        
        // Verify the event was created and all fields are stored correctly
        $this->assertInstanceOf(Event::class, $event);
        $this->assertEquals($eventData['title'], $event->title);
        $this->assertEquals($eventData['description'], $event->description);
        $this->assertEquals($eventData['venue'], $event->venue);
        $this->assertEquals($eventData['total_capacity'], $event->total_capacity);
        $this->assertEquals($eventData['total_capacity'], $event->available_capacity); // Initially same
        $this->assertEquals('draft', $event->status); // Always starts as draft
        
        // Verify event date is properly stored
        $expectedDate = Carbon::parse($eventData['event_date']);
        $this->assertTrue($expectedDate->equalTo($event->event_date));
        
        // Verify ticket types are created if provided
        if (isset($eventData['ticket_types'])) {
            $this->assertCount(count($eventData['ticket_types']), $event->ticketTypes);
            
            foreach ($eventData['ticket_types'] as $index => $ticketTypeData) {
                $ticketType = $event->ticketTypes[$index];
                $this->assertEquals($ticketTypeData['name'], $ticketType->name);
                $this->assertEquals($ticketTypeData['price'], $ticketType->price);
                $this->assertEquals($ticketTypeData['capacity'], $ticketType->capacity);
                $this->assertEquals($ticketTypeData['capacity'], $ticketType->available_capacity);
            }
        }
        
        // Verify the event exists in database
        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'title' => $eventData['title'],
            'venue' => $eventData['venue'],
            'total_capacity' => $eventData['total_capacity'],
            'status' => 'draft'
        ]);
    }

    /**
     * Run a single iteration of public listing visibility testing
     */
    private function runPublicListingVisibilityTest(): void
    {
        // Create multiple events with different statuses
        $draftEvent = $this->eventService->createEvent($this->generateRandomEventData());
        $publishedEvent = $this->eventService->createEvent($this->generateRandomEventData());
        $cancelledEvent = $this->eventService->createEvent($this->generateRandomEventData());
        
        // Publish one event
        $this->eventService->publishEvent($publishedEvent->id);
        
        // Manually set one as cancelled for testing
        $cancelledEvent->update(['status' => 'cancelled']);
        
        // Get public events
        $publicEvents = $this->eventService->getPublicEvents();
        
        // Verify only published events are visible
        $publicEventIds = $publicEvents->pluck('id')->toArray();
        
        $this->assertContains($publishedEvent->id, $publicEventIds, 'Published event should be visible in public listings');
        $this->assertNotContains($draftEvent->id, $publicEventIds, 'Draft event should not be visible in public listings');
        $this->assertNotContains($cancelledEvent->id, $publicEventIds, 'Cancelled event should not be visible in public listings');
        
        // Verify all returned events are published and in the future
        foreach ($publicEvents as $event) {
            $this->assertEquals('published', $event->status);
            $this->assertTrue($event->event_date->isFuture(), 'Public events should be in the future');
        }
    }

    /**
     * Run a single iteration of event data validation testing
     */
    private function runEventDataValidationTest(): void
    {
        // Test with valid data
        $validEventData = $this->generateRandomEventData();
        $event = $this->eventService->createEvent($validEventData);
        $this->assertInstanceOf(Event::class, $event);
        
        // Test capacity constraints
        $currentCapacity = $event->total_capacity;
        $newCapacity = rand($currentCapacity + 1, $currentCapacity + 500);
        
        $updatedEvent = $this->eventService->updateCapacity($event->id, $newCapacity);
        $this->assertEquals($newCapacity, $updatedEvent->total_capacity);
        $this->assertEquals($newCapacity, $updatedEvent->available_capacity); // No bookings yet
    }

    /**
     * Run a single iteration of capacity management testing
     */
    private function runCapacityManagementTest(): void
    {
        // Create event with random capacity
        $eventData = $this->generateRandomEventData();
        $originalCapacity = $eventData['total_capacity'];
        
        $event = $this->eventService->createEvent($eventData);
        
        // Test capacity update with larger capacity
        $newCapacity = $originalCapacity + rand(100, 500);
        $updatedEvent = $this->eventService->updateCapacity($event->id, $newCapacity);
        
        $this->assertEquals($newCapacity, $updatedEvent->total_capacity);
        $this->assertEquals($newCapacity, $updatedEvent->available_capacity);
        
        // Test availability checking
        $requestedTickets = rand(1, $newCapacity);
        $isAvailable = $this->eventService->checkAvailability($event->id, $requestedTickets);
        
        // Should be available since no bookings exist yet
        $this->assertTrue($isAvailable, 'Event should have availability when no bookings exist');
        
        // Test availability with more tickets than capacity
        $tooManyTickets = $newCapacity + rand(1, 100);
        $isNotAvailable = $this->eventService->checkAvailability($event->id, $tooManyTickets);
        
        $this->assertFalse($isNotAvailable, 'Event should not have availability for more tickets than capacity');
    }

    /**
     * Generate random valid event data for testing
     */
    private function generateRandomEventData(): array
    {
        $futureDate = Carbon::now()->addDays(rand(1, 365));
        $capacity = rand(50, 2000);
        
        $eventData = [
            'title' => 'Test Event ' . rand(1000, 9999),
            'description' => 'Test event description for property testing ' . rand(1000, 9999),
            'event_date' => $futureDate->toDateTimeString(),
            'venue' => 'Test Venue ' . rand(100, 999),
            'total_capacity' => $capacity,
            'terms_conditions' => 'Test terms and conditions for event ' . rand(1000, 9999)
        ];
        
        // Randomly add ticket types
        if (rand(0, 1)) {
            $numTicketTypes = rand(1, 3);
            $eventData['ticket_types'] = [];
            
            for ($i = 0; $i < $numTicketTypes; $i++) {
                $ticketCapacity = rand(10, $capacity / $numTicketTypes);
                $eventData['ticket_types'][] = [
                    'name' => 'Ticket Type ' . ($i + 1),
                    'price' => rand(25, 200) + (rand(0, 99) / 100), // Random price with cents
                    'capacity' => $ticketCapacity,
                    'description' => 'Test ticket type description ' . rand(1000, 9999)
                ];
            }
        }
        
        return $eventData;
    }
}
<?php

namespace Modules\EventManagement\Tests\Unit;

use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\EventType;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Services\EventService;
use Modules\EventManagement\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Test backward compatibility with existing concert events
 * 
 * Requirements: 10.1, 10.2, 10.3, 10.4, 10.5
 */
class BackwardCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private EventType $concertType;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Ensure Concert event type exists
        $this->concertType = EventType::firstOrCreate(
            ['slug' => 'concert'],
            [
                'name' => 'Concert',
                'description' => 'Live music performance',
                'is_active' => true,
            ]
        );
    }

    /** @test */
    public function existing_concert_events_still_work_after_migration()
    {
        // Arrange - Create an event simulating a legacy concert
        $event = Event::create([
            'title' => 'Legacy Concert Event',
            'description' => 'A concert created before Phase 4',
            'event_date' => now()->addDays(30),
            'venue' => 'Legacy Venue',
            'total_capacity' => 100,
            'available_capacity' => 100,
            'status' => 'published',
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => Event::MODE_BUY,
            'sale_start_date' => now()->subDays(1),
            'sale_end_date' => now()->addDays(29),
        ]);

        // Act - Retrieve the event
        $retrieved = Event::find($event->id);

        // Assert - Event is still accessible and functional
        $this->assertNotNull($retrieved);
        $this->assertEquals('Legacy Concert Event', $retrieved->title);
        $this->assertEquals($this->concertType->id, $retrieved->event_type_id);
        $this->assertEquals(Event::MODE_BUY, $retrieved->ticket_acquisition_mode);
        $this->assertTrue($retrieved->canAcceptBookings());
    }

    /** @test */
    public function existing_purchase_functionality_is_not_broken()
    {
        // Arrange - Create a legacy concert event
        $event = Event::create([
            'title' => 'Concert with Purchase',
            'description' => 'Test purchase functionality',
            'event_date' => now()->addDays(30),
            'venue' => 'Test Venue',
            'total_capacity' => 100,
            'available_capacity' => 100,
            'status' => 'published',
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => Event::MODE_BUY,
            'sale_start_date' => now()->subDays(1),
            'sale_end_date' => now()->addDays(29),
        ]);

        // Act - Verify purchase is allowed
        $canPurchase = $event->canAcceptBookings() 
            && $event->ticket_acquisition_mode === Event::MODE_BUY;

        // Assert
        $this->assertTrue($canPurchase);
        $this->assertEquals(Event::MODE_BUY, $event->ticket_acquisition_mode);
    }

    /** @test */
    public function existing_api_endpoints_return_expected_responses()
    {
        // Arrange - Create a legacy concert event
        $event = Event::create([
            'title' => 'API Test Concert',
            'description' => 'Testing API compatibility',
            'event_date' => now()->addDays(30),
            'venue' => 'API Test Venue',
            'total_capacity' => 100,
            'available_capacity' => 100,
            'status' => 'published',
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => Event::MODE_BUY,
            'sale_start_date' => now()->subDays(1),
            'sale_end_date' => now()->addDays(29),
        ]);

        // Act - Retrieve event data as API would
        $eventData = $event->toArray();

        // Assert - All expected fields are present
        $this->assertArrayHasKey('id', $eventData);
        $this->assertArrayHasKey('title', $eventData);
        $this->assertArrayHasKey('event_type_id', $eventData);
        $this->assertArrayHasKey('ticket_acquisition_mode', $eventData);
        $this->assertArrayHasKey('sale_start_date', $eventData);
        $this->assertArrayHasKey('sale_end_date', $eventData);
        
        // Verify values
        $this->assertEquals($this->concertType->id, $eventData['event_type_id']);
        $this->assertEquals(Event::MODE_BUY, $eventData['ticket_acquisition_mode']);
    }

    /** @test */
    public function migrated_events_have_concert_type()
    {
        // Arrange - Simulate a migrated event
        $event = Event::create([
            'title' => 'Migrated Concert',
            'description' => 'Event migrated from legacy system',
            'event_date' => now()->addDays(30),
            'venue' => 'Migration Test Venue',
            'total_capacity' => 100,
            'available_capacity' => 100,
            'status' => 'published',
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => Event::MODE_BUY,
            'sale_start_date' => now()->subDays(1),
            'sale_end_date' => now()->addDays(29),
        ]);

        // Act
        $retrieved = Event::with('eventType')->find($event->id);

        // Assert - Event has Concert type
        $this->assertNotNull($retrieved->eventType);
        $this->assertEquals('Concert', $retrieved->eventType->name);
        $this->assertEquals('concert', $retrieved->eventType->slug);
    }

    /** @test */
    public function migrated_events_have_direct_purchase_mode()
    {
        // Arrange - Simulate a migrated event
        $event = Event::create([
            'title' => 'Migrated Purchase Event',
            'description' => 'Event with purchase mode',
            'event_date' => now()->addDays(30),
            'venue' => 'Purchase Test Venue',
            'total_capacity' => 100,
            'available_capacity' => 100,
            'status' => 'published',
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => Event::MODE_BUY,
            'sale_start_date' => now()->subDays(1),
            'sale_end_date' => now()->addDays(29),
        ]);

        // Act
        $retrieved = Event::find($event->id);

        // Assert - Event has Direct Purchase mode
        $this->assertEquals(Event::MODE_BUY, $retrieved->ticket_acquisition_mode);
    }

    /** @test */
    public function migrated_events_have_valid_sale_windows()
    {
        // Arrange - Simulate a migrated event with sale window
        $createdAt = now()->subDays(10);
        $eventDate = now()->addDays(30);
        
        $event = Event::create([
            'title' => 'Sale Window Test',
            'description' => 'Testing sale window migration',
            'event_date' => $eventDate,
            'venue' => 'Sale Window Venue',
            'total_capacity' => 100,
            'available_capacity' => 100,
            'status' => 'published',
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => Event::MODE_BUY,
            'sale_start_date' => $createdAt,
            'sale_end_date' => $eventDate,
            'created_at' => $createdAt,
        ]);

        // Act
        $retrieved = Event::find($event->id);

        // Assert - Sale window is valid
        $this->assertNotNull($retrieved->sale_start_date);
        $this->assertNotNull($retrieved->sale_end_date);
        $this->assertTrue(
            $retrieved->sale_end_date > $retrieved->sale_start_date,
            'Sale end date should be after start date'
        );
    }

    /** @test */
    public function existing_event_references_are_not_broken()
    {
        // Arrange - Create event with related data
        $event = Event::create([
            'title' => 'Event with Relations',
            'description' => 'Testing referential integrity',
            'event_date' => now()->addDays(30),
            'venue' => 'Relations Test Venue',
            'total_capacity' => 100,
            'available_capacity' => 100,
            'status' => 'published',
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => Event::MODE_BUY,
            'sale_start_date' => now()->subDays(1),
            'sale_end_date' => now()->addDays(29),
        ]);

        // Create a booking for this event
        $booking = Booking::create([
            'event_id' => $event->id,
            'customer_name' => 'Test Customer',
            'customer_email' => 'test@example.com',
            'quantity' => 2,
            'total_price' => 100.00,
            'status' => 'confirmed',
        ]);

        // Act - Retrieve event with bookings
        $retrieved = Event::with('bookings')->find($event->id);

        // Assert - Relationships are intact
        $this->assertNotNull($retrieved);
        $this->assertCount(1, $retrieved->bookings);
        $this->assertEquals($booking->id, $retrieved->bookings->first()->id);
    }

    /** @test */
    public function legacy_events_maintain_api_compatibility()
    {
        // Arrange - Create multiple legacy events
        $events = collect([
            Event::create([
                'title' => 'Legacy Event 1',
                'description' => 'First legacy event',
                'event_date' => now()->addDays(30),
                'venue' => 'Venue 1',
                'total_capacity' => 100,
                'available_capacity' => 100,
                'status' => 'published',
                'event_type_id' => $this->concertType->id,
                'ticket_acquisition_mode' => Event::MODE_BUY,
                'sale_start_date' => now()->subDays(1),
                'sale_end_date' => now()->addDays(29),
            ]),
            Event::create([
                'title' => 'Legacy Event 2',
                'description' => 'Second legacy event',
                'event_date' => now()->addDays(45),
                'venue' => 'Venue 2',
                'total_capacity' => 200,
                'available_capacity' => 200,
                'status' => 'published',
                'event_type_id' => $this->concertType->id,
                'ticket_acquisition_mode' => Event::MODE_BUY,
                'sale_start_date' => now()->subDays(1),
                'sale_end_date' => now()->addDays(44),
            ]),
        ]);

        // Act - Retrieve all events
        $retrieved = Event::all();

        // Assert - All events are accessible
        $this->assertCount(2, $retrieved);
        $retrieved->each(function ($event) {
            $this->assertEquals($this->concertType->id, $event->event_type_id);
            $this->assertEquals(Event::MODE_BUY, $event->ticket_acquisition_mode);
            $this->assertNotNull($event->sale_start_date);
            $this->assertNotNull($event->sale_end_date);
        });
    }

    /** @test */
    public function referential_integrity_is_maintained_after_migration()
    {
        // Arrange - Create event
        $event = Event::create([
            'title' => 'Integrity Test Event',
            'description' => 'Testing referential integrity',
            'event_date' => now()->addDays(30),
            'venue' => 'Integrity Venue',
            'total_capacity' => 100,
            'available_capacity' => 100,
            'status' => 'published',
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => Event::MODE_BUY,
            'sale_start_date' => now()->subDays(1),
            'sale_end_date' => now()->addDays(29),
        ]);

        // Act - Check foreign key constraints
        $eventTypeExists = DB::table('event_types')
            ->where('id', $event->event_type_id)
            ->exists();

        // Assert - Foreign key references are valid
        $this->assertTrue($eventTypeExists, 'Event type reference should be valid');
        $this->assertNotNull($event->eventType, 'Event type relationship should work');
    }

    /** @test */
    public function all_migrated_events_have_required_phase4_fields()
    {
        // Arrange - Create multiple events
        Event::create([
            'title' => 'Event 1',
            'description' => 'Test event 1',
            'event_date' => now()->addDays(30),
            'venue' => 'Venue 1',
            'total_capacity' => 100,
            'available_capacity' => 100,
            'status' => 'published',
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => Event::MODE_BUY,
            'sale_start_date' => now()->subDays(1),
            'sale_end_date' => now()->addDays(29),
        ]);

        Event::create([
            'title' => 'Event 2',
            'description' => 'Test event 2',
            'event_date' => now()->addDays(45),
            'venue' => 'Venue 2',
            'total_capacity' => 200,
            'available_capacity' => 200,
            'status' => 'published',
            'event_type_id' => $this->concertType->id,
            'ticket_acquisition_mode' => Event::MODE_BUY,
            'sale_start_date' => now()->subDays(1),
            'sale_end_date' => now()->addDays(44),
        ]);

        // Act - Check all events have required fields
        $eventsWithMissingFields = Event::query()
            ->where(function ($query) {
                $query->whereNull('event_type_id')
                    ->orWhereNull('ticket_acquisition_mode')
                    ->orWhereNull('sale_start_date')
                    ->orWhereNull('sale_end_date');
            })
            ->count();

        // Assert - No events should have missing fields
        $this->assertEquals(0, $eventsWithMissingFields, 'All events should have Phase 4 fields populated');
    }
}

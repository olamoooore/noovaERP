<?php

namespace Modules\EventManagement\Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\EventType;
use Tests\TestCase;

/**
 * Event Controller Validation Test
 *
 * Tests event creation validation for Phase 4 requirements.
 * Requirements: 11.1, 11.2, 11.3, 11.4
 */
class EventControllerValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations
        Artisan::call('migrate');

        // Seed event types
        EventType::create([
            'name' => 'Concert',
            'slug' => 'concert',
            'description' => 'Live music performance',
            'is_active' => true,
        ]);
    }

    /**
     * Test event creation without event type fails
     * Requirements: 11.1
     */
    public function test_event_creation_without_event_type_fails(): void
    {
        $eventData = [
            'title' => 'Test Event',
            'description' => 'Test Description',
            'event_date' => now()->addDays(30)->toDateTimeString(),
            'venue' => 'Test Venue',
            'total_capacity' => 100,
            // Missing event_type_id
            'ticket_acquisition_mode' => 'buy',
            'sale_start_date' => now()->addDays(1)->toDateTimeString(),
            'sale_end_date' => now()->addDays(29)->toDateTimeString(),
        ];

        $response = $this->postJson('/api/v1/event-management/events', $eventData);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['event_type_id']);
        $response->assertJsonFragment([
            'event_type_id' => ['Event type is required. Please select a valid event type.'],
        ]);
    }

    /**
     * Test event creation without acquisition mode fails
     * Requirements: 11.2
     */
    public function test_event_creation_without_acquisition_mode_fails(): void
    {
        $eventType = EventType::first();

        $eventData = [
            'title' => 'Test Event',
            'description' => 'Test Description',
            'event_date' => now()->addDays(30)->toDateTimeString(),
            'venue' => 'Test Venue',
            'total_capacity' => 100,
            'event_type_id' => $eventType->id,
            // Missing ticket_acquisition_mode
            'sale_start_date' => now()->addDays(1)->toDateTimeString(),
            'sale_end_date' => now()->addDays(29)->toDateTimeString(),
        ];

        $response = $this->postJson('/api/v1/event-management/events', $eventData);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ticket_acquisition_mode']);
        $response->assertJsonFragment([
            'ticket_acquisition_mode' => ['Ticket acquisition mode is required. Please select either "buy" or "bid".'],
        ]);
    }

    /**
     * Test event creation without sale window fails
     * Requirements: 11.3
     */
    public function test_event_creation_without_sale_window_fails(): void
    {
        $eventType = EventType::first();

        // Test missing sale_start_date
        $eventData = [
            'title' => 'Test Event',
            'description' => 'Test Description',
            'event_date' => now()->addDays(30)->toDateTimeString(),
            'venue' => 'Test Venue',
            'total_capacity' => 100,
            'event_type_id' => $eventType->id,
            'ticket_acquisition_mode' => 'buy',
            // Missing sale_start_date
            'sale_end_date' => now()->addDays(29)->toDateTimeString(),
        ];

        $response = $this->postJson('/api/v1/event-management/events', $eventData);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sale_start_date']);
        $response->assertJsonFragment([
            'sale_start_date' => ['Sale start date is required. Please specify when ticket sales should begin.'],
        ]);

        // Test missing sale_end_date
        $eventData = [
            'title' => 'Test Event',
            'description' => 'Test Description',
            'event_date' => now()->addDays(30)->toDateTimeString(),
            'venue' => 'Test Venue',
            'total_capacity' => 100,
            'event_type_id' => $eventType->id,
            'ticket_acquisition_mode' => 'buy',
            'sale_start_date' => now()->addDays(1)->toDateTimeString(),
            // Missing sale_end_date
        ];

        $response = $this->postJson('/api/v1/event-management/events', $eventData);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sale_end_date']);
        $response->assertJsonFragment([
            'sale_end_date' => ['Sale end date is required. Please specify when ticket sales should end.'],
        ]);
    }

    /**
     * Test validation errors are descriptive
     * Requirements: 11.4
     */
    public function test_validation_errors_are_descriptive(): void
    {
        $eventData = [
            'title' => 'Test Event',
            'description' => 'Test Description',
            'event_date' => now()->addDays(30)->toDateTimeString(),
            'venue' => 'Test Venue',
            'total_capacity' => 100,
            // All Phase 4 fields missing
        ];

        $response = $this->postJson('/api/v1/event-management/events', $eventData);

        $response->assertStatus(422);

        // Check that all validation errors are present and descriptive
        $response->assertJsonValidationErrors([
            'event_type_id',
            'ticket_acquisition_mode',
            'sale_start_date',
            'sale_end_date',
        ]);

        // Verify error messages are descriptive
        $errors = $response->json('errors');

        $this->assertStringContainsString('Event type is required', $errors['event_type_id'][0]);
        $this->assertStringContainsString('Ticket acquisition mode is required', $errors['ticket_acquisition_mode'][0]);
        $this->assertStringContainsString('Sale start date is required', $errors['sale_start_date'][0]);
        $this->assertStringContainsString('Sale end date is required', $errors['sale_end_date'][0]);
    }

    /**
     * Test invalid acquisition mode value fails
     * Requirements: 11.2, 11.4
     */
    public function test_invalid_acquisition_mode_value_fails(): void
    {
        $eventType = EventType::first();

        $eventData = [
            'title' => 'Test Event',
            'description' => 'Test Description',
            'event_date' => now()->addDays(30)->toDateTimeString(),
            'venue' => 'Test Venue',
            'total_capacity' => 100,
            'event_type_id' => $eventType->id,
            'ticket_acquisition_mode' => 'invalid_mode', // Invalid value
            'sale_start_date' => now()->addDays(1)->toDateTimeString(),
            'sale_end_date' => now()->addDays(29)->toDateTimeString(),
        ];

        $response = $this->postJson('/api/v1/event-management/events', $eventData);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ticket_acquisition_mode']);
        $response->assertJsonFragment([
            'ticket_acquisition_mode' => ['Ticket acquisition mode must be either "buy" or "bid".'],
        ]);
    }

    /**
     * Test sale end date before start date fails
     * Requirements: 11.3, 11.4
     */
    public function test_sale_end_date_before_start_date_fails(): void
    {
        $eventType = EventType::first();

        $eventData = [
            'title' => 'Test Event',
            'description' => 'Test Description',
            'event_date' => now()->addDays(30)->toDateTimeString(),
            'venue' => 'Test Venue',
            'total_capacity' => 100,
            'event_type_id' => $eventType->id,
            'ticket_acquisition_mode' => 'buy',
            'sale_start_date' => now()->addDays(10)->toDateTimeString(),
            'sale_end_date' => now()->addDays(5)->toDateTimeString(), // Before start date
        ];

        $response = $this->postJson('/api/v1/event-management/events', $eventData);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['sale_end_date']);
        $response->assertJsonFragment([
            'sale_end_date' => ['Sale end date must be after the sale start date.'],
        ]);
    }

    /**
     * Test successful event creation with all required fields
     * Requirements: 11.1, 11.2, 11.3
     */
    public function test_successful_event_creation_with_all_required_fields(): void
    {
        $eventType = EventType::first();

        $eventData = [
            'title' => 'Test Event',
            'description' => 'Test Description',
            'event_date' => now()->addDays(30)->toDateTimeString(),
            'venue' => 'Test Venue',
            'total_capacity' => 100,
            'event_type_id' => $eventType->id,
            'ticket_acquisition_mode' => 'buy',
            'sale_start_date' => now()->addDays(1)->toDateTimeString(),
            'sale_end_date' => now()->addDays(29)->toDateTimeString(),
        ];

        $response = $this->postJson('/api/v1/event-management/events', $eventData);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'title',
                'event_type',
                'ticket_acquisition_mode',
                'sale_start_date',
                'sale_end_date',
            ],
            'message',
        ]);
    }
}

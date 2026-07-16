<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\EventType;
use Tests\TestCase;

/**
 * Property Test 1: Event Type Persistence
 * Property Test 2: Acquisition Mode Persistence
 * Property Test 12: Sale Window Persistence
 *
 * Feature: erp-event-management-phase4
 *
 * Validates Requirements: 1.4, 1.5, 3.6, 7.6
 *
 * This property-based test ensures that event configuration data (event type,
 * acquisition mode, and sale window) is correctly persisted and retrieved.
 *
 * Properties Tested:
 * 1. Event type is preserved after creation and retrieval
 * 2. Acquisition mode is preserved after creation and retrieval
 * 3. Sale window dates are preserved after creation and retrieval
 */
class EventTypePersistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed default event types
        $this->seedEventTypes();
    }

    /**
     * Property 1: Event Type Persistence
     *
     * For any event created with a specific event type, retrieving that event
     * should return the same event type.
     *
     * Validates: Requirements 1.4, 1.5
     *
     * @test
     */
    public function property_event_type_is_preserved_after_creation_and_retrieval(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate random event data with event type
                $eventType = $this->getRandomEventType();
                $eventData = $this->generateRandomEventData($eventType->id);

                // Create event
                $createdEvent = Event::create($eventData);

                // Retrieve event
                $retrievedEvent = Event::with('eventType')->find($createdEvent->id);

                // Assert event type is preserved
                if ($retrievedEvent->event_type_id === $eventType->id &&
                    $retrievedEvent->eventType->id === $eventType->id &&
                    $retrievedEvent->eventType->name === $eventType->name) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'expected_event_type_id' => $eventType->id,
                        'expected_event_type_name' => $eventType->name,
                        'actual_event_type_id' => $retrievedEvent->event_type_id,
                        'actual_event_type_name' => $retrievedEvent->eventType->name ?? null,
                    ];
                }

            } catch (\Exception $e) {
                $failures[] = [
                    'iteration' => $i + 1,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ];
            }
        }

        $this->assertEmpty($failures,
            'Event type persistence failures in '.count($failures)." out of {$iterations} iterations:\n".
            json_encode($failures, JSON_PRETTY_PRINT)
        );

        $this->assertEquals($iterations, $passedIterations,
            'All iterations should pass event type persistence test'
        );
    }

    /**
     * Property 2: Acquisition Mode Persistence
     *
     * For any event created with a specific ticket acquisition mode, retrieving
     * that event should return the same acquisition mode.
     *
     * Validates: Requirements 3.6
     *
     * @test
     */
    public function property_acquisition_mode_is_preserved_after_creation_and_retrieval(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate random event data with acquisition mode
                $acquisitionMode = $this->getRandomAcquisitionMode();
                $eventType = $this->getRandomEventType();
                $eventData = $this->generateRandomEventData($eventType->id, $acquisitionMode);

                // Create event
                $createdEvent = Event::create($eventData);

                // Retrieve event
                $retrievedEvent = Event::find($createdEvent->id);

                // Assert acquisition mode is preserved
                if ($retrievedEvent->ticket_acquisition_mode === $acquisitionMode) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'expected_acquisition_mode' => $acquisitionMode,
                        'actual_acquisition_mode' => $retrievedEvent->ticket_acquisition_mode,
                    ];
                }

            } catch (\Exception $e) {
                $failures[] = [
                    'iteration' => $i + 1,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ];
            }
        }

        $this->assertEmpty($failures,
            'Acquisition mode persistence failures in '.count($failures)." out of {$iterations} iterations:\n".
            json_encode($failures, JSON_PRETTY_PRINT)
        );

        $this->assertEquals($iterations, $passedIterations,
            'All iterations should pass acquisition mode persistence test'
        );
    }

    /**
     * Property 12: Sale Window Persistence
     *
     * For any event created with specific sale start and end dates, retrieving
     * that event should return the same sale window dates.
     *
     * Validates: Requirements 7.6
     *
     * @test
     */
    public function property_sale_window_dates_are_preserved_after_creation_and_retrieval(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate random event data with sale window
                $eventType = $this->getRandomEventType();
                $saleWindow = $this->generateRandomSaleWindow();
                $eventData = $this->generateRandomEventData(
                    $eventType->id,
                    $this->getRandomAcquisitionMode(),
                    $saleWindow['start'],
                    $saleWindow['end']
                );

                // Create event
                $createdEvent = Event::create($eventData);

                // Retrieve event
                $retrievedEvent = Event::find($createdEvent->id);

                // Assert sale window dates are preserved
                $startMatches = $retrievedEvent->sale_start_date->equalTo($saleWindow['start']);
                $endMatches = $retrievedEvent->sale_end_date->equalTo($saleWindow['end']);

                if ($startMatches && $endMatches) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'expected_sale_start' => $saleWindow['start']->toDateTimeString(),
                        'expected_sale_end' => $saleWindow['end']->toDateTimeString(),
                        'actual_sale_start' => $retrievedEvent->sale_start_date->toDateTimeString(),
                        'actual_sale_end' => $retrievedEvent->sale_end_date->toDateTimeString(),
                        'start_matches' => $startMatches,
                        'end_matches' => $endMatches,
                    ];
                }

            } catch (\Exception $e) {
                $failures[] = [
                    'iteration' => $i + 1,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ];
            }
        }

        $this->assertEmpty($failures,
            'Sale window persistence failures in '.count($failures)." out of {$iterations} iterations:\n".
            json_encode($failures, JSON_PRETTY_PRINT)
        );

        $this->assertEquals($iterations, $passedIterations,
            'All iterations should pass sale window persistence test'
        );
    }

    // Helper Methods

    private function seedEventTypes(): void
    {
        EventType::create([
            'name' => 'Concert',
            'slug' => 'concert',
            'description' => 'Live music performance',
            'is_active' => true,
        ]);

        EventType::create([
            'name' => 'Meet-the-Artist',
            'slug' => 'meet-the-artist',
            'description' => 'Personal meet and greet with artist',
            'is_active' => true,
        ]);
    }

    private function getRandomEventType(): EventType
    {
        $eventTypes = EventType::active()->get();

        return $eventTypes->random();
    }

    private function getRandomAcquisitionMode(): string
    {
        $modes = [Event::MODE_BUY, Event::MODE_BID];

        return $modes[array_rand($modes)];
    }

    private function generateRandomSaleWindow(): array
    {
        // Generate sale start date between now and 30 days from now
        $saleStart = Carbon::now()->addDays(rand(0, 30))->addHours(rand(0, 23));

        // Generate sale end date between 1 and 60 days after sale start
        $saleEnd = (clone $saleStart)->addDays(rand(1, 60))->addHours(rand(0, 23));

        return [
            'start' => $saleStart,
            'end' => $saleEnd,
        ];
    }

    private function generateRandomEventData(
        int $eventTypeId,
        ?string $acquisitionMode = null,
        ?Carbon $saleStartDate = null,
        ?Carbon $saleEndDate = null
    ): array {
        $acquisitionMode = $acquisitionMode ?? $this->getRandomAcquisitionMode();

        if (! $saleStartDate || ! $saleEndDate) {
            $saleWindow = $this->generateRandomSaleWindow();
            $saleStartDate = $saleWindow['start'];
            $saleEndDate = $saleWindow['end'];
        }

        // Generate event date after sale end date
        $eventDate = (clone $saleEndDate)->addDays(rand(1, 30));

        return [
            'title' => 'Test Event '.uniqid(),
            'description' => 'Test event description '.rand(1000, 9999),
            'event_date' => $eventDate,
            'venue' => 'Test Venue '.rand(1, 100),
            'total_capacity' => rand(50, 500),
            'available_capacity' => rand(50, 500),
            'status' => 'published',
            'event_type_id' => $eventTypeId,
            'ticket_acquisition_mode' => $acquisitionMode,
            'sale_start_date' => $saleStartDate,
            'sale_end_date' => $saleEndDate,
        ];
    }
}

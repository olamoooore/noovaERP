<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\EventType;
use Tests\TestCase;

/**
 * Property Tests 21, 22, 23: Configuration Validation
 *
 * Feature: erp-event-management-phase4
 *
 * Validates Requirements: 11.1, 11.2, 11.3
 *
 * These property-based tests ensure that event configuration validation
 * correctly prevents creation of events without required fields.
 */
class ConfigurationValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed default event types
        $this->seedEventTypes();
    }

    /**
     * Property 21: Configuration Validation - Event Type Required
     *
     * For any event creation attempt without an event type, the creation
     * should fail with a validation error.
     *
     * Validates: Requirements 11.1
     *
     * @test
     */
    public function property_creation_without_event_type_fails(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate event data without event_type_id
                $eventData = $this->generateEventDataWithoutEventType();

                // Attempt to create event
                try {
                    Event::create($eventData);

                    // If we reach here, validation failed
                    $failures[] = [
                        'iteration' => $i + 1,
                        'reason' => 'Event created without event_type_id',
                    ];
                } catch (\Exception $e) {
                    // Expected: should fail
                    $passedIterations++;
                }

            } catch (\Exception $e) {
                $failures[] = [
                    'iteration' => $i + 1,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->assertEmpty($failures,
            'Configuration validation failures in '.count($failures)." out of {$iterations} iterations:\n".
            json_encode($failures, JSON_PRETTY_PRINT)
        );

        $this->assertEquals($iterations, $passedIterations,
            'All iterations should correctly reject events without event type'
        );
    }

    /**
     * Property 22: Configuration Validation - Acquisition Mode Required
     *
     * For any event creation attempt without a ticket acquisition mode,
     * the creation should fail with a validation error.
     *
     * Validates: Requirements 11.2
     *
     * @test
     */
    public function property_creation_without_acquisition_mode_fails(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate event data without ticket_acquisition_mode
                $eventData = $this->generateEventDataWithoutAcquisitionMode();

                // Attempt to create event
                try {
                    Event::create($eventData);

                    // If we reach here, validation failed
                    $failures[] = [
                        'iteration' => $i + 1,
                        'reason' => 'Event created without ticket_acquisition_mode',
                    ];
                } catch (\Exception $e) {
                    // Expected: should fail
                    $passedIterations++;
                }

            } catch (\Exception $e) {
                $failures[] = [
                    'iteration' => $i + 1,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->assertEmpty($failures,
            'Configuration validation failures in '.count($failures)." out of {$iterations} iterations:\n".
            json_encode($failures, JSON_PRETTY_PRINT)
        );

        $this->assertEquals($iterations, $passedIterations,
            'All iterations should correctly reject events without acquisition mode'
        );
    }

    /**
     * Property 23: Configuration Validation - Sale Window Required
     *
     * For any event creation attempt without a complete sale window
     * (start and end dates), the creation should fail with a validation error.
     *
     * Validates: Requirements 11.3
     *
     * @test
     */
    public function property_creation_without_sale_window_fails(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Randomly choose to omit either start or end date
                $omitField = rand(0, 1) === 0 ? 'start' : 'end';

                // Generate event data without complete sale window
                $eventData = $this->generateEventDataWithoutSaleWindow($omitField);

                // Attempt to create event
                try {
                    Event::create($eventData);

                    // If we reach here, validation failed
                    $failures[] = [
                        'iteration' => $i + 1,
                        'omitted_field' => $omitField,
                        'reason' => "Event created without sale_{$omitField}_date",
                    ];
                } catch (\Exception $e) {
                    // Expected: should fail
                    $passedIterations++;
                }

            } catch (\Exception $e) {
                $failures[] = [
                    'iteration' => $i + 1,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->assertEmpty($failures,
            'Configuration validation failures in '.count($failures)." out of {$iterations} iterations:\n".
            json_encode($failures, JSON_PRETTY_PRINT)
        );

        $this->assertEquals($iterations, $passedIterations,
            'All iterations should correctly reject events without complete sale window'
        );
    }

    // Helper Methods

    private function seedEventTypes(): void
    {
        EventType::firstOrCreate(
            ['slug' => 'concert'],
            [
                'name' => 'Concert',
                'description' => 'Live music performance',
                'is_active' => true,
            ]
        );

        EventType::firstOrCreate(
            ['slug' => 'meet-the-artist'],
            [
                'name' => 'Meet-the-Artist',
                'description' => 'Personal meet and greet with artist',
                'is_active' => true,
            ]
        );
    }

    private function getRandomEventType(): EventType
    {
        $eventTypes = EventType::where('is_active', true)->get();

        return $eventTypes->random();
    }

    private function generateEventDataWithoutEventType(): array
    {
        $saleStart = Carbon::now()->addDays(rand(1, 10));
        $saleEnd = (clone $saleStart)->addDays(rand(1, 20));
        $eventDate = (clone $saleEnd)->addDays(rand(1, 30));

        return [
            'title' => 'Test Event '.uniqid(),
            'description' => 'Test event description',
            'event_date' => $eventDate,
            'venue' => 'Test Venue',
            'total_capacity' => rand(50, 500),
            'available_capacity' => rand(50, 500),
            'status' => 'draft',
            // Missing event_type_id
            'ticket_acquisition_mode' => Event::MODE_BUY,
            'sale_start_date' => $saleStart,
            'sale_end_date' => $saleEnd,
        ];
    }

    private function generateEventDataWithoutAcquisitionMode(): array
    {
        $eventType = $this->getRandomEventType();
        $saleStart = Carbon::now()->addDays(rand(1, 10));
        $saleEnd = (clone $saleStart)->addDays(rand(1, 20));
        $eventDate = (clone $saleEnd)->addDays(rand(1, 30));

        return [
            'title' => 'Test Event '.uniqid(),
            'description' => 'Test event description',
            'event_date' => $eventDate,
            'venue' => 'Test Venue',
            'total_capacity' => rand(50, 500),
            'available_capacity' => rand(50, 500),
            'status' => 'draft',
            'event_type_id' => $eventType->id,
            // Missing ticket_acquisition_mode
            'sale_start_date' => $saleStart,
            'sale_end_date' => $saleEnd,
        ];
    }

    private function generateEventDataWithoutSaleWindow(string $omitField): array
    {
        $eventType = $this->getRandomEventType();
        $saleStart = Carbon::now()->addDays(rand(1, 10));
        $saleEnd = (clone $saleStart)->addDays(rand(1, 20));
        $eventDate = (clone $saleEnd)->addDays(rand(1, 30));

        $data = [
            'title' => 'Test Event '.uniqid(),
            'description' => 'Test event description',
            'event_date' => $eventDate,
            'venue' => 'Test Venue',
            'total_capacity' => rand(50, 500),
            'available_capacity' => rand(50, 500),
            'status' => 'draft',
            'event_type_id' => $eventType->id,
            'ticket_acquisition_mode' => Event::MODE_BUY,
        ];

        // Add only one of the sale window dates
        if ($omitField === 'start') {
            $data['sale_end_date'] = $saleEnd;
            // Missing sale_start_date
        } else {
            $data['sale_start_date'] = $saleStart;
            // Missing sale_end_date
        }

        return $data;
    }
}

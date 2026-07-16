<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\EventType;
use Tests\TestCase;

/**
 * Property Test 24: Validation Error Descriptiveness
 *
 * Feature: erp-event-management-phase4
 *
 * Validates Requirements: 11.4
 *
 * This property-based test ensures that validation errors include
 * descriptive messages explaining what is invalid.
 */
class ValidationErrorDescriptivenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed default event types
        $this->seedEventTypes();
    }

    /**
     * Property 24: Validation Error Descriptiveness
     *
     * For any event creation attempt with invalid configuration, the returned
     * validation errors should include descriptive messages explaining what
     * is invalid.
     *
     * Validates: Requirements 11.4
     *
     * @test
     */
    public function property_validation_errors_are_descriptive(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate invalid event configuration
                $invalidConfig = $this->generateInvalidEventConfiguration();

                // Attempt to create event via API
                $response = $this->postJson('/api/v1/event-management/events', $invalidConfig);

                // Should return 422 validation error
                if ($response->status() === 422) {
                    $errors = $response->json('errors');

                    // Check that errors are present and descriptive
                    if (! empty($errors)) {
                        $allDescriptive = true;

                        foreach ($errors as $field => $messages) {
                            foreach ($messages as $message) {
                                // Check that message is not empty and contains meaningful text
                                if (empty($message) || strlen($message) < 10) {
                                    $allDescriptive = false;
                                    $failures[] = [
                                        'iteration' => $i + 1,
                                        'field' => $field,
                                        'message' => $message,
                                        'reason' => 'Error message is not descriptive enough',
                                    ];
                                }
                            }
                        }

                        if ($allDescriptive) {
                            $passedIterations++;
                        }
                    } else {
                        $failures[] = [
                            'iteration' => $i + 1,
                            'reason' => 'No validation errors returned',
                        ];
                    }
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'status' => $response->status(),
                        'reason' => 'Expected 422 validation error',
                    ];
                }

            } catch (\Exception $e) {
                $failures[] = [
                    'iteration' => $i + 1,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->assertEmpty($failures,
            'Validation error descriptiveness failures in '.count($failures)." out of {$iterations} iterations:\n".
            json_encode($failures, JSON_PRETTY_PRINT)
        );

        $this->assertEquals($iterations, $passedIterations,
            'All iterations should return descriptive validation errors'
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
    }

    private function generateInvalidEventConfiguration(): array
    {
        // Randomly choose which fields to omit or make invalid
        $invalidationType = rand(1, 4);

        $baseData = [
            'title' => 'Test Event '.uniqid(),
            'description' => 'Test event description',
            'venue' => 'Test Venue',
            'total_capacity' => rand(50, 500),
        ];

        switch ($invalidationType) {
            case 1:
                // Missing event_type_id
                $baseData['ticket_acquisition_mode'] = 'buy';
                $baseData['sale_start_date'] = Carbon::now()->addDays(1)->toDateTimeString();
                $baseData['sale_end_date'] = Carbon::now()->addDays(10)->toDateTimeString();
                $baseData['event_date'] = Carbon::now()->addDays(30)->toDateTimeString();
                break;

            case 2:
                // Missing ticket_acquisition_mode
                $eventType = EventType::first();
                $baseData['event_type_id'] = $eventType->id;
                $baseData['sale_start_date'] = Carbon::now()->addDays(1)->toDateTimeString();
                $baseData['sale_end_date'] = Carbon::now()->addDays(10)->toDateTimeString();
                $baseData['event_date'] = Carbon::now()->addDays(30)->toDateTimeString();
                break;

            case 3:
                // Missing sale_start_date
                $eventType = EventType::first();
                $baseData['event_type_id'] = $eventType->id;
                $baseData['ticket_acquisition_mode'] = 'buy';
                $baseData['sale_end_date'] = Carbon::now()->addDays(10)->toDateTimeString();
                $baseData['event_date'] = Carbon::now()->addDays(30)->toDateTimeString();
                break;

            case 4:
                // Invalid sale window (end before start)
                $eventType = EventType::first();
                $baseData['event_type_id'] = $eventType->id;
                $baseData['ticket_acquisition_mode'] = 'buy';
                $baseData['sale_start_date'] = Carbon::now()->addDays(10)->toDateTimeString();
                $baseData['sale_end_date'] = Carbon::now()->addDays(1)->toDateTimeString();
                $baseData['event_date'] = Carbon::now()->addDays(30)->toDateTimeString();
                break;
        }

        return $baseData;
    }
}

<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\EventType;
use Modules\EventManagement\Services\SaleWindowService;
use Tests\TestCase;

/**
 * Property Test 13: Sale Window Validation
 *
 * Feature: erp-event-management-phase4
 *
 * Validates Requirements: 7.3
 *
 * This property-based test ensures that sale window validation correctly
 * prevents creation of events with invalid sale windows (end date before
 * or equal to start date).
 */
class SaleWindowValidationTest extends TestCase
{
    use RefreshDatabase;

    protected SaleWindowService $saleWindowService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->saleWindowService = new SaleWindowService;

        // Seed default event types
        $this->seedEventTypes();
    }

    /**
     * Property 13: Sale Window Validation
     *
     * For any event creation attempt where the sale end date is before or equal
     * to the sale start date, the creation should fail with a validation error.
     *
     * Validates: Requirements 7.3
     *
     * @test
     */
    public function property_invalid_sale_window_prevents_event_creation(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate random sale window dates where end <= start
                $invalidSaleWindow = $this->generateInvalidSaleWindow();

                // Generate event data with invalid sale window
                $eventType = $this->getRandomEventType();
                $eventData = $this->generateEventData(
                    $eventType->id,
                    $invalidSaleWindow['start'],
                    $invalidSaleWindow['end']
                );

                // Validate sale window using service
                $validation = $this->saleWindowService->validateSaleWindow(
                    $invalidSaleWindow['start'],
                    $invalidSaleWindow['end']
                );

                // Assert validation fails
                if (! $validation['valid'] && ! empty($validation['errors'])) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'sale_start' => $invalidSaleWindow['start']->toDateTimeString(),
                        'sale_end' => $invalidSaleWindow['end']->toDateTimeString(),
                        'validation_result' => $validation,
                        'reason' => 'Validation should have failed but passed',
                    ];
                }

                // Also test that database constraint prevents creation
                try {
                    Event::create($eventData);

                    // If we reach here, the database allowed invalid data
                    $failures[] = [
                        'iteration' => $i + 1,
                        'sale_start' => $invalidSaleWindow['start']->toDateTimeString(),
                        'sale_end' => $invalidSaleWindow['end']->toDateTimeString(),
                        'reason' => 'Database allowed creation with invalid sale window',
                    ];

                    // Decrement passed iterations since validation passed but DB didn't enforce
                    if ($passedIterations > 0) {
                        $passedIterations--;
                    }
                } catch (\Exception $e) {
                    // Expected: database should reject invalid sale window
                    // This is good - the constraint is working
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
            'Sale window validation failures in '.count($failures)." out of {$iterations} iterations:\n".
            json_encode($failures, JSON_PRETTY_PRINT)
        );

        $this->assertEquals($iterations, $passedIterations,
            'All iterations should correctly reject invalid sale windows'
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

    /**
     * Generate invalid sale window where end <= start
     */
    private function generateInvalidSaleWindow(): array
    {
        // Generate a base date
        $baseDate = Carbon::now()->addDays(rand(1, 30));

        // Randomly choose between:
        // 1. End date before start date
        // 2. End date equal to start date
        $scenario = rand(1, 2);

        if ($scenario === 1) {
            // End date before start date
            $saleStart = (clone $baseDate)->addDays(rand(1, 10));
            $saleEnd = (clone $baseDate)->subDays(rand(1, 10));
        } else {
            // End date equal to start date
            $saleStart = clone $baseDate;
            $saleEnd = clone $baseDate;
        }

        return [
            'start' => $saleStart,
            'end' => $saleEnd,
        ];
    }

    private function generateEventData(
        int $eventTypeId,
        Carbon $saleStartDate,
        Carbon $saleEndDate
    ): array {
        // Generate event date after sale end date (or start if end is before start)
        $latestDate = $saleEndDate->greaterThan($saleStartDate) ? $saleEndDate : $saleStartDate;
        $eventDate = (clone $latestDate)->addDays(rand(1, 30));

        return [
            'title' => 'Test Event '.uniqid(),
            'description' => 'Test event description '.rand(1000, 9999),
            'event_date' => $eventDate,
            'venue' => 'Test Venue '.rand(1, 100),
            'total_capacity' => rand(50, 500),
            'available_capacity' => rand(50, 500),
            'status' => 'published',
            'event_type_id' => $eventTypeId,
            'ticket_acquisition_mode' => Event::MODE_BUY,
            'sale_start_date' => $saleStartDate,
            'sale_end_date' => $saleEndDate,
        ];
    }
}

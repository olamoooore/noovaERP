<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\EventType;
use Modules\EventManagement\Services\SaleWindowService;
use Carbon\Carbon;

/**
 * Property Tests 14, 15, 16: Sale Window Enforcement
 * Property Tests 17, 18, 19: Sale Status Accuracy
 * 
 * Feature: erp-event-management-phase4
 * 
 * Validates Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 8.6
 * 
 * This property-based test ensures that sale window enforcement correctly
 * prevents or allows ticket purchases based on the current time relative
 * to the sale window, and that sale status is accurately reported.
 */
class SaleWindowEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected SaleWindowService $saleWindowService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->saleWindowService = new SaleWindowService();
        
        // Seed default event types
        $this->seedEventTypes();
    }

    /**
     * Property 14: Pre-Window Purchase Prevention
     * 
     * For any event where the current time is before the sale start time,
     * attempting to purchase or bid should fail with an error indicating
     * sales have not started.
     * 
     * Validates: Requirements 8.1
     * 
     * @test
     */
    public function property_purchases_prevented_before_sale_window(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate event with future sale start date
                $eventType = $this->getRandomEventType();
                $futureStart = Carbon::now()->addDays(rand(1, 30));
                $futureEnd = (clone $futureStart)->addDays(rand(1, 30));
                
                $eventData = $this->generateEventData(
                    $eventType->id,
                    $futureStart,
                    $futureEnd
                );
                
                $event = Event::create($eventData);
                
                // Check if within sale window (should be false)
                $isWithinWindow = $this->saleWindowService->isWithinSaleWindow($event);
                
                // Get sale status (should be NOT_STARTED)
                $saleStatus = $this->saleWindowService->getSaleStatus($event);
                
                // Assert purchase is prevented
                if (!$isWithinWindow && $saleStatus === SaleWindowService::STATUS_NOT_STARTED) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'current_time' => Carbon::now()->toDateTimeString(),
                        'sale_start' => $futureStart->toDateTimeString(),
                        'sale_end' => $futureEnd->toDateTimeString(),
                        'is_within_window' => $isWithinWindow,
                        'sale_status' => $saleStatus,
                        'expected_status' => SaleWindowService::STATUS_NOT_STARTED,
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
            "Pre-window purchase prevention failures in " . count($failures) . " out of {$iterations} iterations:\n" .
            json_encode($failures, JSON_PRETTY_PRINT)
        );
        
        $this->assertEquals($iterations, $passedIterations,
            "All iterations should correctly prevent purchases before sale window"
        );
    }

    /**
     * Property 15: Post-Window Purchase Prevention
     * 
     * For any event where the current time is after the sale end time,
     * attempting to purchase or bid should fail with an error indicating
     * sales have closed.
     * 
     * Validates: Requirements 8.2
     * 
     * @test
     */
    public function property_purchases_prevented_after_sale_window(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate event with past sale end date
                $pastEnd = Carbon::now()->subDays(rand(1, 30));
                $pastStart = (clone $pastEnd)->subDays(rand(1, 30));
                
                $eventType = $this->getRandomEventType();
                $eventData = $this->generateEventData(
                    $eventType->id,
                    $pastStart,
                    $pastEnd
                );
                
                $event = Event::create($eventData);
                
                // Check if within sale window (should be false)
                $isWithinWindow = $this->saleWindowService->isWithinSaleWindow($event);
                
                // Get sale status (should be CLOSED)
                $saleStatus = $this->saleWindowService->getSaleStatus($event);
                
                // Assert purchase is prevented
                if (!$isWithinWindow && $saleStatus === SaleWindowService::STATUS_CLOSED) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'current_time' => Carbon::now()->toDateTimeString(),
                        'sale_start' => $pastStart->toDateTimeString(),
                        'sale_end' => $pastEnd->toDateTimeString(),
                        'is_within_window' => $isWithinWindow,
                        'sale_status' => $saleStatus,
                        'expected_status' => SaleWindowService::STATUS_CLOSED,
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
            "Post-window purchase prevention failures in " . count($failures) . " out of {$iterations} iterations:\n" .
            json_encode($failures, JSON_PRETTY_PRINT)
        );
        
        $this->assertEquals($iterations, $passedIterations,
            "All iterations should correctly prevent purchases after sale window"
        );
    }

    /**
     * Property 16: Within-Window Purchase Allowance
     * 
     * For any event where the current time is within the sale window and
     * tickets are available, purchase or bid attempts should be allowed
     * to proceed.
     * 
     * Validates: Requirements 8.3
     * 
     * @test
     */
    public function property_purchases_allowed_within_sale_window(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate event with current time within sale window
                $pastStart = Carbon::now()->subDays(rand(1, 10));
                $futureEnd = Carbon::now()->addDays(rand(1, 10));
                
                $eventType = $this->getRandomEventType();
                $eventData = $this->generateEventData(
                    $eventType->id,
                    $pastStart,
                    $futureEnd
                );
                
                $event = Event::create($eventData);
                
                // Check if within sale window (should be true)
                $isWithinWindow = $this->saleWindowService->isWithinSaleWindow($event);
                
                // Get sale status (should be OPEN)
                $saleStatus = $this->saleWindowService->getSaleStatus($event);
                
                // Assert purchase is allowed
                if ($isWithinWindow && $saleStatus === SaleWindowService::STATUS_OPEN) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'current_time' => Carbon::now()->toDateTimeString(),
                        'sale_start' => $pastStart->toDateTimeString(),
                        'sale_end' => $futureEnd->toDateTimeString(),
                        'is_within_window' => $isWithinWindow,
                        'sale_status' => $saleStatus,
                        'expected_status' => SaleWindowService::STATUS_OPEN,
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
            "Within-window purchase allowance failures in " . count($failures) . " out of {$iterations} iterations:\n" .
            json_encode($failures, JSON_PRETTY_PRINT)
        );
        
        $this->assertEquals($iterations, $passedIterations,
            "All iterations should correctly allow purchases within sale window"
        );
    }

    /**
     * Property 17, 18, 19: Sale Status Accuracy
     * 
     * For any event, the sale status should accurately reflect the current
     * time relative to the sale window:
     * - "not_started" when before sale window
     * - "open" when within sale window
     * - "closed" when after sale window
     * 
     * Validates: Requirements 8.4, 8.5, 8.6
     * 
     * @test
     */
    public function property_sale_status_accuracy(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Test all three scenarios
                $scenarios = [
                    'not_started' => [
                        'start' => Carbon::now()->addDays(rand(1, 30)),
                        'end' => Carbon::now()->addDays(rand(31, 60)),
                        'expected_status' => SaleWindowService::STATUS_NOT_STARTED,
                    ],
                    'open' => [
                        'start' => Carbon::now()->subDays(rand(1, 10)),
                        'end' => Carbon::now()->addDays(rand(1, 10)),
                        'expected_status' => SaleWindowService::STATUS_OPEN,
                    ],
                    'closed' => [
                        'start' => Carbon::now()->subDays(rand(31, 60)),
                        'end' => Carbon::now()->subDays(rand(1, 30)),
                        'expected_status' => SaleWindowService::STATUS_CLOSED,
                    ],
                ];
                
                $scenarioKey = array_rand($scenarios);
                $scenario = $scenarios[$scenarioKey];
                
                $eventType = $this->getRandomEventType();
                $eventData = $this->generateEventData(
                    $eventType->id,
                    $scenario['start'],
                    $scenario['end']
                );
                
                $event = Event::create($eventData);
                
                // Get sale status
                $saleStatus = $this->saleWindowService->getSaleStatus($event);
                
                // Assert status matches expected
                if ($saleStatus === $scenario['expected_status']) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'scenario' => $scenarioKey,
                        'current_time' => Carbon::now()->toDateTimeString(),
                        'sale_start' => $scenario['start']->toDateTimeString(),
                        'sale_end' => $scenario['end']->toDateTimeString(),
                        'expected_status' => $scenario['expected_status'],
                        'actual_status' => $saleStatus,
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
            "Sale status accuracy failures in " . count($failures) . " out of {$iterations} iterations:\n" .
            json_encode($failures, JSON_PRETTY_PRINT)
        );
        
        $this->assertEquals($iterations, $passedIterations,
            "All iterations should correctly report sale status"
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

    private function generateEventData(
        int $eventTypeId,
        Carbon $saleStartDate,
        Carbon $saleEndDate
    ): array {
        // Generate event date after sale end date
        $latestDate = $saleEndDate->greaterThan($saleStartDate) ? $saleEndDate : $saleStartDate;
        $eventDate = (clone $latestDate)->addDays(rand(1, 30));
        
        return [
            'title' => 'Test Event ' . uniqid(),
            'description' => 'Test event description ' . rand(1000, 9999),
            'event_date' => $eventDate,
            'venue' => 'Test Venue ' . rand(1, 100),
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

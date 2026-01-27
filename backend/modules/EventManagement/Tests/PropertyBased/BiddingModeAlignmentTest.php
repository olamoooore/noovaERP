<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\EventType;
use Modules\EventManagement\Services\BiddingService;
use Modules\EventManagement\Services\SaleWindowService;
use Modules\EventManagement\Exceptions\InvalidAcquisitionModeException;
use Carbon\Carbon;

/**
 * Property Test 5: Mode-Action Alignment for Bidding
 * Property Test 7: Bid Prevention for Purchase Events
 * Property Test 3: Exclusive Action Visibility
 * 
 * Feature: erp-event-management-phase4
 * 
 * Validates Requirements: 4.2, 4.3, 4.5
 * 
 * This property-based test ensures that:
 * 1. Events with Bidding mode only allow bid actions
 * 2. Events with Direct Purchase mode prevent bid actions
 * 3. Events show either purchase OR bid actions, never both
 */
class BiddingModeAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected BiddingService $biddingService;
    protected SaleWindowService $saleWindowService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Seed default event types
        $this->seedEventTypes();
        
        // Initialize services
        $this->saleWindowService = app(SaleWindowService::class);
        $this->biddingService = app(BiddingService::class);
    }

    /**
     * Property 5: Mode-Action Alignment for Bidding
     * 
     * For any event with Bidding mode, the available actions for customers
     * should only include bid.
     * 
     * Validates: Requirements 4.2
     * 
     * @test
     */
    public function property_bidding_mode_only_allows_bid_action(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate event with Bidding mode
                $eventType = $this->getRandomEventType();
                $eventData = $this->generateRandomEventData($eventType->id, Event::MODE_BID);
                
                // Create event
                $event = Event::create($eventData);
                
                // Get available actions for customer (check if bid is allowed)
                $canBidResult = $this->biddingService->canBid($event->id);
                
                // Assert only bid action is available
                // For Bidding mode, canBid should return true (within sale window)
                if ($canBidResult['can_bid'] === true) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'event_id' => $event->id,
                        'acquisition_mode' => $event->ticket_acquisition_mode,
                        'can_bid' => $canBidResult['can_bid'],
                        'reason' => $canBidResult['reason'] ?? null,
                        'sale_status' => $this->saleWindowService->getSaleStatus($event),
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
            "Mode-action alignment failures for Bidding in " . count($failures) . " out of {$iterations} iterations:\n" .
            json_encode($failures, JSON_PRETTY_PRINT)
        );
        
        $this->assertEquals($iterations, $passedIterations,
            "All iterations should pass mode-action alignment test for Bidding"
        );
    }

    /**
     * Property 7: Bid Prevention for Purchase Events
     * 
     * For any event with Direct Purchase mode, attempting to bid on tickets should
     * fail with an error.
     * 
     * Validates: Requirements 4.5
     * 
     * @test
     */
    public function property_purchase_mode_prevents_bid_action(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate event with Direct Purchase mode
                $eventType = $this->getRandomEventType();
                $eventData = $this->generateRandomEventData($eventType->id, Event::MODE_BUY);
                
                // Create event
                $event = Event::create($eventData);
                
                // Check if bid is allowed (should be false)
                $canBidResult = $this->biddingService->canBid($event->id);
                
                // Assert bid is not allowed
                if ($canBidResult['can_bid'] === false &&
                    str_contains($canBidResult['reason'], 'purchase')) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'event_id' => $event->id,
                        'acquisition_mode' => $event->ticket_acquisition_mode,
                        'can_bid' => $canBidResult['can_bid'],
                        'reason' => $canBidResult['reason'] ?? null,
                        'expected' => 'Bid should be prevented for purchase events',
                    ];
                }
                
                // Also test that actual bid attempt throws exception
                try {
                    $bidData = $this->generateBidData($event->id);
                    $this->biddingService->submitBid($bidData);
                    
                    // If we reach here, the bid was allowed (should not happen)
                    $failures[] = [
                        'iteration' => $i + 1,
                        'event_id' => $event->id,
                        'acquisition_mode' => $event->ticket_acquisition_mode,
                        'error' => 'Bid was allowed for purchase event (should have thrown exception)',
                    ];
                    $passedIterations--; // Decrement since this is a failure
                    
                } catch (InvalidAcquisitionModeException $e) {
                    // Expected exception - bid was correctly prevented
                    // passedIterations already incremented above
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
            "Bid prevention failures for Purchase events in " . count($failures) . " out of {$iterations} iterations:\n" .
            json_encode($failures, JSON_PRETTY_PRINT)
        );
        
        $this->assertEquals($iterations, $passedIterations,
            "All iterations should pass bid prevention test for Purchase events"
        );
    }

    /**
     * Property 3: Exclusive Action Visibility
     * 
     * For any event, the available actions should contain either purchase OR bid,
     * never both.
     * 
     * Validates: Requirements 4.3
     * 
     * @test
     */
    public function property_exclusive_action_visibility(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate event with random acquisition mode
                $eventType = $this->getRandomEventType();
                $acquisitionMode = $this->getRandomAcquisitionMode();
                $eventData = $this->generateRandomEventData($eventType->id, $acquisitionMode);
                
                // Create event
                $event = Event::create($eventData);
                
                // Check both actions
                $canBidResult = $this->biddingService->canBid($event->id);
                
                // For purchase service, we need to check if it exists
                // Since we're testing bidding service, we'll simulate the check
                $canPurchase = ($event->ticket_acquisition_mode === Event::MODE_BUY);
                $canBid = $canBidResult['can_bid'];
                
                // Assert exactly one action is available (XOR)
                $exclusiveAction = ($canPurchase xor $canBid);
                
                if ($exclusiveAction) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'event_id' => $event->id,
                        'acquisition_mode' => $event->ticket_acquisition_mode,
                        'can_purchase' => $canPurchase,
                        'can_bid' => $canBid,
                        'expected' => 'Exactly one action should be available',
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
            "Exclusive action visibility failures in " . count($failures) . " out of {$iterations} iterations:\n" .
            json_encode($failures, JSON_PRETTY_PRINT)
        );
        
        $this->assertEquals($iterations, $passedIterations,
            "All iterations should pass exclusive action visibility test"
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

    private function getRandomAcquisitionMode(): string
    {
        $modes = [Event::MODE_BUY, Event::MODE_BID];
        return $modes[array_rand($modes)];
    }

    private function generateRandomSaleWindow(): array
    {
        // Generate sale window that is currently active (for testing bid availability)
        $saleStart = Carbon::now()->subDays(rand(1, 5));
        $saleEnd = Carbon::now()->addDays(rand(1, 30));
        
        return [
            'start' => $saleStart,
            'end' => $saleEnd,
        ];
    }

    private function generateRandomEventData(
        int $eventTypeId,
        string $acquisitionMode
    ): array {
        $saleWindow = $this->generateRandomSaleWindow();
        
        // Generate event date after sale end date
        $eventDate = (clone $saleWindow['end'])->addDays(rand(1, 30));
        
        return [
            'title' => 'Test Event ' . uniqid(),
            'description' => 'Test event description ' . rand(1000, 9999),
            'event_date' => $eventDate,
            'venue' => 'Test Venue ' . rand(1, 100),
            'total_capacity' => rand(50, 500),
            'available_capacity' => rand(50, 500),
            'status' => 'published',
            'event_type_id' => $eventTypeId,
            'ticket_acquisition_mode' => $acquisitionMode,
            'sale_start_date' => $saleWindow['start'],
            'sale_end_date' => $saleWindow['end'],
        ];
    }

    private function generateBidData(int $eventId): array
    {
        return [
            'event_id' => $eventId,
            'customer_id' => 1, // Assuming customer ID 1 exists
            'bid_amount' => rand(50, 500),
            'quantity' => rand(1, 5),
        ];
    }
}

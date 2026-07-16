<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EventManagement\Exceptions\InvalidAcquisitionModeException;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\EventType;
use Modules\EventManagement\Services\PurchaseService;
use Modules\EventManagement\Services\SaleWindowService;
use Tests\TestCase;

/**
 * Property Test 4: Mode-Action Alignment for Direct Purchase
 * Property Test 6: Purchase Prevention for Bidding Events
 *
 * Feature: erp-event-management-phase4
 *
 * Validates Requirements: 4.1, 4.4
 *
 * This property-based test ensures that:
 * 1. Events with Direct Purchase mode only allow purchase actions
 * 2. Events with Bidding mode prevent purchase actions
 */
class ModeActionAlignmentTest extends TestCase
{
    use RefreshDatabase;

    protected PurchaseService $purchaseService;

    protected SaleWindowService $saleWindowService;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed default event types
        $this->seedEventTypes();

        // Initialize services
        $this->saleWindowService = app(SaleWindowService::class);
        $this->purchaseService = app(PurchaseService::class);
    }

    /**
     * Property 4: Mode-Action Alignment for Direct Purchase
     *
     * For any event with Direct Purchase mode, the available actions for customers
     * should only include purchase.
     *
     * Validates: Requirements 4.1
     *
     * @test
     */
    public function property_direct_purchase_mode_only_allows_purchase_action(): void
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

                // Get available actions for customer (check if purchase is allowed)
                $canPurchaseResult = $this->purchaseService->canPurchase($event->id);

                // Assert only purchase action is available
                // For Direct Purchase mode, canPurchase should return true (within sale window)
                if ($canPurchaseResult['can_purchase'] === true) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'event_id' => $event->id,
                        'acquisition_mode' => $event->ticket_acquisition_mode,
                        'can_purchase' => $canPurchaseResult['can_purchase'],
                        'reason' => $canPurchaseResult['reason'] ?? null,
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
            'Mode-action alignment failures for Direct Purchase in '.count($failures)." out of {$iterations} iterations:\n".
            json_encode($failures, JSON_PRETTY_PRINT)
        );

        $this->assertEquals($iterations, $passedIterations,
            'All iterations should pass mode-action alignment test for Direct Purchase'
        );
    }

    /**
     * Property 6: Purchase Prevention for Bidding Events
     *
     * For any event with Bidding mode, attempting to purchase tickets should
     * fail with an error.
     *
     * Validates: Requirements 4.4
     *
     * @test
     */
    public function property_bidding_mode_prevents_purchase_action(): void
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

                // Check if purchase is allowed (should be false)
                $canPurchaseResult = $this->purchaseService->canPurchase($event->id);

                // Assert purchase is not allowed
                if ($canPurchaseResult['can_purchase'] === false &&
                    str_contains($canPurchaseResult['reason'], 'bidding')) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'event_id' => $event->id,
                        'acquisition_mode' => $event->ticket_acquisition_mode,
                        'can_purchase' => $canPurchaseResult['can_purchase'],
                        'reason' => $canPurchaseResult['reason'] ?? null,
                        'expected' => 'Purchase should be prevented for bidding events',
                    ];
                }

                // Also test that actual purchase attempt throws exception
                try {
                    $purchaseData = $this->generatePurchaseData($event->id);
                    $this->purchaseService->purchaseTickets($purchaseData);

                    // If we reach here, the purchase was allowed (should not happen)
                    $failures[] = [
                        'iteration' => $i + 1,
                        'event_id' => $event->id,
                        'acquisition_mode' => $event->ticket_acquisition_mode,
                        'error' => 'Purchase was allowed for bidding event (should have thrown exception)',
                    ];
                    $passedIterations--; // Decrement since this is a failure

                } catch (InvalidAcquisitionModeException $e) {
                    // Expected exception - purchase was correctly prevented
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
            'Purchase prevention failures for Bidding events in '.count($failures)." out of {$iterations} iterations:\n".
            json_encode($failures, JSON_PRETTY_PRINT)
        );

        $this->assertEquals($iterations, $passedIterations,
            'All iterations should pass purchase prevention test for Bidding events'
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

    private function generateRandomSaleWindow(): array
    {
        // Generate sale window that is currently active (for testing purchase availability)
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
            'title' => 'Test Event '.uniqid(),
            'description' => 'Test event description '.rand(1000, 9999),
            'event_date' => $eventDate,
            'venue' => 'Test Venue '.rand(1, 100),
            'total_capacity' => rand(50, 500),
            'available_capacity' => rand(50, 500),
            'status' => 'published',
            'event_type_id' => $eventTypeId,
            'ticket_acquisition_mode' => $acquisitionMode,
            'sale_start_date' => $saleWindow['start'],
            'sale_end_date' => $saleWindow['end'],
        ];
    }

    private function generatePurchaseData(int $eventId): array
    {
        return [
            'event_id' => $eventId,
            'buyer_name' => 'Test Buyer '.uniqid(),
            'buyer_email' => 'buyer'.uniqid().'@test.com',
            'buyer_phone' => '+1234567890',
            'total_amount' => rand(50, 500),
            'currency' => 'USD',
            'attendees' => [
                [
                    'ticket_type_id' => 1,
                    'attendee_name' => 'Attendee '.uniqid(),
                    'attendee_email' => 'attendee'.uniqid().'@test.com',
                ],
            ],
        ];
    }
}

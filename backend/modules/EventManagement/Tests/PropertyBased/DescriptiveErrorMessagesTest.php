<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\EventType;
use Modules\EventManagement\Services\PurchaseService;
use Modules\EventManagement\Services\SaleWindowService;
use Modules\EventManagement\Exceptions\SaleNotStartedException;
use Modules\EventManagement\Exceptions\SaleClosedException;
use Carbon\Carbon;

/**
 * Property Test 20: Descriptive Error Messages
 * 
 * Feature: erp-event-management-phase4
 * 
 * Validates Requirements: 8.7
 * 
 * This property-based test ensures that purchase or bid attempts outside
 * the sale window return descriptive error messages that clearly explain
 * why the action was prevented.
 */
class DescriptiveErrorMessagesTest extends TestCase
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
     * Property 20: Descriptive Error Messages
     * 
     * For any purchase or bid attempt outside the sale window, the error message
     * should clearly describe why the action was prevented (not started, closed,
     * or sold out).
     * 
     * Validates: Requirements 8.7
     * 
     * @test
     */
    public function property_error_messages_are_descriptive_for_sale_window_violations(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate event with sale window
                $eventType = $this->getRandomEventType();
                
                // Randomly choose: before window, after window, or during window
                $windowType = ['before', 'after', 'during'][array_rand(['before', 'after', 'during'])];
                
                $eventData = $this->generateEventDataWithSaleWindow($eventType->id, $windowType);
                $event = Event::create($eventData);
                
                // Attempt to purchase
                $purchaseData = $this->generatePurchaseData($event->id);
                
                try {
                    $this->purchaseService->purchaseTickets($purchaseData);
                    
                    // If purchase succeeded, it should only be during the window
                    if ($windowType === 'during') {
                        $passedIterations++;
                    } else {
                        $failures[] = [
                            'iteration' => $i + 1,
                            'window_type' => $windowType,
                            'error' => 'Purchase succeeded when it should have failed',
                            'event_id' => $event->id,
                        ];
                    }
                    
                } catch (SaleNotStartedException $e) {
                    // Check that error message is descriptive
                    $message = $e->getMessage();
                    
                    if ($windowType === 'before' &&
                        (str_contains($message, 'start') || str_contains($message, 'will start'))) {
                        $passedIterations++;
                    } else {
                        $failures[] = [
                            'iteration' => $i + 1,
                            'window_type' => $windowType,
                            'exception' => 'SaleNotStartedException',
                            'message' => $message,
                            'descriptive' => str_contains($message, 'start') || str_contains($message, 'will start'),
                            'expected' => 'Message should mention when sales will start',
                        ];
                    }
                    
                } catch (SaleClosedException $e) {
                    // Check that error message is descriptive
                    $message = $e->getMessage();
                    
                    if ($windowType === 'after' &&
                        (str_contains($message, 'ended') || str_contains($message, 'closed'))) {
                        $passedIterations++;
                    } else {
                        $failures[] = [
                            'iteration' => $i + 1,
                            'window_type' => $windowType,
                            'exception' => 'SaleClosedException',
                            'message' => $message,
                            'descriptive' => str_contains($message, 'ended') || str_contains($message, 'closed'),
                            'expected' => 'Message should mention that sales have ended',
                        ];
                    }
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
            "Descriptive error message failures in " . count($failures) . " out of {$iterations} iterations:\n" .
            json_encode($failures, JSON_PRETTY_PRINT)
        );
        
        $this->assertEquals($iterations, $passedIterations,
            "All iterations should pass descriptive error message test"
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

    private function generateEventDataWithSaleWindow(int $eventTypeId, string $windowType): array
    {
        $now = Carbon::now();
        
        switch ($windowType) {
            case 'before':
                // Sale starts in the future
                $saleStart = $now->copy()->addDays(rand(1, 10));
                $saleEnd = $saleStart->copy()->addDays(rand(1, 30));
                break;
                
            case 'after':
                // Sale ended in the past
                $saleEnd = $now->copy()->subDays(rand(1, 10));
                $saleStart = $saleEnd->copy()->subDays(rand(1, 30));
                break;
                
            case 'during':
            default:
                // Sale is currently active
                $saleStart = $now->copy()->subDays(rand(1, 5));
                $saleEnd = $now->copy()->addDays(rand(1, 30));
                break;
        }
        
        // Generate event date after sale end date
        $eventDate = $saleEnd->copy()->addDays(rand(1, 30));
        
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
            'sale_start_date' => $saleStart,
            'sale_end_date' => $saleEnd,
        ];
    }

    private function generatePurchaseData(int $eventId): array
    {
        return [
            'event_id' => $eventId,
            'buyer_name' => 'Test Buyer ' . uniqid(),
            'buyer_email' => 'buyer' . uniqid() . '@test.com',
            'buyer_phone' => '+1234567890',
            'total_amount' => rand(50, 500),
            'currency' => 'USD',
            'attendees' => [
                [
                    'ticket_type_id' => 1,
                    'attendee_name' => 'Attendee ' . uniqid(),
                    'attendee_email' => 'attendee' . uniqid() . '@test.com',
                ]
            ],
        ];
    }
}

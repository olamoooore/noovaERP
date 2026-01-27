<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use PHPUnit\Framework\TestCase;
use Carbon\Carbon;
use Modules\EventManagement\Http\Resources\StaffEventResource;
use stdClass;

/**
 * Property Test 10: Staff Inventory Visibility
 * 
 * Feature: erp-event-management-phase4
 * 
 * Validates Requirements: 6.1, 6.2, 6.3
 */
class StaffInventoryVisibilityTest extends TestCase
{
    /**
     * Property 10: Staff Inventory Visibility
     * 
     * @test
     */
    public function property_staff_response_includes_inventory_data(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                $event = $this->createMockEvent();
                $staffResource = new StaffEventResource($event);
                
                $request = \Mockery::mock('Illuminate\Http\Request');
                $request->shouldReceive('wantsJson')->andReturn(true);
                
                $responseArray = $staffResource->toArray($request);
                
                $hasInventoryKey = array_key_exists('inventory', $responseArray);
                $hasTotal = $hasInventoryKey && array_key_exists('total', $responseArray['inventory']);
                $hasSold = $hasInventoryKey && array_key_exists('sold', $responseArray['inventory']);
                $hasRemaining = $hasInventoryKey && array_key_exists('remaining', $responseArray['inventory']);
                
                $totalIsNumeric = $hasTotal && is_numeric($responseArray['inventory']['total']);
                $soldIsNumeric = $hasSold && is_numeric($responseArray['inventory']['sold']);
                $remainingIsNumeric = $hasRemaining && is_numeric($responseArray['inventory']['remaining']);
                
                $mathCorrect = false;
                if ($hasTotal && $hasSold && $hasRemaining) {
                    $total = $responseArray['inventory']['total'];
                    $sold = $responseArray['inventory']['sold'];
                    $remaining = $responseArray['inventory']['remaining'];
                    $mathCorrect = ($total == ($sold + $remaining));
                }
                
                if ($hasInventoryKey && $hasTotal && $hasSold && $hasRemaining && 
                    $totalIsNumeric && $soldIsNumeric && $remainingIsNumeric && $mathCorrect) {
                    $passedIterations++;
                } else {
                    $failures[] = [
                        'iteration' => $i + 1,
                        'inventory_data' => $responseArray['inventory'] ?? null,
                    ];
                }
            } catch (\Exception $e) {
                $failures[] = ['iteration' => $i + 1, 'error' => $e->getMessage()];
            }
        }

        $this->assertEmpty($failures, "Staff inventory visibility failures: " . json_encode($failures));
        $this->assertEquals($iterations, $passedIterations);
    }

    private function createMockEvent()
    {
        $saleWindow = $this->generateRandomSaleWindow();
        $totalCapacity = rand(50, 500);
        $availableCapacity = rand(0, $totalCapacity);
        
        $event = new stdClass();
        $event->id = rand(1, 10000);
        $event->title = 'Test Event ' . uniqid();
        $event->description = 'Test description';
        $event->event_date = (clone $saleWindow['end'])->addDays(rand(1, 30));
        $event->venue = 'Test Venue';
        $event->total_capacity = $totalCapacity;
        $event->available_capacity = $availableCapacity;
        $event->status = 'published';
        $event->event_type_id = 1;
        $event->ticket_acquisition_mode = 'buy';
        $event->sale_start_date = $saleWindow['start'];
        $event->sale_end_date = $saleWindow['end'];
        $event->sale_status = 'open'; // Add sale_status for testing
        $event->terms_conditions = 'Test terms and conditions';
        $event->created_at = Carbon::now();
        $event->updated_at = Carbon::now();
        
        $eventType = new stdClass();
        $eventType->id = 1;
        $eventType->name = 'Concert';
        $eventType->slug = 'concert';
        
        $event->eventType = $eventType;
        
        return $event;
    }

    private function generateRandomSaleWindow(): array
    {
        $saleStart = Carbon::now()->addDays(rand(0, 30));
        $saleEnd = (clone $saleStart)->addDays(rand(1, 60));
        return ['start' => $saleStart, 'end' => $saleEnd];
    }
}

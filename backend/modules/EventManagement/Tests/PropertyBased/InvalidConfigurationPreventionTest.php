<?php

namespace Modules\EventManagement\Tests\PropertyBased;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\EventType;
use Carbon\Carbon;

/**
 * Property Test 25: Invalid Configuration Prevention
 * 
 * Feature: erp-event-management-phase4
 * 
 * Validates Requirements: 11.5
 * 
 * This property-based test ensures that the system prevents creation
 * of events with incomplete or invalid configuration.
 */
class InvalidConfigurationPreventionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Seed default event types
        $this->seedEventTypes();
    }

    /**
     * Property 25: Invalid Configuration Prevention
     * 
     * For any event data with incomplete or invalid configuration, the system
     * should prevent creation and return validation errors.
     * 
     * Validates: Requirements 11.5
     * 
     * @test
     */
    public function property_invalid_configuration_is_prevented(): void
    {
        $iterations = 100;
        $passedIterations = 0;
        $failures = [];

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Generate incomplete/invalid event data
                $invalidData = $this->generateIncompleteEventData();
                
                // Attempt to create event
                try {
                    Event::create($invalidData);
                    
                    // If we reach here, validation failed to prevent creation
                    $failures[] = [
                        'iteration' => $i + 1,
                        'data' => $invalidData,
                        'reason' => 'Event created with incomplete/invalid configuration',
                    ];
                } catch (\Exception $e) {
                    // Expected: should fail
                    // Verify error message is meaningful
                    if (!empty($e->getMessage())) {
                        $passedIterations++;
                    } else {
                        $failures[] = [
                            'iteration' => $i + 1,
                            'reason' => 'Exception thrown but message is empty',
                        ];
                    }
                }
                
            } catch (\Exception $e) {
                $failures[] = [
                    'iteration' => $i + 1,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $this->assertEmpty($failures,
            "Invalid configuration prevention failures in " . count($failures) . " out of {$iterations} iterations:\n" .
            json_encode($failures, JSON_PRETTY_PRINT)
        );
        
        $this->assertEquals($iterations, $passedIterations,
            "All iterations should correctly prevent invalid configurations"
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

    private function generateIncompleteEventData(): array
    {
        // Randomly choose which configuration to make invalid
        $invalidationType = rand(1, 6);
        
        $eventType = EventType::where('is_active', true)->first();
        $saleStart = Carbon::now()->addDays(rand(1, 10));
        $saleEnd = (clone $saleStart)->addDays(rand(1, 20));
        $eventDate = (clone $saleEnd)->addDays(rand(1, 30));
        
        $data = [
            'title' => 'Test Event ' . uniqid(),
            'description' => 'Test event description',
            'event_date' => $eventDate,
            'venue' => 'Test Venue',
            'total_capacity' => rand(50, 500),
            'available_capacity' => rand(50, 500),
            'status' => 'draft',
        ];
        
        switch ($invalidationType) {
            case 1:
                // Missing event_type_id
                $data['ticket_acquisition_mode'] = Event::MODE_BUY;
                $data['sale_start_date'] = $saleStart;
                $data['sale_end_date'] = $saleEnd;
                break;
                
            case 2:
                // Missing ticket_acquisition_mode
                $data['event_type_id'] = $eventType->id;
                $data['sale_start_date'] = $saleStart;
                $data['sale_end_date'] = $saleEnd;
                break;
                
            case 3:
                // Missing sale_start_date
                $data['event_type_id'] = $eventType->id;
                $data['ticket_acquisition_mode'] = Event::MODE_BUY;
                $data['sale_end_date'] = $saleEnd;
                break;
                
            case 4:
                // Missing sale_end_date
                $data['event_type_id'] = $eventType->id;
                $data['ticket_acquisition_mode'] = Event::MODE_BUY;
                $data['sale_start_date'] = $saleStart;
                break;
                
            case 5:
                // Invalid acquisition mode
                $data['event_type_id'] = $eventType->id;
                $data['ticket_acquisition_mode'] = 'invalid_mode';
                $data['sale_start_date'] = $saleStart;
                $data['sale_end_date'] = $saleEnd;
                break;
                
            case 6:
                // Invalid sale window (end before start)
                $data['event_type_id'] = $eventType->id;
                $data['ticket_acquisition_mode'] = Event::MODE_BUY;
                $data['sale_start_date'] = $saleEnd;
                $data['sale_end_date'] = $saleStart;
                break;
        }
        
        return $data;
    }
}

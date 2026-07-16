<?php

namespace Modules\EventManagement\Tests\Performance;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Services\BookingService;
use Modules\EventManagement\Services\EventService;
use Modules\EventManagement\Services\TenantSchemaManager;
use Tests\TestCase;

/**
 * Performance Test for Concurrent Booking Scenarios
 *
 * Tests concurrent booking scenarios and capacity enforcement under load
 * Validates: Requirements 1.4, 1.5, 9.2, 9.4
 */
class ConcurrentBookingTest extends TestCase
{
    use RefreshDatabase;

    private EventService $eventService;

    private BookingService $bookingService;

    private AuditService $auditService;

    private TenantSchemaManager $tenantSchemaManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Run migrations to set up the database schema
        $this->artisan('migrate', [
            '--path' => 'backend/modules/EventManagement/Database/Migrations',
            '--realpath' => true,
        ]);

        // Set up service dependencies
        $this->auditService = $this->createMock(AuditService::class);
        $this->tenantSchemaManager = $this->createMock(TenantSchemaManager::class);

        $this->eventService = new EventService(
            $this->tenantSchemaManager,
            $this->auditService
        );

        $this->bookingService = new BookingService(
            $this->tenantSchemaManager,
            $this->auditService
        );
    }

    /**
     * Test concurrent booking attempts on limited capacity event
     *
     * @test
     */
    public function test_concurrent_booking_attempts_on_limited_capacity_event()
    {
        // Create event with very limited capacity
        $capacity = 10;
        $event = $this->createTestEventWithCapacity($capacity);

        // Simulate concurrent booking attempts (more than capacity)
        $concurrentAttempts = 20;
        $successfulBookings = [];
        $failedBookings = [];

        $startTime = microtime(true);

        // Simulate concurrent booking attempts
        for ($i = 0; $i < $concurrentAttempts; $i++) {
            try {
                $bookingData = $this->generateBookingData($event->id, "concurrent-buyer-{$i}@example.com");
                $booking = $this->bookingService->createBooking($bookingData);
                $successfulBookings[] = $booking;
            } catch (\Exception $e) {
                $failedBookings[] = [
                    'attempt' => $i,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Verify capacity constraints were enforced
        $this->assertLessThanOrEqual($capacity, count($successfulBookings),
            'Successful bookings should not exceed event capacity');

        $this->assertGreaterThan(0, count($failedBookings),
            'Some bookings should fail due to capacity constraints');

        // Verify event capacity is correctly updated
        $event->refresh();
        $expectedAvailableCapacity = $capacity - count($successfulBookings);
        $this->assertEquals($expectedAvailableCapacity, $event->available_capacity,
            'Event available capacity should be correctly updated');

        // Verify performance - concurrent operations should complete in reasonable time
        $this->assertLessThan(5.0, $executionTime,
            'Concurrent booking operations should complete within 5 seconds');

        // Verify database consistency
        $dbBookingCount = DB::table('bookings')->where('event_id', $event->id)->count();
        $this->assertEquals(count($successfulBookings), $dbBookingCount,
            'Database booking count should match successful bookings');

        // Verify all successful bookings have unique references
        $bookingReferences = array_map(function ($booking) {
            return $booking->booking_reference;
        }, $successfulBookings);

        $this->assertEquals(count($bookingReferences), count(array_unique($bookingReferences)),
            'All booking references should be unique');
    }

    /**
     * Test capacity enforcement under high load
     *
     * @test
     */
    public function test_capacity_enforcement_under_high_load()
    {
        // Create multiple events with different capacities
        $events = [
            $this->createTestEventWithCapacity(5),
            $this->createTestEventWithCapacity(10),
            $this->createTestEventWithCapacity(15),
        ];

        $totalAttempts = 0;
        $totalSuccessful = 0;
        $totalFailed = 0;

        $startTime = microtime(true);

        // Simulate high load across multiple events
        foreach ($events as $eventIndex => $event) {
            $attemptsPerEvent = $event->total_capacity + 10; // Attempt more than capacity

            for ($i = 0; $i < $attemptsPerEvent; $i++) {
                $totalAttempts++;

                try {
                    $bookingData = $this->generateBookingData(
                        $event->id,
                        "load-test-{$eventIndex}-{$i}@example.com"
                    );
                    $booking = $this->bookingService->createBooking($bookingData);
                    $totalSuccessful++;
                } catch (\Exception $e) {
                    $totalFailed++;
                }
            }
        }

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Verify capacity constraints across all events
        $totalExpectedCapacity = array_sum(array_map(function ($event) {
            return $event->total_capacity;
        }, $events));

        $this->assertEquals($totalExpectedCapacity, $totalSuccessful,
            'Total successful bookings should equal total capacity across all events');

        $this->assertGreaterThan(0, $totalFailed,
            'Some bookings should fail due to capacity constraints');

        // Verify performance under load
        $this->assertLessThan(10.0, $executionTime,
            'High load operations should complete within 10 seconds');

        // Verify each event's capacity is correctly managed
        foreach ($events as $event) {
            $event->refresh();
            $this->assertEquals(0, $event->available_capacity,
                "Event {$event->id} should have zero available capacity");
            $this->assertEquals('sold_out', $event->status,
                "Event {$event->id} should be marked as sold out");
        }

        // Verify database integrity
        $totalDbBookings = DB::table('bookings')->whereIn('event_id',
            array_map(function ($event) {
                return $event->id;
            }, $events)
        )->count();

        $this->assertEquals($totalSuccessful, $totalDbBookings,
            'Database booking count should match successful bookings');
    }

    /**
     * Test database constraint enforcement under concurrent operations
     *
     * @test
     */
    public function test_database_constraint_enforcement_under_concurrent_operations()
    {
        $event = $this->createTestEventWithCapacity(50);

        $concurrentBookings = 25;
        $bookings = [];

        // Create multiple bookings concurrently
        for ($i = 0; $i < $concurrentBookings; $i++) {
            $bookingData = $this->generateBookingData($event->id, "constraint-test-{$i}@example.com");
            $booking = $this->bookingService->createBooking($bookingData);
            $bookings[] = $booking;
        }

        // Test unique constraint enforcement
        $bookingReferences = DB::table('bookings')
            ->whereIn('id', array_map(function ($b) {
                return $b->id;
            }, $bookings))
            ->pluck('booking_reference')
            ->toArray();

        $this->assertEquals(count($bookingReferences), count(array_unique($bookingReferences)),
            'All booking references should be unique (database constraint)');

        // Test foreign key constraint enforcement
        foreach ($bookings as $booking) {
            $this->assertDatabaseHas('bookings', [
                'id' => $booking->id,
                'event_id' => $event->id,
            ]);

            // Verify attendees are properly linked
            $attendeeCount = DB::table('booking_attendees')
                ->where('booking_id', $booking->id)
                ->count();

            $this->assertGreaterThan(0, $attendeeCount,
                'Each booking should have at least one attendee');
        }

        // Test cascade delete constraint
        $testBooking = $bookings[0];
        $attendeeCountBefore = DB::table('booking_attendees')
            ->where('booking_id', $testBooking->id)
            ->count();

        $this->assertGreaterThan(0, $attendeeCountBefore,
            'Test booking should have attendees before deletion');

        // Delete booking should cascade to attendees
        DB::table('bookings')->where('id', $testBooking->id)->delete();

        $attendeeCountAfter = DB::table('booking_attendees')
            ->where('booking_id', $testBooking->id)
            ->count();

        $this->assertEquals(0, $attendeeCountAfter,
            'Attendees should be deleted when booking is deleted (cascade constraint)');
    }

    /**
     * Test performance of capacity checking operations
     *
     * @test
     */
    public function test_performance_of_capacity_checking_operations()
    {
        $event = $this->createTestEventWithCapacity(1000);

        // Create some bookings to have realistic data
        for ($i = 0; $i < 100; $i++) {
            $bookingData = $this->generateBookingData($event->id, "perf-test-{$i}@example.com");
            $this->bookingService->createBooking($bookingData);
        }

        // Test capacity checking performance
        $iterations = 1000;
        $startTime = microtime(true);

        for ($i = 0; $i < $iterations; $i++) {
            $requestedTickets = rand(1, 10);
            $isAvailable = $this->eventService->checkAvailability($event->id, $requestedTickets);

            // Verify the result is boolean
            $this->assertIsBool($isAvailable, 'Availability check should return boolean');
        }

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Performance assertion - 1000 capacity checks should be fast
        $this->assertLessThan(1.0, $executionTime,
            '1000 capacity checks should complete within 1 second');

        $averageTime = $executionTime / $iterations;
        $this->assertLessThan(0.001, $averageTime,
            'Average capacity check should be under 1ms');
    }

    /**
     * Test state transition performance under load
     *
     * @test
     */
    public function test_state_transition_performance_under_load()
    {
        $event = $this->createTestEventWithCapacity(100);

        // Create multiple bookings
        $bookings = [];
        for ($i = 0; $i < 50; $i++) {
            $bookingData = $this->generateBookingData($event->id, "state-test-{$i}@example.com");
            $booking = $this->bookingService->createBooking($bookingData);
            $bookings[] = $booking;
        }

        // Test state transition performance
        $startTime = microtime(true);

        // Transition all bookings through states
        foreach ($bookings as $booking) {
            // new -> pending
            $booking = $this->bookingService->transitionState($booking->id, 'pending');
            $this->assertEquals('pending', $booking->state);

            // pending -> confirmed
            $booking = $this->bookingService->transitionState($booking->id, 'confirmed');
            $this->assertEquals('confirmed', $booking->state);

            // confirmed -> booked
            $booking = $this->bookingService->transitionState($booking->id, 'booked');
            $this->assertEquals('booked', $booking->state);
        }

        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;

        // Performance assertion - state transitions should be efficient
        $totalTransitions = count($bookings) * 3; // 3 transitions per booking
        $this->assertLessThan(5.0, $executionTime,
            'State transitions should complete within 5 seconds');

        $averageTransitionTime = $executionTime / $totalTransitions;
        $this->assertLessThan(0.1, $averageTransitionTime,
            'Average state transition should be under 100ms');

        // Verify all bookings reached final state
        foreach ($bookings as $booking) {
            $booking->refresh();
            $this->assertEquals('booked', $booking->state);
        }
    }

    /**
     * Test memory usage during bulk operations
     *
     * @test
     */
    public function test_memory_usage_during_bulk_operations()
    {
        $event = $this->createTestEventWithCapacity(500);

        $memoryBefore = memory_get_usage(true);

        // Create many bookings to test memory usage
        $bookingCount = 200;
        $bookings = [];

        for ($i = 0; $i < $bookingCount; $i++) {
            $bookingData = $this->generateBookingData($event->id, "memory-test-{$i}@example.com");
            $booking = $this->bookingService->createBooking($bookingData);
            $bookings[] = $booking;

            // Periodically check memory usage
            if ($i % 50 === 0) {
                $currentMemory = memory_get_usage(true);
                $memoryIncrease = $currentMemory - $memoryBefore;

                // Memory increase should be reasonable (less than 50MB for 50 bookings)
                $this->assertLessThan(50 * 1024 * 1024, $memoryIncrease,
                    'Memory usage should not increase excessively during bulk operations');
            }
        }

        $memoryAfter = memory_get_usage(true);
        $totalMemoryIncrease = $memoryAfter - $memoryBefore;

        // Total memory increase should be reasonable (less than 100MB for 200 bookings)
        $this->assertLessThan(100 * 1024 * 1024, $totalMemoryIncrease,
            'Total memory increase should be reasonable for bulk operations');

        // Verify all bookings were created successfully
        $this->assertCount($bookingCount, $bookings);

        // Verify database consistency
        $dbBookingCount = DB::table('bookings')->where('event_id', $event->id)->count();
        $this->assertEquals($bookingCount, $dbBookingCount);
    }

    /**
     * Create a test event with specific capacity
     */
    private function createTestEventWithCapacity(int $capacity): Event
    {
        $eventData = [
            'title' => 'Performance Test Event '.rand(1000, 9999),
            'description' => 'Event for performance testing',
            'event_date' => Carbon::now()->addDays(30)->toDateTimeString(),
            'venue' => 'Performance Test Venue',
            'total_capacity' => $capacity,
            'terms_conditions' => 'Performance test terms',
        ];

        $event = $this->eventService->createEvent($eventData);

        return $this->eventService->publishEvent($event->id);
    }

    /**
     * Generate booking data for testing
     */
    private function generateBookingData(int $eventId, string $buyerEmail): array
    {
        return [
            'event_id' => $eventId,
            'buyer_name' => 'Performance Test Buyer',
            'buyer_email' => $buyerEmail,
            'buyer_phone' => '+1'.rand(1000000000, 9999999999),
            'total_amount' => 50.00,
            'currency' => 'USD',
            'consent_pdpa' => true,
            'consent_marketing' => false,
            'attendees' => [
                [
                    'ticket_type_id' => 1,
                    'attendee_name' => 'Performance Test Attendee',
                    'attendee_email' => $buyerEmail,
                    'attendee_phone' => '+1'.rand(1000000000, 9999999999),
                ],
            ],
        ];
    }
}

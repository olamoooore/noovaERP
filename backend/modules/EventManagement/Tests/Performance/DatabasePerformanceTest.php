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
use Modules\EventManagement\Services\TicketService;
use Tests\TestCase;

/**
 * Performance Test for Database Operations
 *
 * Tests database constraint enforcement and query performance under load
 * Validates: Requirements 9.2, 9.4
 */
class DatabasePerformanceTest extends TestCase
{
    use RefreshDatabase;

    private EventService $eventService;

    private BookingService $bookingService;

    private TicketService $ticketService;

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

        $this->ticketService = new TicketService(
            $this->tenantSchemaManager,
            $this->auditService
        );
    }

    /**
     * Test database constraint enforcement under high load
     *
     * @test
     */
    public function test_database_constraint_enforcement_under_high_load()
    {
        // Create test data
        $event = $this->createTestEvent();
        $bookingCount = 100;

        $startTime = microtime(true);

        // Create many bookings to test constraint enforcement
        $bookings = [];
        for ($i = 0; $i < $bookingCount; $i++) {
            $bookingData = $this->generateBookingData($event->id, "constraint-{$i}@example.com");
            $booking = $this->bookingService->createBooking($bookingData);
            $bookings[] = $booking;
        }

        $endTime = microtime(true);
        $creationTime = $endTime - $startTime;

        // Performance assertion
        $this->assertLessThan(10.0, $creationTime,
            'Creating 100 bookings should complete within 10 seconds');

        // Test unique constraint enforcement
        $bookingReferences = DB::table('bookings')
            ->whereIn('id', array_map(function ($b) {
                return $b->id;
            }, $bookings))
            ->pluck('booking_reference')
            ->toArray();

        $this->assertEquals(count($bookingReferences), count(array_unique($bookingReferences)),
            'All booking references should be unique');

        // Test foreign key constraint enforcement
        $startTime = microtime(true);

        foreach ($bookings as $booking) {
            // Verify foreign key relationships
            $attendees = DB::table('booking_attendees')
                ->where('booking_id', $booking->id)
                ->get();

            $this->assertGreaterThan(0, $attendees->count(),
                'Each booking should have attendees (foreign key constraint)');

            foreach ($attendees as $attendee) {
                $this->assertEquals($booking->id, $attendee->booking_id,
                    'Attendee should be linked to correct booking');
            }
        }

        $endTime = microtime(true);
        $verificationTime = $endTime - $startTime;

        $this->assertLessThan(5.0, $verificationTime,
            'Foreign key verification should complete within 5 seconds');
    }

    /**
     * Test index performance on large datasets
     *
     * @test
     */
    public function test_index_performance_on_large_datasets()
    {
        // Create multiple events
        $events = [];
        for ($i = 0; $i < 10; $i++) {
            $events[] = $this->createTestEvent("Performance Event {$i}");
        }

        // Create many bookings across events
        $totalBookings = 500;
        $bookingsPerEvent = $totalBookings / count($events);

        foreach ($events as $eventIndex => $event) {
            for ($i = 0; $i < $bookingsPerEvent; $i++) {
                $bookingData = $this->generateBookingData(
                    $event->id,
                    "index-test-{$eventIndex}-{$i}@example.com"
                );
                $this->bookingService->createBooking($bookingData);
            }
        }

        // Test query performance with indexes
        $startTime = microtime(true);

        // Test event_id index performance
        foreach ($events as $event) {
            $bookings = DB::table('bookings')
                ->where('event_id', $event->id)
                ->get();

            $this->assertGreaterThan(0, $bookings->count(),
                'Should find bookings for each event');
        }

        $endTime = microtime(true);
        $queryTime = $endTime - $startTime;

        $this->assertLessThan(1.0, $queryTime,
            'Indexed queries should complete within 1 second');

        // Test booking_reference index performance
        $startTime = microtime(true);

        $sampleBookings = DB::table('bookings')->limit(50)->get();
        foreach ($sampleBookings as $booking) {
            $foundBooking = DB::table('bookings')
                ->where('booking_reference', $booking->booking_reference)
                ->first();

            $this->assertNotNull($foundBooking,
                'Should find booking by reference (unique index)');
            $this->assertEquals($booking->id, $foundBooking->id);
        }

        $endTime = microtime(true);
        $referenceQueryTime = $endTime - $startTime;

        $this->assertLessThan(0.5, $referenceQueryTime,
            'Booking reference queries should be very fast with unique index');
    }

    /**
     * Test transaction performance and rollback scenarios
     *
     * @test
     */
    public function test_transaction_performance_and_rollback_scenarios()
    {
        $event = $this->createTestEvent();

        // Test successful transaction performance
        $startTime = microtime(true);

        DB::transaction(function () use ($event) {
            for ($i = 0; $i < 50; $i++) {
                $bookingData = $this->generateBookingData($event->id, "transaction-{$i}@example.com");
                $this->bookingService->createBooking($bookingData);
            }
        });

        $endTime = microtime(true);
        $transactionTime = $endTime - $startTime;

        $this->assertLessThan(5.0, $transactionTime,
            'Transaction with 50 bookings should complete within 5 seconds');

        // Verify all bookings were created
        $bookingCount = DB::table('bookings')->where('event_id', $event->id)->count();
        $this->assertEquals(50, $bookingCount, 'All bookings should be committed');

        // Test rollback scenario
        $initialBookingCount = DB::table('bookings')->where('event_id', $event->id)->count();

        try {
            DB::transaction(function () use ($event) {
                // Create some bookings
                for ($i = 0; $i < 10; $i++) {
                    $bookingData = $this->generateBookingData($event->id, "rollback-{$i}@example.com");
                    $this->bookingService->createBooking($bookingData);
                }

                // Force an exception to trigger rollback
                throw new \Exception('Forced rollback for testing');
            });
        } catch (\Exception $e) {
            // Expected exception
        }

        // Verify rollback occurred
        $finalBookingCount = DB::table('bookings')->where('event_id', $event->id)->count();
        $this->assertEquals($initialBookingCount, $finalBookingCount,
            'Booking count should be unchanged after rollback');
    }

    /**
     * Test cascade delete performance
     *
     * @test
     */
    public function test_cascade_delete_performance()
    {
        $event = $this->createTestEvent();

        // Create bookings with attendees
        $bookings = [];
        for ($i = 0; $i < 20; $i++) {
            $bookingData = $this->generateBookingDataWithMultipleAttendees(
                $event->id,
                "cascade-{$i}@example.com",
                5 // 5 attendees per booking
            );
            $booking = $this->bookingService->createBooking($bookingData);
            $bookings[] = $booking;
        }

        // Verify initial state
        $initialBookingCount = DB::table('bookings')->where('event_id', $event->id)->count();
        $initialAttendeeCount = DB::table('booking_attendees')
            ->whereIn('booking_id', array_map(function ($b) {
                return $b->id;
            }, $bookings))
            ->count();

        $this->assertEquals(20, $initialBookingCount);
        $this->assertEquals(100, $initialAttendeeCount); // 20 bookings * 5 attendees

        // Test cascade delete performance
        $startTime = microtime(true);

        // Delete half the bookings
        $bookingsToDelete = array_slice($bookings, 0, 10);
        $bookingIdsToDelete = array_map(function ($b) {
            return $b->id;
        }, $bookingsToDelete);

        DB::table('bookings')->whereIn('id', $bookingIdsToDelete)->delete();

        $endTime = microtime(true);
        $deleteTime = $endTime - $startTime;

        $this->assertLessThan(2.0, $deleteTime,
            'Cascade delete should complete within 2 seconds');

        // Verify cascade delete worked
        $remainingBookingCount = DB::table('bookings')->where('event_id', $event->id)->count();
        $remainingAttendeeCount = DB::table('booking_attendees')
            ->whereIn('booking_id', array_map(function ($b) {
                return $b->id;
            }, $bookings))
            ->count();

        $this->assertEquals(10, $remainingBookingCount, 'Half the bookings should remain');
        $this->assertEquals(50, $remainingAttendeeCount, 'Half the attendees should remain');

        // Verify deleted attendees are gone
        $deletedAttendeeCount = DB::table('booking_attendees')
            ->whereIn('booking_id', $bookingIdsToDelete)
            ->count();

        $this->assertEquals(0, $deletedAttendeeCount,
            'Attendees should be cascade deleted with bookings');
    }

    /**
     * Test bulk insert performance
     *
     * @test
     */
    public function test_bulk_insert_performance()
    {
        $event = $this->createTestEvent();

        // Prepare bulk data
        $bulkBookingData = [];
        $bulkAttendeeData = [];

        for ($i = 0; $i < 100; $i++) {
            $bookingReference = 'BK'.strtoupper(substr(md5(rand()), 0, 8));

            $bulkBookingData[] = [
                'booking_reference' => $bookingReference,
                'event_id' => $event->id,
                'buyer_name' => "Bulk Buyer {$i}",
                'buyer_email' => "bulk-{$i}@example.com",
                'buyer_phone' => '+1'.rand(1000000000, 9999999999),
                'total_amount' => 50.00,
                'currency' => 'USD',
                'state' => 'new',
                'consent_pdpa' => true,
                'consent_marketing' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Test bulk insert performance
        $startTime = microtime(true);

        DB::table('bookings')->insert($bulkBookingData);

        $endTime = microtime(true);
        $bulkInsertTime = $endTime - $startTime;

        $this->assertLessThan(1.0, $bulkInsertTime,
            'Bulk insert of 100 bookings should complete within 1 second');

        // Verify all records were inserted
        $insertedCount = DB::table('bookings')
            ->where('buyer_name', 'like', 'Bulk Buyer%')
            ->count();

        $this->assertEquals(100, $insertedCount, 'All bulk records should be inserted');

        // Test bulk insert with duplicate constraint violation
        $duplicateData = $bulkBookingData[0]; // Reuse first booking data

        $this->expectException(\Exception::class);
        DB::table('bookings')->insert([$duplicateData]);
    }

    /**
     * Test query optimization with complex joins
     *
     * @test
     */
    public function test_query_optimization_with_complex_joins()
    {
        // Create test data
        $events = [];
        for ($i = 0; $i < 5; $i++) {
            $events[] = $this->createTestEvent("Join Test Event {$i}");
        }

        // Create bookings and attendees
        foreach ($events as $event) {
            for ($i = 0; $i < 20; $i++) {
                $bookingData = $this->generateBookingDataWithMultipleAttendees(
                    $event->id,
                    "join-test-{$event->id}-{$i}@example.com",
                    3
                );
                $this->bookingService->createBooking($bookingData);
            }
        }

        // Test complex join query performance
        $startTime = microtime(true);

        $results = DB::table('events')
            ->join('bookings', 'events.id', '=', 'bookings.event_id')
            ->join('booking_attendees', 'bookings.id', '=', 'booking_attendees.booking_id')
            ->select([
                'events.title',
                'events.total_capacity',
                'bookings.booking_reference',
                'bookings.buyer_name',
                'booking_attendees.attendee_name',
            ])
            ->where('events.status', 'published')
            ->orderBy('events.title')
            ->orderBy('bookings.created_at')
            ->get();

        $endTime = microtime(true);
        $joinQueryTime = $endTime - $startTime;

        $this->assertLessThan(2.0, $joinQueryTime,
            'Complex join query should complete within 2 seconds');

        $this->assertGreaterThan(0, $results->count(),
            'Join query should return results');

        // Test aggregation query performance
        $startTime = microtime(true);

        $aggregationResults = DB::table('events')
            ->leftJoin('bookings', 'events.id', '=', 'bookings.event_id')
            ->leftJoin('booking_attendees', 'bookings.id', '=', 'booking_attendees.booking_id')
            ->select([
                'events.id',
                'events.title',
                'events.total_capacity',
                DB::raw('COUNT(DISTINCT bookings.id) as booking_count'),
                DB::raw('COUNT(booking_attendees.id) as attendee_count'),
                DB::raw('SUM(bookings.total_amount) as total_revenue'),
            ])
            ->groupBy('events.id', 'events.title', 'events.total_capacity')
            ->get();

        $endTime = microtime(true);
        $aggregationQueryTime = $endTime - $startTime;

        $this->assertLessThan(1.0, $aggregationQueryTime,
            'Aggregation query should complete within 1 second');

        $this->assertEquals(count($events), $aggregationResults->count(),
            'Aggregation should return one row per event');

        // Verify aggregation accuracy
        foreach ($aggregationResults as $result) {
            $this->assertGreaterThan(0, $result->booking_count,
                'Each event should have bookings');
            $this->assertGreaterThan(0, $result->attendee_count,
                'Each event should have attendees');
            $this->assertGreaterThan(0, $result->total_revenue,
                'Each event should have revenue');
        }
    }

    /**
     * Create a test event
     */
    private function createTestEvent(?string $title = null): Event
    {
        $title = $title ?? 'Performance Test Event '.rand(1000, 9999);

        $eventData = [
            'title' => $title,
            'description' => 'Event for performance testing',
            'event_date' => Carbon::now()->addDays(30)->toDateTimeString(),
            'venue' => 'Performance Test Venue',
            'total_capacity' => 1000,
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

    /**
     * Generate booking data with multiple attendees
     */
    private function generateBookingDataWithMultipleAttendees(int $eventId, string $buyerEmail, int $attendeeCount): array
    {
        $attendees = [];
        for ($i = 0; $i < $attendeeCount; $i++) {
            $attendees[] = [
                'ticket_type_id' => 1,
                'attendee_name' => "Attendee {$i}",
                'attendee_email' => str_replace('@', "+{$i}@", $buyerEmail),
                'attendee_phone' => '+1'.rand(1000000000, 9999999999),
            ];
        }

        return [
            'event_id' => $eventId,
            'buyer_name' => 'Performance Test Buyer',
            'buyer_email' => $buyerEmail,
            'buyer_phone' => '+1'.rand(1000000000, 9999999999),
            'total_amount' => 50.00 * $attendeeCount,
            'currency' => 'USD',
            'consent_pdpa' => true,
            'consent_marketing' => false,
            'attendees' => $attendees,
        ];
    }
}

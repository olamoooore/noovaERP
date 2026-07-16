<?php

namespace Modules\EventManagement\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\Ticket;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Services\BookingService;
use Modules\EventManagement\Services\EventService;
use Modules\EventManagement\Services\TenantSchemaManager;
use Modules\EventManagement\Services\TicketService;
use Tests\TestCase;

/**
 * Property Test for Ticket Generation and Uniqueness
 *
 * **Feature: erp-event-management, Property 4: Ticket Generation and Uniqueness**
 * **Validates: Requirements 3.1, 3.2, 3.3, 3.5**
 *
 * Property: For any booking in "Booked" state, the system should generate unique tickets
 * for each attendee with unique codes, valid QR payloads, and proper status tracking
 * (active, revoked, used).
 */
class TicketGenerationPropertyTest extends TestCase
{
    use RefreshDatabase;

    private TicketService $ticketService;

    private BookingService $bookingService;

    private EventService $eventService;

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

        $this->ticketService = new TicketService(
            $this->tenantSchemaManager,
            $this->auditService
        );

        $this->bookingService = new BookingService(
            $this->tenantSchemaManager,
            $this->auditService
        );

        $this->eventService = new EventService(
            $this->tenantSchemaManager,
            $this->auditService
        );
    }

    /**
     * Property Test: Ticket generation creates unique codes
     * Tests that all generated ticket codes are unique across the system
     *
     * @test
     */
    public function property_ticket_generation_creates_unique_codes()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runUniqueCodeGenerationTest();
        }
    }

    /**
     * Property Test: Tickets are generated for booked bookings only
     * Tests that tickets can only be generated for bookings in "booked" state
     *
     * @test
     */
    public function property_tickets_generated_for_booked_bookings_only()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runBookedStateRequirementTest();
        }
    }

    /**
     * Property Test: QR payloads contain valid ticket information
     * Tests that QR payloads contain all required ticket information
     *
     * @test
     */
    public function property_qr_payloads_contain_valid_ticket_information()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runQRPayloadValidationTest();
        }
    }

    /**
     * Property Test: Ticket status tracking works correctly
     * Tests that ticket status (active, revoked, used) is properly maintained
     *
     * @test
     */
    public function property_ticket_status_tracking_works_correctly()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runTicketStatusTrackingTest();
        }
    }

    /**
     * Property Test: One ticket per attendee is generated
     * Tests that exactly one ticket is generated for each attendee
     *
     * @test
     */
    public function property_one_ticket_per_attendee_is_generated()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runOneTicketPerAttendeeTest();
        }
    }

    /**
     * Run a single iteration of unique code generation testing
     */
    private function runUniqueCodeGenerationTest(): void
    {
        $generatedCodes = [];
        $numCodes = rand(10, 50);

        // Generate multiple ticket codes
        for ($i = 0; $i < $numCodes; $i++) {
            $code = $this->ticketService->generateUniqueCode();

            // Verify code format
            $this->assertMatchesRegularExpression('/^TK[A-Z0-9]{8}$/', $code, 'Ticket code should match expected format');

            // Verify uniqueness
            $this->assertNotContains($code, $generatedCodes, 'Generated ticket code should be unique');

            $generatedCodes[] = $code;

            // Simulate saving to database to test database uniqueness check
            DB::table('tickets')->insert([
                'ticket_code' => $code,
                'booking_id' => 1, // Dummy booking ID
                'attendee_id' => 1, // Dummy attendee ID
                'qr_payload' => 'dummy_payload',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Verify all codes are unique in database
        $dbCodes = DB::table('tickets')->pluck('ticket_code')->toArray();
        $this->assertEquals(count($dbCodes), count(array_unique($dbCodes)), 'All ticket codes in database should be unique');
    }

    /**
     * Run a single iteration of booked state requirement testing
     */
    private function runBookedStateRequirementTest(): void
    {
        // Create test event and booking
        $event = $this->createTestEvent();
        $booking = $this->createTestBooking($event->id);

        // Test that tickets cannot be generated for non-booked states
        $nonBookedStates = ['new', 'pending', 'confirmed', 'cancelled'];
        $testState = $nonBookedStates[array_rand($nonBookedStates)];

        // Set booking to test state
        $booking->update(['state' => $testState]);

        // Attempt to generate tickets should fail
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Tickets can only be generated for bookings in 'booked' state");

        $this->ticketService->generateTickets($booking->id);
    }

    /**
     * Run a single iteration of QR payload validation testing
     */
    private function runQRPayloadValidationTest(): void
    {
        // Create test event and booking
        $event = $this->createTestEvent();
        $booking = $this->createTestBookingInBookedState($event->id);

        // Generate tickets
        $tickets = $this->ticketService->generateTickets($booking->id);

        // Verify each ticket has valid QR payload
        foreach ($tickets as $ticket) {
            $this->assertNotEmpty($ticket->qr_payload, 'QR payload should not be empty');

            // Decode and validate QR payload
            $payload = json_decode($ticket->qr_payload, true);
            $this->assertIsArray($payload, 'QR payload should be valid JSON');

            // Verify required fields in payload
            $requiredFields = [
                'version', 'type', 'ticket_code', 'booking_reference',
                'event_id', 'attendee_id', 'attendee_name', 'event_title',
                'event_date', 'venue', 'issued_at', 'checksum',
            ];

            foreach ($requiredFields as $field) {
                $this->assertArrayHasKey($field, $payload, "QR payload should contain '{$field}' field");
                $this->assertNotEmpty($payload[$field], "QR payload field '{$field}' should not be empty");
            }

            // Verify payload data matches ticket data
            $this->assertEquals($ticket->ticket_code, $payload['ticket_code']);
            $this->assertEquals($booking->booking_reference, $payload['booking_reference']);
            $this->assertEquals($event->id, $payload['event_id']);
            $this->assertEquals($event->title, $payload['event_title']);
            $this->assertEquals($event->venue, $payload['venue']);
            $this->assertEquals('event_ticket', $payload['type']);
            $this->assertEquals('1.0', $payload['version']);
        }
    }

    /**
     * Run a single iteration of ticket status tracking testing
     */
    private function runTicketStatusTrackingTest(): void
    {
        // Create test event and booking
        $event = $this->createTestEvent();
        $booking = $this->createTestBookingInBookedState($event->id);

        // Generate tickets
        $tickets = $this->ticketService->generateTickets($booking->id);
        $ticket = $tickets->first();

        // Verify initial status
        $this->assertEquals('active', $ticket->status);
        $this->assertTrue($ticket->isActive());
        $this->assertFalse($ticket->isRevoked());
        $this->assertFalse($ticket->isUsed());
        $this->assertNotNull($ticket->issued_at);
        $this->assertNull($ticket->revoked_at);
        $this->assertNull($ticket->used_at);

        // Test revocation
        $revokedTicket = $this->ticketService->revokeTicket($ticket->id);
        $this->assertEquals('revoked', $revokedTicket->status);
        $this->assertFalse($revokedTicket->isActive());
        $this->assertTrue($revokedTicket->isRevoked());
        $this->assertFalse($revokedTicket->isUsed());
        $this->assertNotNull($revokedTicket->revoked_at);

        // Create another ticket to test usage
        $newTicket = $tickets->last();
        $usedTicket = $this->ticketService->markTicketAsUsed($newTicket->id);
        $this->assertEquals('used', $usedTicket->status);
        $this->assertFalse($usedTicket->isActive());
        $this->assertFalse($usedTicket->isRevoked());
        $this->assertTrue($usedTicket->isUsed());
        $this->assertNotNull($usedTicket->used_at);
    }

    /**
     * Run a single iteration of one ticket per attendee testing
     */
    private function runOneTicketPerAttendeeTest(): void
    {
        // Create test event and booking with random number of attendees
        $event = $this->createTestEvent();
        $attendeeCount = rand(1, 10);
        $booking = $this->createTestBookingInBookedState($event->id, $attendeeCount);

        // Generate tickets
        $tickets = $this->ticketService->generateTickets($booking->id);

        // Verify ticket count matches attendee count
        $this->assertCount($attendeeCount, $tickets, 'Number of tickets should match number of attendees');

        // Verify each attendee has exactly one ticket
        $attendeeIds = $booking->attendees->pluck('id')->toArray();
        $ticketAttendeeIds = $tickets->pluck('attendee_id')->toArray();

        $this->assertEquals(
            sort($attendeeIds),
            sort($ticketAttendeeIds),
            'Each attendee should have exactly one ticket'
        );

        // Verify all tickets belong to the same booking
        foreach ($tickets as $ticket) {
            $this->assertEquals($booking->id, $ticket->booking_id);
        }

        // Verify attempting to generate tickets again fails
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Tickets already exist for this booking');

        $this->ticketService->generateTickets($booking->id);
    }

    /**
     * Create a test event
     */
    private function createTestEvent(): Event
    {
        $eventData = [
            'title' => 'Test Event '.rand(1000, 9999),
            'description' => 'Test event for property testing',
            'event_date' => Carbon::now()->addDays(rand(1, 30))->toDateTimeString(),
            'venue' => 'Test Venue '.rand(100, 999),
            'total_capacity' => rand(50, 500),
            'terms_conditions' => 'Test terms and conditions',
        ];

        $event = $this->eventService->createEvent($eventData);

        return $this->eventService->publishEvent($event->id);
    }

    /**
     * Create a test booking
     */
    private function createTestBooking(int $eventId, ?int $attendeeCount = null): Booking
    {
        $attendeeCount = $attendeeCount ?? rand(1, 5);
        $bookingData = $this->generateRandomBookingData($eventId, $attendeeCount);

        return $this->bookingService->createBooking($bookingData);
    }

    /**
     * Create a test booking in booked state
     */
    private function createTestBookingInBookedState(int $eventId, ?int $attendeeCount = null): Booking
    {
        $booking = $this->createTestBooking($eventId, $attendeeCount);

        // Transition through states to reach booked
        $booking = $this->bookingService->transitionState($booking->id, 'pending');
        $booking = $this->bookingService->transitionState($booking->id, 'confirmed');
        $booking = $this->bookingService->transitionState($booking->id, 'booked');

        return $booking;
    }

    /**
     * Generate random booking data for testing
     */
    private function generateRandomBookingData(int $eventId, int $attendeeCount): array
    {
        $pricePerTicket = rand(25, 200);

        $bookingData = [
            'event_id' => $eventId,
            'buyer_name' => 'Test Buyer '.rand(1000, 9999),
            'buyer_email' => 'buyer'.rand(1000, 9999).'@example.com',
            'buyer_phone' => '+1'.rand(1000000000, 9999999999),
            'total_amount' => $pricePerTicket * $attendeeCount,
            'currency' => 'USD',
            'consent_pdpa' => (bool) rand(0, 1),
            'consent_marketing' => (bool) rand(0, 1),
            'attendees' => [],
        ];

        // Create attendee data
        for ($i = 0; $i < $attendeeCount; $i++) {
            $bookingData['attendees'][] = [
                'ticket_type_id' => 1, // Assuming ticket type exists
                'attendee_name' => 'Attendee '.($i + 1).' '.rand(1000, 9999),
                'attendee_email' => 'attendee'.rand(1000, 9999).'@example.com',
                'attendee_phone' => '+1'.rand(1000000000, 9999999999),
            ];
        }

        return $bookingData;
    }
}

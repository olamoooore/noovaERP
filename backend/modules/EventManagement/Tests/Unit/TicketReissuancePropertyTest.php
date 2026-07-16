<?php

namespace Modules\EventManagement\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
 * Property Test for Ticket Reissuance Process
 *
 * **Feature: erp-event-management, Property 5: Ticket Reissuance Process**
 * **Validates: Requirements 3.4**
 *
 * Property: For any ticket reissuance request, the system should revoke the old ticket
 * and create a new one with a different unique code while maintaining audit trails.
 */
class TicketReissuancePropertyTest extends TestCase
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
     * Property Test: Ticket reissuance revokes old and creates new ticket
     * Tests that reissuance properly revokes the old ticket and creates a new one
     *
     * @test
     */
    public function property_ticket_reissuance_revokes_old_and_creates_new_ticket()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runTicketReissuanceTest();
        }
    }

    /**
     * Property Test: Reissued tickets have different unique codes
     * Tests that new tickets always have different codes from the original
     *
     * @test
     */
    public function property_reissued_tickets_have_different_unique_codes()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runUniqueCodeReissuanceTest();
        }
    }

    /**
     * Property Test: Only active tickets can be reissued
     * Tests that revoked or used tickets cannot be reissued
     *
     * @test
     */
    public function property_only_active_tickets_can_be_reissued()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runActiveTicketReissuanceTest();
        }
    }

    /**
     * Property Test: Reissued tickets maintain same booking and attendee
     * Tests that reissued tickets preserve the original booking and attendee relationships
     *
     * @test
     */
    public function property_reissued_tickets_maintain_same_booking_and_attendee()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runBookingAttendeePreservationTest();
        }
    }

    /**
     * Property Test: Reissued tickets have valid QR payloads
     * Tests that reissued tickets have properly formatted QR payloads with correct data
     *
     * @test
     */
    public function property_reissued_tickets_have_valid_qr_payloads()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runQRPayloadValidationTest();
        }
    }

    /**
     * Property Test: Reissuance maintains audit trail
     * Tests that ticket reissuance creates proper audit logs
     *
     * @test
     */
    public function property_reissuance_maintains_audit_trail()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runAuditTrailTest();
        }
    }

    /**
     * Run a single iteration of ticket reissuance testing
     */
    private function runTicketReissuanceTest(): void
    {
        // Create test event, booking, and tickets
        $event = $this->createTestEvent();
        $booking = $this->createTestBookingInBookedState($event->id);
        $originalTickets = $this->ticketService->generateTickets($booking->id);
        $originalTicket = $originalTickets->first();

        // Store original ticket data
        $originalTicketCode = $originalTicket->ticket_code;
        $originalStatus = $originalTicket->status;

        // Verify original ticket is active
        $this->assertEquals('active', $originalStatus);
        $this->assertTrue($originalTicket->isActive());
        $this->assertNull($originalTicket->revoked_at);

        // Reissue the ticket
        $newTicket = $this->ticketService->reissueTicket($originalTicket->id);

        // Verify new ticket was created
        $this->assertInstanceOf(Ticket::class, $newTicket);
        $this->assertNotEquals($originalTicket->id, $newTicket->id);
        $this->assertNotEquals($originalTicketCode, $newTicket->ticket_code);

        // Verify new ticket is active
        $this->assertEquals('active', $newTicket->status);
        $this->assertTrue($newTicket->isActive());
        $this->assertNotNull($newTicket->issued_at);
        $this->assertNull($newTicket->revoked_at);
        $this->assertNull($newTicket->used_at);

        // Verify original ticket was revoked
        $originalTicket->refresh();
        $this->assertEquals('revoked', $originalTicket->status);
        $this->assertTrue($originalTicket->isRevoked());
        $this->assertNotNull($originalTicket->revoked_at);

        // Verify both tickets exist in database
        $this->assertDatabaseHas('tickets', [
            'id' => $originalTicket->id,
            'ticket_code' => $originalTicketCode,
            'status' => 'revoked',
        ]);

        $this->assertDatabaseHas('tickets', [
            'id' => $newTicket->id,
            'ticket_code' => $newTicket->ticket_code,
            'status' => 'active',
        ]);
    }

    /**
     * Run a single iteration of unique code reissuance testing
     */
    private function runUniqueCodeReissuanceTest(): void
    {
        // Create test event, booking, and tickets
        $event = $this->createTestEvent();
        $booking = $this->createTestBookingInBookedState($event->id);
        $originalTickets = $this->ticketService->generateTickets($booking->id);

        $reissuedCodes = [];
        $originalCodes = [];

        // Reissue multiple tickets and collect codes
        foreach ($originalTickets as $originalTicket) {
            $originalCodes[] = $originalTicket->ticket_code;

            $newTicket = $this->ticketService->reissueTicket($originalTicket->id);
            $reissuedCodes[] = $newTicket->ticket_code;

            // Verify new code is different from original
            $this->assertNotEquals($originalTicket->ticket_code, $newTicket->ticket_code);

            // Verify new code follows format
            $this->assertMatchesRegularExpression('/^TK[A-Z0-9]{8}$/', $newTicket->ticket_code);
        }

        // Verify all reissued codes are unique
        $this->assertEquals(count($reissuedCodes), count(array_unique($reissuedCodes)));

        // Verify no reissued code matches any original code
        $this->assertEmpty(array_intersect($originalCodes, $reissuedCodes));
    }

    /**
     * Run a single iteration of active ticket reissuance testing
     */
    private function runActiveTicketReissuanceTest(): void
    {
        // Create test event, booking, and tickets
        $event = $this->createTestEvent();
        $booking = $this->createTestBookingInBookedState($event->id);
        $tickets = $this->ticketService->generateTickets($booking->id);

        // Test reissuance of active ticket (should work)
        $activeTicket = $tickets->first();
        $this->assertEquals('active', $activeTicket->status);

        $reissuedTicket = $this->ticketService->reissueTicket($activeTicket->id);
        $this->assertInstanceOf(Ticket::class, $reissuedTicket);

        // Test reissuance of revoked ticket (should fail)
        $revokedTicket = $tickets->last();
        $this->ticketService->revokeTicket($revokedTicket->id);
        $revokedTicket->refresh();
        $this->assertEquals('revoked', $revokedTicket->status);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot reissue an already revoked ticket');

        $this->ticketService->reissueTicket($revokedTicket->id);
    }

    /**
     * Run a single iteration of booking/attendee preservation testing
     */
    private function runBookingAttendeePreservationTest(): void
    {
        // Create test event, booking, and tickets
        $event = $this->createTestEvent();
        $booking = $this->createTestBookingInBookedState($event->id);
        $originalTickets = $this->ticketService->generateTickets($booking->id);
        $originalTicket = $originalTickets->first();

        // Store original relationships
        $originalBookingId = $originalTicket->booking_id;
        $originalAttendeeId = $originalTicket->attendee_id;

        // Reissue the ticket
        $newTicket = $this->ticketService->reissueTicket($originalTicket->id);

        // Verify relationships are preserved
        $this->assertEquals($originalBookingId, $newTicket->booking_id);
        $this->assertEquals($originalAttendeeId, $newTicket->attendee_id);

        // Verify the ticket can access the same booking and attendee
        $this->assertEquals($booking->id, $newTicket->booking->id);
        $this->assertEquals($booking->booking_reference, $newTicket->booking->booking_reference);
        $this->assertEquals($originalTicket->attendee->attendee_name, $newTicket->attendee->attendee_name);
    }

    /**
     * Run a single iteration of QR payload validation testing
     */
    private function runQRPayloadValidationTest(): void
    {
        // Create test event, booking, and tickets
        $event = $this->createTestEvent();
        $booking = $this->createTestBookingInBookedState($event->id);
        $originalTickets = $this->ticketService->generateTickets($booking->id);
        $originalTicket = $originalTickets->first();

        // Reissue the ticket
        $newTicket = $this->ticketService->reissueTicket($originalTicket->id);

        // Verify QR payload exists and is valid JSON
        $this->assertNotEmpty($newTicket->qr_payload);
        $payload = json_decode($newTicket->qr_payload, true);
        $this->assertIsArray($payload);

        // Verify required fields in payload
        $requiredFields = [
            'version', 'type', 'ticket_code', 'booking_reference',
            'event_id', 'attendee_id', 'attendee_name', 'event_title',
            'event_date', 'venue', 'issued_at', 'checksum',
        ];

        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $payload);
            $this->assertNotEmpty($payload[$field]);
        }

        // Verify payload data matches new ticket data
        $this->assertEquals($newTicket->ticket_code, $payload['ticket_code']);
        $this->assertEquals($booking->booking_reference, $payload['booking_reference']);
        $this->assertEquals($event->id, $payload['event_id']);
        $this->assertEquals($newTicket->attendee_id, $payload['attendee_id']);

        // Verify payload is different from original ticket payload
        $originalPayload = json_decode($originalTicket->qr_payload, true);
        $this->assertNotEquals($originalPayload['ticket_code'], $payload['ticket_code']);
        $this->assertNotEquals($originalPayload['checksum'], $payload['checksum']);
        $this->assertNotEquals($originalPayload['issued_at'], $payload['issued_at']);
    }

    /**
     * Run a single iteration of audit trail testing
     */
    private function runAuditTrailTest(): void
    {
        // Create test event, booking, and tickets
        $event = $this->createTestEvent();
        $booking = $this->createTestBookingInBookedState($event->id);
        $originalTickets = $this->ticketService->generateTickets($booking->id);
        $originalTicket = $originalTickets->first();

        // Store original data for audit verification
        $originalTicketCode = $originalTicket->ticket_code;
        $bookingReference = $booking->booking_reference;

        // Mock audit service to capture log calls
        $this->auditService->expects($this->once())
            ->method('logActivity')
            ->with(
                'ticket_reissued',
                'ticket',
                $this->anything(), // New ticket ID
                $this->callback(function ($data) use ($originalTicketCode, $bookingReference) {
                    return isset($data['old_ticket_code']) &&
                           isset($data['new_ticket_code']) &&
                           isset($data['booking_reference']) &&
                           isset($data['reason']) &&
                           $data['old_ticket_code'] === $originalTicketCode &&
                           $data['booking_reference'] === $bookingReference &&
                           $data['reason'] === 'admin_reissue' &&
                           $data['new_ticket_code'] !== $originalTicketCode;
                })
            );

        // Reissue the ticket
        $newTicket = $this->ticketService->reissueTicket($originalTicket->id);

        // Verify audit log would contain correct information
        $this->assertInstanceOf(Ticket::class, $newTicket);
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
     * Create a test booking in booked state
     */
    private function createTestBookingInBookedState(int $eventId, ?int $attendeeCount = null): Booking
    {
        $attendeeCount = $attendeeCount ?? rand(1, 5);
        $bookingData = $this->generateRandomBookingData($eventId, $attendeeCount);

        $booking = $this->bookingService->createBooking($bookingData);

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

<?php

namespace Modules\EventManagement\Tests\Integration;

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
 * Integration Test for Complete Booking Workflow
 *
 * Tests the complete booking workflow from event creation to ticket issuance
 * Validates: Requirements 9.1, 5.1, 8.5
 */
class CompleteBookingWorkflowTest extends TestCase
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
     * Test complete booking workflow from event creation to ticket issuance
     *
     * @test
     */
    public function test_complete_booking_workflow_from_event_creation_to_ticket_issuance()
    {
        // Step 1: Create an event
        $eventData = [
            'title' => 'KPOP Merchandise Event 2024',
            'description' => 'Exclusive KPOP merchandise showcase and sale',
            'event_date' => Carbon::now()->addDays(30)->toDateTimeString(),
            'venue' => 'Convention Center Hall A',
            'total_capacity' => 500,
            'terms_conditions' => 'All sales final. No refunds after event date.',
            'ticket_types' => [
                [
                    'name' => 'General Admission',
                    'price' => 50.00,
                    'capacity' => 400,
                    'description' => 'General admission to the event',
                ],
                [
                    'name' => 'VIP Access',
                    'price' => 150.00,
                    'capacity' => 100,
                    'description' => 'VIP access with early entry and exclusive merchandise',
                ],
            ],
        ];

        $event = $this->eventService->createEvent($eventData);

        // Verify event creation
        $this->assertInstanceOf(Event::class, $event);
        $this->assertEquals('draft', $event->status);
        $this->assertEquals(500, $event->total_capacity);
        $this->assertEquals(500, $event->available_capacity);
        $this->assertCount(2, $event->ticketTypes);

        // Step 2: Publish the event
        $publishedEvent = $this->eventService->publishEvent($event->id);

        // Verify event publishing
        $this->assertEquals('published', $publishedEvent->status);

        // Verify event appears in public listings
        $publicEvents = $this->eventService->getPublicEvents();
        $this->assertTrue($publicEvents->contains('id', $event->id));

        // Step 3: Create a booking
        $bookingData = [
            'event_id' => $event->id,
            'buyer_name' => 'John Doe',
            'buyer_email' => 'john.doe@example.com',
            'buyer_phone' => '+1234567890',
            'total_amount' => 200.00, // 1 General + 1 VIP
            'currency' => 'USD',
            'consent_pdpa' => true,
            'consent_marketing' => false,
            'attendees' => [
                [
                    'ticket_type_id' => $event->ticketTypes[0]->id, // General Admission
                    'attendee_name' => 'John Doe',
                    'attendee_email' => 'john.doe@example.com',
                    'attendee_phone' => '+1234567890',
                ],
                [
                    'ticket_type_id' => $event->ticketTypes[1]->id, // VIP Access
                    'attendee_name' => 'Jane Doe',
                    'attendee_email' => 'jane.doe@example.com',
                    'attendee_phone' => '+1234567891',
                ],
            ],
        ];

        $booking = $this->bookingService->createBooking($bookingData);

        // Verify booking creation
        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertEquals('new', $booking->state);
        $this->assertEquals(200.00, $booking->total_amount);
        $this->assertCount(2, $booking->attendees);
        $this->assertMatchesRegularExpression('/^BK[A-Z0-9]{8}$/', $booking->booking_reference);

        // Verify capacity was reserved
        $event->refresh();
        $this->assertEquals(498, $event->available_capacity); // 500 - 2 tickets

        // Step 4: Initiate payment (transition to pending)
        $pendingBooking = $this->bookingService->transitionState($booking->id, 'pending');

        // Verify state transition
        $this->assertEquals('pending', $pendingBooking->state);

        // Step 5: Process payment confirmation
        $paymentData = [
            'payment_reference' => 'PAY_123456789',
            'status' => 'confirmed',
            'amount' => 200.00,
            'currency' => 'USD',
        ];

        $confirmedBooking = $this->bookingService->processPaymentCallback(
            $booking->booking_reference,
            $paymentData
        );

        // Verify payment processing
        $this->assertEquals('confirmed', $confirmedBooking->state);
        $this->assertEquals('PAY_123456789', $confirmedBooking->payment_reference);

        // Step 6: Issue tickets (transition to booked)
        $bookedBooking = $this->bookingService->transitionState($confirmedBooking->id, 'booked');

        // Verify final state
        $this->assertEquals('booked', $bookedBooking->state);

        // Step 7: Generate tickets
        $tickets = $this->ticketService->generateTickets($bookedBooking->id);

        // Verify ticket generation
        $this->assertCount(2, $tickets);

        foreach ($tickets as $ticket) {
            $this->assertInstanceOf(Ticket::class, $ticket);
            $this->assertEquals('active', $ticket->status);
            $this->assertMatchesRegularExpression('/^TK[A-Z0-9]{8}$/', $ticket->ticket_code);
            $this->assertNotEmpty($ticket->qr_payload);

            // Verify QR payload contains required information
            $payload = json_decode($ticket->qr_payload, true);
            $this->assertIsArray($payload);
            $this->assertEquals($ticket->ticket_code, $payload['ticket_code']);
            $this->assertEquals($booking->booking_reference, $payload['booking_reference']);
            $this->assertEquals($event->id, $payload['event_id']);
            $this->assertEquals($event->title, $payload['event_title']);
        }

        // Step 8: Verify audit trail (mock verification)
        $this->auditService->expects($this->atLeastOnce())
            ->method('logActivity')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything());

        // Step 9: Verify database consistency
        $this->assertDatabaseHas('events', [
            'id' => $event->id,
            'status' => 'published',
            'available_capacity' => 498,
        ]);

        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'state' => 'booked',
            'payment_reference' => 'PAY_123456789',
        ]);

        $this->assertDatabaseCount('tickets', 2);
        $this->assertDatabaseCount('booking_attendees', 2);

        // Verify all ticket codes are unique
        $ticketCodes = DB::table('tickets')->pluck('ticket_code')->toArray();
        $this->assertEquals(count($ticketCodes), count(array_unique($ticketCodes)));
    }

    /**
     * Test booking workflow with capacity constraints
     *
     * @test
     */
    public function test_booking_workflow_respects_capacity_constraints()
    {
        // Create event with limited capacity
        $eventData = [
            'title' => 'Limited Capacity Event',
            'description' => 'Test event with limited capacity',
            'event_date' => Carbon::now()->addDays(15)->toDateTimeString(),
            'venue' => 'Small Venue',
            'total_capacity' => 5,
            'terms_conditions' => 'Limited capacity event',
        ];

        $event = $this->eventService->createEvent($eventData);
        $publishedEvent = $this->eventService->publishEvent($event->id);

        // Create booking that uses all capacity
        $bookingData = [
            'event_id' => $event->id,
            'buyer_name' => 'Test Buyer',
            'buyer_email' => 'test@example.com',
            'buyer_phone' => '+1234567890',
            'total_amount' => 250.00,
            'currency' => 'USD',
            'consent_pdpa' => true,
            'consent_marketing' => false,
            'attendees' => [],
        ];

        // Add 5 attendees to fill capacity
        for ($i = 1; $i <= 5; $i++) {
            $bookingData['attendees'][] = [
                'ticket_type_id' => 1,
                'attendee_name' => "Attendee {$i}",
                'attendee_email' => "attendee{$i}@example.com",
                'attendee_phone' => "+123456789{$i}",
            ];
        }

        $booking = $this->bookingService->createBooking($bookingData);

        // Verify capacity is fully reserved
        $event->refresh();
        $this->assertEquals(0, $event->available_capacity);
        $this->assertEquals('sold_out', $event->status);

        // Attempt to create another booking should fail
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient capacity for requested tickets');

        $newBookingData = [
            'event_id' => $event->id,
            'buyer_name' => 'Another Buyer',
            'buyer_email' => 'another@example.com',
            'buyer_phone' => '+1987654321',
            'total_amount' => 50.00,
            'currency' => 'USD',
            'consent_pdpa' => true,
            'consent_marketing' => false,
            'attendees' => [
                [
                    'ticket_type_id' => 1,
                    'attendee_name' => 'Extra Attendee',
                    'attendee_email' => 'extra@example.com',
                    'attendee_phone' => '+1987654321',
                ],
            ],
        ];

        $this->bookingService->createBooking($newBookingData);
    }

    /**
     * Test booking cancellation workflow
     *
     * @test
     */
    public function test_booking_cancellation_workflow()
    {
        // Create event and booking
        $event = $this->createTestEvent();
        $booking = $this->createTestBooking($event->id);

        // Transition to confirmed state
        $booking = $this->bookingService->transitionState($booking->id, 'pending');
        $booking = $this->bookingService->transitionState($booking->id, 'confirmed');

        // Verify initial capacity
        $event->refresh();
        $initialAvailableCapacity = $event->available_capacity;

        // Cancel the booking
        $cancelledBooking = $this->bookingService->cancelBooking($booking->id, 'Customer request');

        // Verify cancellation
        $this->assertEquals('cancelled', $cancelledBooking->state);

        // Verify capacity was restored
        $event->refresh();
        $this->assertGreaterThan($initialAvailableCapacity, $event->available_capacity);

        // Verify cancelled booking cannot transition to other states
        $this->expectException(\InvalidArgumentException::class);
        $this->bookingService->transitionState($cancelledBooking->id, 'booked');
    }

    /**
     * Create a test event
     */
    private function createTestEvent(): Event
    {
        $eventData = [
            'title' => 'Test Event '.rand(1000, 9999),
            'description' => 'Test event for integration testing',
            'event_date' => Carbon::now()->addDays(rand(1, 30))->toDateTimeString(),
            'venue' => 'Test Venue '.rand(100, 999),
            'total_capacity' => 100,
            'terms_conditions' => 'Test terms and conditions',
        ];

        $event = $this->eventService->createEvent($eventData);

        return $this->eventService->publishEvent($event->id);
    }

    /**
     * Create a test booking
     */
    private function createTestBooking(int $eventId): Booking
    {
        $bookingData = [
            'event_id' => $eventId,
            'buyer_name' => 'Test Buyer',
            'buyer_email' => 'test@example.com',
            'buyer_phone' => '+1234567890',
            'total_amount' => 100.00,
            'currency' => 'USD',
            'consent_pdpa' => true,
            'consent_marketing' => false,
            'attendees' => [
                [
                    'ticket_type_id' => 1,
                    'attendee_name' => 'Test Attendee',
                    'attendee_email' => 'attendee@example.com',
                    'attendee_phone' => '+1234567890',
                ],
            ],
        ];

        return $this->bookingService->createBooking($bookingData);
    }
}

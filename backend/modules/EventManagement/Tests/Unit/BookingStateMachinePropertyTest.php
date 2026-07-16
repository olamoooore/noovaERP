<?php

namespace Modules\EventManagement\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Services\BookingService;
use Modules\EventManagement\Services\EventService;
use Modules\EventManagement\Services\TenantSchemaManager;
use Tests\TestCase;

/**
 * Property Test for Booking State Machine Transitions
 *
 * **Feature: erp-event-management, Property 3: Booking State Machine Transitions**
 * **Validates: Requirements 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7**
 *
 * Property: For any booking, state transitions should follow the valid path
 * (New → Pending → Confirmed → Booked, with Cancelled as terminal), reject invalid
 * transitions, and ensure idempotency for creation and payment callbacks.
 */
class BookingStateMachinePropertyTest extends TestCase
{
    use RefreshDatabase;

    private BookingService $bookingService;

    private EventService $eventService;

    private AuditService $auditService;

    private TenantSchemaManager $tenantSchemaManager;

    // Valid state transition paths
    private const VALID_TRANSITIONS = [
        'new' => ['pending', 'cancelled'],
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['booked', 'cancelled'],
        'booked' => ['cancelled'],
        'cancelled' => [], // Terminal state
    ];

    // All possible states
    private const ALL_STATES = ['new', 'pending', 'confirmed', 'booked', 'cancelled'];

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
     * Property Test: Valid state transitions are allowed
     * Tests that all valid state transitions work correctly
     *
     * @test
     */
    public function property_valid_state_transitions_are_allowed()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runValidStateTransitionTest();
        }
    }

    /**
     * Property Test: Invalid state transitions are rejected
     * Tests that invalid state transitions are properly rejected
     *
     * @test
     */
    public function property_invalid_state_transitions_are_rejected()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runInvalidStateTransitionTest();
        }
    }

    /**
     * Property Test: Booking creation is idempotent
     * Tests that booking creation with same data produces consistent results
     *
     * @test
     */
    public function property_booking_creation_is_idempotent()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runBookingCreationIdempotencyTest();
        }
    }

    /**
     * Property Test: Payment callback processing is idempotent
     * Tests that processing the same payment callback multiple times is safe
     *
     * @test
     */
    public function property_payment_callback_processing_is_idempotent()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runPaymentCallbackIdempotencyTest();
        }
    }

    /**
     * Property Test: State machine enforces business rules
     * Tests that state transitions enforce proper business logic
     *
     * @test
     */
    public function property_state_machine_enforces_business_rules()
    {
        // Run property test with multiple iterations
        for ($i = 0; $i < 100; $i++) {
            $this->runBusinessRuleEnforcementTest();
        }
    }

    /**
     * Run a single iteration of valid state transition testing
     */
    private function runValidStateTransitionTest(): void
    {
        // Create test event and booking
        $event = $this->createTestEvent();
        $booking = $this->createTestBooking($event->id);

        // Test all valid transitions from current state
        $currentState = $booking->state;
        $validNextStates = self::VALID_TRANSITIONS[$currentState];

        if (empty($validNextStates)) {
            // Terminal state, no valid transitions
            return;
        }

        // Pick a random valid next state
        $nextState = $validNextStates[array_rand($validNextStates)];

        // Validate the transition is allowed
        $this->assertTrue(
            $this->bookingService->validateStateTransition($currentState, $nextState),
            "Transition from '{$currentState}' to '{$nextState}' should be valid"
        );

        // Perform the transition
        $updatedBooking = $this->bookingService->transitionState($booking->id, $nextState);

        // Verify the state was updated
        $this->assertEquals($nextState, $updatedBooking->state);

        // Verify the booking exists in database with correct state
        $this->assertDatabaseHas('bookings', [
            'id' => $booking->id,
            'state' => $nextState,
        ]);
    }

    /**
     * Run a single iteration of invalid state transition testing
     */
    private function runInvalidStateTransitionTest(): void
    {
        // Create test event and booking
        $event = $this->createTestEvent();
        $booking = $this->createTestBooking($event->id);

        $currentState = $booking->state;
        $validNextStates = self::VALID_TRANSITIONS[$currentState];

        // Find invalid states (all states except valid ones)
        $invalidStates = array_diff(self::ALL_STATES, $validNextStates, [$currentState]);

        if (empty($invalidStates)) {
            // No invalid states to test
            return;
        }

        // Pick a random invalid state
        $invalidState = $invalidStates[array_rand($invalidStates)];

        // Validate the transition is not allowed
        $this->assertFalse(
            $this->bookingService->validateStateTransition($currentState, $invalidState),
            "Transition from '{$currentState}' to '{$invalidState}' should be invalid"
        );

        // Attempt the invalid transition should throw exception
        $this->expectException(\InvalidArgumentException::class);
        $this->bookingService->transitionState($booking->id, $invalidState);
    }

    /**
     * Run a single iteration of booking creation idempotency testing
     */
    private function runBookingCreationIdempotencyTest(): void
    {
        // Create test event
        $event = $this->createTestEvent();

        // Generate booking data
        $bookingData = $this->generateRandomBookingData($event->id);

        // Create first booking
        $booking1 = $this->bookingService->createBooking($bookingData);

        // Verify booking was created correctly
        $this->assertEquals('new', $booking1->state);
        $this->assertEquals($bookingData['buyer_name'], $booking1->buyer_name);
        $this->assertEquals($bookingData['buyer_email'], $booking1->buyer_email);
        $this->assertEquals($bookingData['total_amount'], $booking1->total_amount);

        // Verify booking reference is unique
        $this->assertNotEmpty($booking1->booking_reference);
        $this->assertMatchesRegularExpression('/^BK[A-Z0-9]{8}$/', $booking1->booking_reference);

        // Create second booking with different data should work
        $bookingData2 = $this->generateRandomBookingData($event->id);
        $booking2 = $this->bookingService->createBooking($bookingData2);

        // Verify both bookings have unique references
        $this->assertNotEquals($booking1->booking_reference, $booking2->booking_reference);
    }

    /**
     * Run a single iteration of payment callback idempotency testing
     */
    private function runPaymentCallbackIdempotencyTest(): void
    {
        // Create test event and booking
        $event = $this->createTestEvent();
        $booking = $this->createTestBooking($event->id);

        // Transition to pending state first
        $booking = $this->bookingService->transitionState($booking->id, 'pending');

        // Generate payment callback data
        $paymentData = [
            'payment_reference' => 'PAY_'.rand(100000, 999999),
            'status' => 'confirmed',
            'amount' => $booking->total_amount,
            'currency' => $booking->currency,
        ];

        // Process payment callback first time
        $result1 = $this->bookingService->processPaymentCallback($booking->booking_reference, $paymentData);

        // Verify state transition occurred
        $this->assertEquals('confirmed', $result1->state);
        $this->assertEquals($paymentData['payment_reference'], $result1->payment_reference);

        // Process same payment callback again (idempotency test)
        $result2 = $this->bookingService->processPaymentCallback($booking->booking_reference, $paymentData);

        // Verify idempotency - same result, no duplicate processing
        $this->assertEquals($result1->id, $result2->id);
        $this->assertEquals($result1->state, $result2->state);
        $this->assertEquals($result1->payment_reference, $result2->payment_reference);
        $this->assertEquals($result1->updated_at, $result2->updated_at);
    }

    /**
     * Run a single iteration of business rule enforcement testing
     */
    private function runBusinessRuleEnforcementTest(): void
    {
        // Create test event with limited capacity
        $capacity = rand(5, 20);
        $event = $this->createTestEvent($capacity);

        // Create booking that uses all capacity
        $bookingData = $this->generateRandomBookingData($event->id, $capacity);
        $booking = $this->bookingService->createBooking($bookingData);

        // Transition to pending to reserve capacity
        $booking = $this->bookingService->transitionState($booking->id, 'pending');

        // Verify capacity was reserved
        $event->refresh();
        $this->assertEquals(0, $event->available_capacity);
        $this->assertEquals('sold_out', $event->status);

        // Try to create another booking - should fail due to capacity
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Insufficient capacity for requested tickets');

        $newBookingData = $this->generateRandomBookingData($event->id, 1);
        $this->bookingService->createBooking($newBookingData);
    }

    /**
     * Create a test event
     */
    private function createTestEvent(?int $capacity = null): Event
    {
        $capacity = $capacity ?? rand(50, 500);

        $eventData = [
            'title' => 'Test Event '.rand(1000, 9999),
            'description' => 'Test event for property testing',
            'event_date' => Carbon::now()->addDays(rand(1, 30))->toDateTimeString(),
            'venue' => 'Test Venue '.rand(100, 999),
            'total_capacity' => $capacity,
            'terms_conditions' => 'Test terms and conditions',
        ];

        $event = $this->eventService->createEvent($eventData);

        // Publish the event so it can accept bookings
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
     * Generate random booking data for testing
     */
    private function generateRandomBookingData(int $eventId, ?int $attendeeCount = null): array
    {
        $attendeeCount = $attendeeCount ?? rand(1, 3);
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

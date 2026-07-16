<?php

namespace Modules\EventManagement\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\BookingAttendee;
use Modules\EventManagement\Models\Customer;
use Modules\EventManagement\Models\Event;

class BookingService
{
    protected TenantSchemaManager $tenantSchemaManager;

    protected AuditService $auditService;

    protected FanTierService $fanTierService;

    protected LoyaltyService $loyaltyService;

    protected FraudDetectionService $fraudDetectionService;

    protected AntiAbuseService $antiAbuseService;

    protected BookingFactsService $bookingFactsService;

    protected CapacityReservationService $capacityReservationService;

    protected OutboxService $outboxService;

    // Valid state transitions based on the state machine design
    private const VALID_TRANSITIONS = [
        'new' => ['pending', 'cancelled'],
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['booked', 'cancelled'],
        'booked' => ['cancelled'],
        'cancelled' => [], // Terminal state
    ];

    public function __construct(
        TenantSchemaManager $tenantSchemaManager,
        AuditService $auditService,
        FanTierService $fanTierService,
        LoyaltyService $loyaltyService,
        FraudDetectionService $fraudDetectionService,
        AntiAbuseService $antiAbuseService,
        BookingFactsService $bookingFactsService,
        CapacityReservationService $capacityReservationService,
        OutboxService $outboxService
    ) {
        $this->tenantSchemaManager = $tenantSchemaManager;
        $this->auditService = $auditService;
        $this->fanTierService = $fanTierService;
        $this->loyaltyService = $loyaltyService;
        $this->fraudDetectionService = $fraudDetectionService;
        $this->antiAbuseService = $antiAbuseService;
        $this->bookingFactsService = $bookingFactsService;
        $this->capacityReservationService = $capacityReservationService;
        $this->outboxService = $outboxService;
    }

    /**
     * Create a new booking with enhanced capacity reservation system
     * Requirements: 2.1 - Initialize booking in "New" state
     * Requirements: 1.4, 1.5 - Enforce capacity limits and prevent overbooking
     * Requirements: 13.1, 13.2, 13.3 - Enforce tier-based booking privileges
     * Requirements: 14.1, 14.2, 14.5 - Fraud detection and anti-abuse controls
     * Requirements: 19.1, 19.2, 19.3 - Enhanced capacity reservation system
     */
    public function createBooking(array $bookingData): Booking
    {
        return DB::transaction(function () use ($bookingData) {
            $tenantId = $this->resolveTenantId($bookingData['tenant_id'] ?? null);

            // Step 1: Fraud Detection and Anti-Abuse Checks
            $fraudAnalysis = $this->performFraudChecks($tenantId, $bookingData);

            if ($fraudAnalysis['blocked']) {
                throw new \InvalidArgumentException($fraudAnalysis['reason']);
            }

            // Step 2: Get event and validate it can accept bookings
            $event = Event::findOrFail($bookingData['event_id']);

            if (! $event->canAcceptBookings()) {
                throw new \InvalidArgumentException('Event cannot accept new bookings');
            }

            // Step 3: Check tier-based booking eligibility if customer_id is provided
            if (isset($bookingData['customer_id'])) {
                $tierCheck = $this->checkTierBookingEligibility(
                    $tenantId,
                    $bookingData['customer_id'],
                    $bookingData['event_id'],
                    $bookingData['attendees'] ?? []
                );

                if (! $tierCheck['eligible']) {
                    throw new \InvalidArgumentException($tierCheck['reason']);
                }
            }

            // Step 4: Calculate total tickets requested
            $totalTicketsRequested = 0;
            if (isset($bookingData['attendees'])) {
                $totalTicketsRequested = count($bookingData['attendees']);
            }

            // Step 5: Create capacity reservation using enhanced system
            $reservationResult = $this->capacityReservationService->createReservation(
                $bookingData['event_id'],
                $totalTicketsRequested,
                [
                    'customer_session_id' => $bookingData['session_id'] ?? null,
                    'ip_address' => $bookingData['ip_address'] ?? null,
                    'reservation_minutes' => 15, // 15-minute reservation window
                    'metadata' => [
                        'fraud_risk_score' => $fraudAnalysis['risk_score'],
                        'requires_manual_review' => $fraudAnalysis['requires_manual_review'],
                    ],
                ]
            );

            // Step 6: Generate unique booking reference
            $bookingReference = $this->generateBookingReference();

            // Step 7: Apply tier-based discounts if applicable
            $finalAmount = $bookingData['total_amount'];
            $appliedDiscounts = [];

            if (isset($bookingData['customer_id'])) {
                $discountResult = $this->applyTierDiscounts(
                    $tenantId,
                    $bookingData['customer_id'],
                    $finalAmount
                );
                $finalAmount = $discountResult['final_amount'];
                $appliedDiscounts = $discountResult['applied_discounts'];
            }

            // Step 8: Create the booking with reservation token
            $booking = Booking::create([
                'booking_reference' => $bookingReference,
                'event_id' => $bookingData['event_id'],
                'customer_id' => $bookingData['customer_id'] ?? null,
                'buyer_name' => $bookingData['buyer_name'],
                'buyer_email' => $bookingData['buyer_email'],
                'buyer_phone' => $bookingData['buyer_phone'],
                'total_amount' => $finalAmount,
                'original_amount' => $bookingData['total_amount'],
                'currency' => $bookingData['currency'] ?? 'USD',
                'state' => 'new', // Always start in 'new' state
                'consent_pdpa' => $bookingData['consent_pdpa'] ?? false,
                'consent_marketing' => $bookingData['consent_marketing'] ?? false,
                'tier_benefits_applied' => $appliedDiscounts,
                'fraud_risk_score' => $fraudAnalysis['risk_score'],
                'requires_manual_review' => $fraudAnalysis['requires_manual_review'],
                'capacity_reservation_token' => $reservationResult['reservation_token'],
                'capacity_reservation_expires_at' => $reservationResult['expires_at'],
            ]);

            // Step 9: Update reservation with booking ID
            DB::table('capacity_reservations')
                ->where('reservation_token', $reservationResult['reservation_token'])
                ->update(['booking_id' => $booking->id]);

            // Step 10: Handle fraud response actions
            if ($fraudAnalysis['requires_manual_review']) {
                $this->antiAbuseService->queueForManualReview(
                    $tenantId,
                    $booking->id,
                    $fraudAnalysis['risk_factors'],
                    $fraudAnalysis['risk_score']
                );
            }

            // Step 11: Create attendees if provided
            if (isset($bookingData['attendees']) && is_array($bookingData['attendees'])) {
                foreach ($bookingData['attendees'] as $attendeeData) {
                    BookingAttendee::create([
                        'booking_id' => $booking->id,
                        'ticket_type_id' => $attendeeData['ticket_type_id'],
                        'attendee_name' => $attendeeData['attendee_name'],
                        'attendee_email' => $attendeeData['attendee_email'] ?? null,
                        'attendee_phone' => $attendeeData['attendee_phone'] ?? null,
                        'encrypted_id_info' => isset($attendeeData['id_info']) ? encrypt($attendeeData['id_info']) : null,
                    ]);
                }
            }

            // Step 12: Capture immutable booking facts
            $this->bookingFactsService->captureBookingFacts($booking);

            // Step 13: Create outbox event for side effects
            $this->outboxService->createEvent(
                'booking',
                (string) $booking->id,
                'booking.created',
                [
                    'booking_id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'event_id' => $booking->event_id,
                    'customer_id' => $booking->customer_id,
                    'total_amount' => $finalAmount,
                    'attendee_count' => $totalTicketsRequested,
                    'tier_benefits_applied' => $appliedDiscounts,
                    'fraud_risk_score' => $fraudAnalysis['risk_score'],
                    'requires_manual_review' => $fraudAnalysis['requires_manual_review'],
                ]
            );

            // Step 14: Log the booking creation
            $this->auditService->logActivity(
                'booking_created',
                'booking',
                $booking->id,
                [
                    'booking_reference' => $bookingReference,
                    'event_id' => $bookingData['event_id'],
                    'customer_id' => $bookingData['customer_id'] ?? null,
                    'total_amount' => $finalAmount,
                    'original_amount' => $bookingData['total_amount'],
                    'attendee_count' => $totalTicketsRequested,
                    'tier_benefits_applied' => $appliedDiscounts,
                    'fraud_risk_score' => $fraudAnalysis['risk_score'],
                    'requires_manual_review' => $fraudAnalysis['requires_manual_review'],
                    'capacity_reservation_token' => $reservationResult['reservation_token'],
                    'available_capacity_after' => $reservationResult['available_capacity_after'],
                ]
            );

            return $booking->load('attendees');
        });
    }

    /**
     * Transition booking state
     * Requirements: 2.2, 2.3, 2.4, 2.5 - Handle state transitions
     */
    public function transitionState(int $bookingId, string $toState): Booking
    {
        return DB::transaction(function () use ($bookingId, $toState) {
            $booking = Booking::findOrFail($bookingId);
            $fromState = $booking->state;

            // Validate state transition
            if (! $this->validateStateTransition($fromState, $toState)) {
                throw new \InvalidArgumentException("Invalid state transition from '{$fromState}' to '{$toState}'");
            }

            // Perform state-specific logic
            $this->performStateTransitionLogic($booking, $fromState, $toState);

            // Update the booking state
            $booking->update(['state' => $toState]);

            // Log the state transition
            $this->auditService->logActivity(
                'booking_state_transition',
                'booking',
                $booking->id,
                [
                    'from_state' => $fromState,
                    'to_state' => $toState,
                    'booking_reference' => $booking->booking_reference,
                ]
            );

            return $booking;
        });
    }

    /**
     * Process payment callback
     * Requirements: 2.7 - Support idempotency for payment callbacks
     */
    public function processPaymentCallback(string $bookingReference, array $paymentData): Booking
    {
        return DB::transaction(function () use ($bookingReference, $paymentData) {
            $booking = Booking::where('booking_reference', $bookingReference)->firstOrFail();

            // Idempotency check - if already processed this payment, return existing booking
            if ($booking->payment_reference === $paymentData['payment_reference']) {
                return $booking;
            }

            // Update payment reference
            $booking->update(['payment_reference' => $paymentData['payment_reference']]);

            // Transition based on payment status
            if ($paymentData['status'] === 'confirmed' || $paymentData['status'] === 'success') {
                $booking = $this->transitionState($booking->id, 'confirmed');

                // Capture payment confirmation facts
                $this->bookingFactsService->createAnalyticsFact('payment_confirmed', $booking, [
                    'payment_reference' => $paymentData['payment_reference'],
                    'payment_method' => $paymentData['payment_method'] ?? null,
                    'payment_amount' => $paymentData['amount'] ?? $booking->total_amount,
                    'payment_currency' => $paymentData['currency'] ?? $booking->currency,
                    'payment_timestamp' => $paymentData['timestamp'] ?? now()->toISOString(),
                    'payment_processing_time' => $paymentData['processing_time_ms'] ?? null,
                ]);

                // Create outbox event for payment confirmation side effects
                $this->outboxService->createEvent(
                    'booking',
                    (string) $booking->id,
                    'payment.confirmed',
                    [
                        'booking_id' => $booking->id,
                        'booking_reference' => $booking->booking_reference,
                        'payment_reference' => $paymentData['payment_reference'],
                        'payment_method' => $paymentData['payment_method'] ?? null,
                        'payment_amount' => $paymentData['amount'] ?? $booking->total_amount,
                        'payment_currency' => $paymentData['currency'] ?? $booking->currency,
                    ]
                );

            } elseif ($paymentData['status'] === 'failed' || $paymentData['status'] === 'cancelled') {
                $booking = $this->transitionState($booking->id, 'cancelled');
            }

            // Log the payment callback processing
            $this->auditService->logActivity(
                'payment_callback_processed',
                'booking',
                $booking->id,
                [
                    'payment_reference' => $paymentData['payment_reference'],
                    'payment_status' => $paymentData['status'],
                    'booking_reference' => $bookingReference,
                ]
            );

            return $booking;
        });
    }

    /**
     * Cancel booking with improved capacity management
     * Requirements: 2.5 - Transition to cancelled state
     * Requirements: 1.4, 1.5 - Proper capacity release and status management
     */
    public function cancelBooking(int $bookingId, string $reason): Booking
    {
        return DB::transaction(function () use ($bookingId, $reason) {
            $booking = Booking::findOrFail($bookingId);

            // Check if booking can be cancelled
            if ($booking->state === 'cancelled') {
                throw new \InvalidArgumentException('Booking is already cancelled');
            }

            // Get attendee count before transitioning state
            $attendeeCount = $booking->attendees->count();

            // Transition to cancelled state (this will call releaseCapacity)
            $booking = $this->transitionState($bookingId, 'cancelled');

            // Capture cancellation facts
            $this->bookingFactsService->createAnalyticsFact('cancellation', $booking, [
                'cancellation_reason' => $reason,
                'cancelled_tickets' => $attendeeCount,
                'original_amount' => $booking->total_amount,
                'refund_eligible' => $this->isRefundEligible($booking),
                'days_before_event' => $booking->event->event_date ?
                    now()->diffInDays($booking->event->event_date, false) : null,
                'booking_age_hours' => $booking->created_at->diffInHours(now()),
            ]);

            // Create outbox event for cancellation side effects
            $this->outboxService->createEvent(
                'booking',
                (string) $booking->id,
                'booking.cancelled',
                [
                    'booking_id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'cancellation_reason' => $reason,
                    'cancelled_tickets' => $attendeeCount,
                    'refund_eligible' => $this->isRefundEligible($booking),
                    'original_amount' => $booking->total_amount,
                ]
            );

            // Log the cancellation with additional details
            $this->auditService->logActivity(
                'booking_cancelled',
                'booking',
                $booking->id,
                [
                    'reason' => $reason,
                    'booking_reference' => $booking->booking_reference,
                    'released_capacity' => $attendeeCount,
                    'event_id' => $booking->event_id,
                ]
            );

            return $booking;
        });
    }

    /**
     * Validate state transition
     * Requirements: 2.6 - Reject invalid state transitions
     */
    public function validateStateTransition(string $fromState, string $toState): bool
    {
        if (! isset(self::VALID_TRANSITIONS[$fromState])) {
            return false;
        }

        return in_array($toState, self::VALID_TRANSITIONS[$fromState]);
    }

    /**
     * Get booking by reference
     */
    public function getBookingByReference(string $bookingReference): Booking
    {
        return Booking::where('booking_reference', $bookingReference)
            ->with(['event', 'attendees', 'tickets'])
            ->firstOrFail();
    }

    /**
     * Get booking by ID
     */
    public function getBookingById(int $bookingId): Booking
    {
        return Booking::with(['event', 'attendees', 'tickets'])->findOrFail($bookingId);
    }

    /**
     * Get bookings for an event
     */
    public function getEventBookings(int $eventId): Collection
    {
        return Booking::where('event_id', $eventId)
            ->with(['attendees', 'tickets'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Check if booking is eligible for refund based on cancellation timing
     */
    private function isRefundEligible(Booking $booking): bool
    {
        if (! $booking->event->event_date) {
            return false;
        }

        $hoursBeforeEvent = now()->diffInHours($booking->event->event_date, false);

        // Default refund policy: eligible if cancelled more than 24 hours before event
        return $hoursBeforeEvent > 24;
    }

    /**
     * Generate unique booking reference
     */
    private function generateBookingReference(): string
    {
        do {
            $reference = 'BK'.strtoupper(Str::random(8));
        } while (Booking::where('booking_reference', $reference)->exists());

        return $reference;
    }

    /**
     * Perform state-specific transition logic with enhanced capacity management
     */
    private function performStateTransitionLogic(Booking $booking, string $fromState, string $toState): void
    {
        switch ($toState) {
            case 'pending':
                // When transitioning to pending, reservation should already exist from creation
                if ($fromState === 'new') {
                    $this->validateCapacityReservation($booking);
                }
                break;

            case 'confirmed':
                // When transitioning to confirmed, confirm the capacity reservation
                if ($fromState === 'pending' && $booking->capacity_reservation_token) {
                    try {
                        $confirmResult = $this->capacityReservationService->confirmReservation(
                            $booking->capacity_reservation_token,
                            $booking->id
                        );

                        // Log successful confirmation
                        $this->auditService->logActivity(
                            'capacity_reservation_confirmed',
                            'booking',
                            $booking->id,
                            [
                                'reservation_token' => $booking->capacity_reservation_token,
                                'quantity' => $confirmResult['quantity'],
                                'available_capacity_after' => $confirmResult['available_capacity_after'],
                            ]
                        );

                    } catch (\Exception $e) {
                        // If reservation confirmation fails, the booking cannot be confirmed
                        throw new \InvalidArgumentException(
                            'Cannot confirm booking: '.$e->getMessage()
                        );
                    }
                }
                break;

            case 'booked':
                // When transitioning to booked, capacity should already be confirmed
                if ($fromState === 'confirmed') {
                    // Capacity is already confirmed, booking is now final
                    // Ticket generation will be handled by TicketService
                }
                break;

            case 'cancelled':
                // When transitioning to cancelled, release capacity reservation
                if ($booking->capacity_reservation_token) {
                    try {
                        $releaseResult = $this->capacityReservationService->releaseReservation(
                            $booking->capacity_reservation_token,
                            "Booking cancelled: {$booking->booking_reference}"
                        );

                        // Log successful release
                        $this->auditService->logActivity(
                            'capacity_reservation_released',
                            'booking',
                            $booking->id,
                            [
                                'reservation_token' => $booking->capacity_reservation_token,
                                'quantity_released' => $releaseResult['quantity_released'],
                                'available_capacity_after' => $releaseResult['available_capacity_after'],
                                'reason' => $releaseResult['reason'],
                            ]
                        );

                    } catch (\Exception $e) {
                        // Log error but don't fail the cancellation
                        \Log::error('Failed to release capacity reservation during cancellation', [
                            'booking_id' => $booking->id,
                            'reservation_token' => $booking->capacity_reservation_token,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                break;
        }
    }

    /**
     * Validate capacity reservation exists and is still valid
     */
    private function validateCapacityReservation(Booking $booking): void
    {
        if (! $booking->capacity_reservation_token) {
            throw new \InvalidArgumentException('No capacity reservation found for booking');
        }

        $reservation = $this->capacityReservationService->getReservation($booking->capacity_reservation_token);

        if (! $reservation) {
            throw new \InvalidArgumentException('Capacity reservation not found');
        }

        if ($reservation['status'] !== 'active') {
            throw new \InvalidArgumentException('Capacity reservation is not active: '.$reservation['status']);
        }

        if ($reservation['is_expired']) {
            throw new \InvalidArgumentException('Capacity reservation has expired');
        }
    }

    /**
     * Check real-time capacity using enhanced reservation system
     * Requirements: 1.4, 1.5 - Real-time capacity checking
     * Requirements: 19.5 - Real-time capacity calculations
     */
    private function checkRealTimeCapacity(Event $event, int $requestedTickets): bool
    {
        try {
            $availableCapacity = $this->capacityReservationService->calculateRealTimeAvailableCapacity($event);

            return $availableCapacity >= $requestedTickets;
        } catch (\Exception $e) {
            \Log::error('Failed to check real-time capacity', [
                'event_id' => $event->id,
                'requested_tickets' => $requestedTickets,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if booking can be created for event using enhanced capacity system
     * Requirements: 1.4, 1.5 - Comprehensive booking validation
     * Requirements: 13.1, 13.2, 13.3 - Tier-based booking eligibility
     * Requirements: 14.1, 14.2, 14.5 - Fraud detection and anti-abuse validation
     * Requirements: 19.5 - Real-time capacity availability
     */
    public function canCreateBooking(int $eventId, int $requestedTickets, ?int $customerId = null, ?string $tenantId = null, array $fraudContext = []): array
    {
        $event = Event::findOrFail($eventId);
        $tenantId = $this->resolveTenantId($tenantId);

        $result = [
            'can_book' => false,
            'reason' => null,
            'available_capacity' => 0,
            'requested_tickets' => $requestedTickets,
            'tier_info' => null,
            'early_access_info' => null,
            'fraud_check' => null,
            'identity_verification_required' => false,
        ];

        if (! $event->canAcceptBookings()) {
            $result['reason'] = 'Event is not accepting bookings';

            return $result;
        }

        // Get real-time available capacity using enhanced system
        try {
            $availableCapacity = $this->capacityReservationService->calculateRealTimeAvailableCapacity($event);
            $result['available_capacity'] = $availableCapacity;

            if ($availableCapacity <= 0) {
                $result['reason'] = 'Event is sold out';

                return $result;
            }

            if ($availableCapacity < $requestedTickets) {
                $result['reason'] = "Insufficient capacity. Available: {$availableCapacity}, Requested: {$requestedTickets}";

                return $result;
            }

        } catch (\Exception $e) {
            $result['reason'] = 'Unable to check capacity availability';
            \Log::error('Failed to check capacity for booking validation', [
                'event_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return $result;
        }

        // Fraud and anti-abuse checks
        if (! empty($fraudContext)) {
            $fraudCheck = $this->performFraudChecks($tenantId, array_merge($fraudContext, [
                'event_id' => $eventId,
                'attendees' => array_fill(0, $requestedTickets, ['ticket_type_id' => 1]), // Mock attendees for validation
            ]));

            $result['fraud_check'] = $fraudCheck;

            if ($fraudCheck['blocked']) {
                $result['reason'] = $fraudCheck['reason'];

                return $result;
            }

            if ($fraudCheck['requires_identity_verification']) {
                $result['identity_verification_required'] = true;
            }
        }

        // Check tier-based eligibility if customer is provided
        if ($customerId && $tenantId) {
            $tierCheck = $this->checkTierBookingEligibility($tenantId, $customerId, $eventId, []);
            $result['tier_info'] = $tierCheck;

            if (! $tierCheck['eligible']) {
                $result['reason'] = $tierCheck['reason'];

                return $result;
            }
        }

        $result['can_book'] = true;

        return $result;
    }

    /**
     * Check tier-based booking eligibility for a customer.
     * Requirements: 13.1, 13.2, 13.3 - Tier privilege enforcement
     */
    public function checkTierBookingEligibility(string $tenantId, int $customerId, int $eventId, array $requestedTicketTypes): array
    {
        // Check if customer has early access
        $earlyAccessInfo = $this->fanTierService->hasEarlyAccess($tenantId, $customerId, $eventId);

        // If event is in early access period, check eligibility
        if ($this->isEventInEarlyAccessPeriod($tenantId, $eventId)) {
            if (! $earlyAccessInfo['has_access']) {
                return [
                    'eligible' => false,
                    'reason' => 'Event is currently in early access period. '.$earlyAccessInfo['reason'],
                    'early_access_info' => $earlyAccessInfo,
                ];
            }

            // Check ticket type eligibility for early access
            if (isset($earlyAccessInfo['window'])) {
                $window = $earlyAccessInfo['window'];
                foreach ($requestedTicketTypes as $ticketTypeData) {
                    if (! $window->isTicketTypeEligible($ticketTypeData['ticket_type_id'])) {
                        return [
                            'eligible' => false,
                            'reason' => 'Some requested ticket types are not available during early access',
                            'early_access_info' => $earlyAccessInfo,
                        ];
                    }
                }

                // Check per-customer ticket limits
                if ($window->max_tickets_per_customer) {
                    $existingTickets = $this->getCustomerTicketCount($tenantId, $customerId, $eventId);
                    $requestedCount = count($requestedTicketTypes);

                    if ($existingTickets + $requestedCount > $window->max_tickets_per_customer) {
                        return [
                            'eligible' => false,
                            'reason' => "Early access limit: maximum {$window->max_tickets_per_customer} tickets per customer",
                            'early_access_info' => $earlyAccessInfo,
                        ];
                    }
                }
            }
        }

        return [
            'eligible' => true,
            'early_access_info' => $earlyAccessInfo,
        ];
    }

    /**
     * Apply tier-based discounts to booking amount.
     * Requirements: 13.1, 13.4 - Tier benefits and loyalty rewards
     */
    public function applyTierDiscounts(string $tenantId, int $customerId, float $originalAmount): array
    {
        $benefits = $this->fanTierService->getCustomerBenefits($tenantId, $customerId);
        $appliedDiscounts = [];
        $finalAmount = $originalAmount;

        foreach ($benefits as $benefit) {
            if ($benefit->benefit_type === 'discount' && $benefit->appliesTo('booking')) {
                $discountPercentage = $benefit->getDiscountPercentage();
                if ($discountPercentage) {
                    $discountAmount = $originalAmount * ($discountPercentage / 100);
                    $finalAmount -= $discountAmount;

                    $appliedDiscounts[] = [
                        'benefit_id' => $benefit->id,
                        'benefit_name' => $benefit->benefit_name,
                        'discount_percentage' => $discountPercentage,
                        'discount_amount' => $discountAmount,
                    ];

                    // Record benefit usage
                    $benefit->recordUsage(
                        $customerId,
                        'discount_applied',
                        'booking',
                        null,
                        [
                            'original_amount' => $originalAmount,
                            'discount_percentage' => $discountPercentage,
                            'discount_amount' => $discountAmount,
                        ]
                    );
                }
            }
        }

        return [
            'original_amount' => $originalAmount,
            'final_amount' => max(0, $finalAmount), // Ensure non-negative
            'applied_discounts' => $appliedDiscounts,
            'total_discount' => $originalAmount - $finalAmount,
        ];
    }

    /**
     * Process booking completion and award loyalty points.
     * Requirements: 13.4 - Loyalty points earning
     */
    public function processBookingCompletion(int $bookingId): array
    {
        return DB::transaction(function () use ($bookingId) {
            $booking = Booking::findOrFail($bookingId);

            if (! $booking->customer_id) {
                return ['points_awarded' => 0, 'message' => 'No customer associated with booking'];
            }

            // Resolve tenant ID from tenancy context (fail closed)
            if (! tenancy()->initialized) {
                throw new \RuntimeException('Tenant context not initialized for loyalty processing');
            }
            $tenantId = (string) tenant()->id;

            // Calculate points for this booking
            $pointsToAward = $this->loyaltyService->calculateBookingPoints(
                $tenantId,
                (float) $booking->total_amount
            );

            // Award points
            $pointsResult = $this->loyaltyService->awardPoints(
                $tenantId,
                $booking->customer_id,
                $pointsToAward,
                'booking',
                $booking->id,
                "Booking completed: {$booking->booking_reference}"
            );

            // Update customer stats
            $customerTier = $this->fanTierService->getCustomerTier(
                $tenantId,
                $booking->customer_id
            );

            if ($customerTier) {
                $customerTier->updateStats(0, (float) $booking->total_amount);
            }

            // Check for tier progression
            $this->fanTierService->processAutomaticTierProgression(
                $tenantId,
                $booking->customer_id
            );

            return [
                'points_awarded' => $pointsResult['final_points'],
                'points_multiplier' => $pointsResult['multiplier'],
                'new_points_balance' => $pointsResult['new_balance'],
            ];
        });
    }

    /**
     * Check if event is currently in early access period.
     */
    private function isEventInEarlyAccessPeriod(string $tenantId, int $eventId): bool
    {
        $earlyAccessWindows = $this->fanTierService->getEarlyAccessWindows($tenantId, $eventId);

        return $earlyAccessWindows->contains(function ($window) {
            return $window->isCurrentlyOpen();
        });
    }

    /**
     * Get the number of tickets a customer has already booked for an event.
     */
    private function getCustomerTicketCount(string $tenantId, int $customerId, int $eventId): int
    {
        return DB::table('bookings')
            ->join('booking_attendees', 'bookings.id', '=', 'booking_attendees.booking_id')
            ->where('bookings.customer_id', $customerId)
            ->where('bookings.event_id', $eventId)
            ->whereIn('bookings.state', ['pending', 'confirmed', 'booked'])
            ->count();
    }

    /**
     * Perform comprehensive fraud checks before booking creation.
     * Requirements: 14.1, 14.2, 14.5 - Fraud detection and prevention
     */
    private function performFraudChecks(string $tenantId, array $bookingData): array
    {
        // Prepare fraud analysis context
        $fraudContext = [
            'tenant_id' => $tenantId,
            'customer_id' => $bookingData['customer_id'] ?? null,
            'ip_address' => $bookingData['ip_address'] ?? '0.0.0.0',
            'user_agent' => $bookingData['user_agent'] ?? null,
            'event_id' => $bookingData['event_id'],
            'tickets_requested' => count($bookingData['attendees'] ?? []),
            'total_amount' => $bookingData['total_amount'],
            'session_id' => $bookingData['session_id'] ?? null,
            'action_type' => 'booking_attempt',
            'metadata' => $bookingData['metadata'] ?? [],
        ];

        // Step 1: Check IP restrictions
        $ipCheck = $this->fraudDetectionService->checkIpRestriction($tenantId, $fraudContext['ip_address']);
        if ($ipCheck['restricted'] && $ipCheck['restriction_type'] === 'blocked') {
            return [
                'blocked' => true,
                'reason' => "IP address is blocked: {$ipCheck['reason']}",
                'risk_score' => 100,
                'risk_factors' => ['blocked_ip'],
                'requires_manual_review' => false,
            ];
        }

        // Step 2: Check rate limiting
        $rateLimitCheck = $this->antiAbuseService->enforceRateLimit($tenantId, $fraudContext);
        if ($rateLimitCheck['limited']) {
            return [
                'blocked' => true,
                'reason' => 'Rate limit exceeded. Please try again later.',
                'risk_score' => 80,
                'risk_factors' => ['rate_limit_exceeded'],
                'requires_manual_review' => false,
            ];
        }

        // Step 3: Analyze booking attempt for fraud patterns
        $fraudAnalysis = $this->fraudDetectionService->analyzeBookingAttempt($fraudContext);

        // Step 4: Check if identity verification is required
        $requiresVerification = false;
        if (isset($bookingData['customer_id'])) {
            $requiresVerification = $this->fraudDetectionService->requiresIdentityVerification(
                $tenantId,
                $bookingData['customer_id'],
                $fraudAnalysis['risk_score']
            );
        }

        // Step 5: Determine if booking should be blocked or require manual review
        $blocked = false;
        $requiresManualReview = false;
        $reason = null;

        switch ($fraudAnalysis['recommendation']) {
            case 'block':
                $blocked = true;
                $reason = 'Booking blocked due to high fraud risk';
                break;

            case 'manual_review':
                $requiresManualReview = true;
                break;

            case 'identity_verification':
                if ($requiresVerification) {
                    $blocked = true;
                    $reason = 'Identity verification required before booking';
                }
                break;
        }

        // Step 6: Execute automated response actions if needed
        if (! $blocked && ! empty($fraudAnalysis['actions_required'])) {
            $this->antiAbuseService->executeAutomatedResponse(
                $tenantId,
                $fraudAnalysis['actions_required'],
                array_merge($fraudContext, [
                    'risk_score' => $fraudAnalysis['risk_score'],
                    'risk_factors' => $fraudAnalysis['evidence'],
                ])
            );
        }

        return [
            'blocked' => $blocked,
            'reason' => $reason,
            'risk_score' => $fraudAnalysis['risk_score'],
            'risk_factors' => $fraudAnalysis['evidence'],
            'requires_manual_review' => $requiresManualReview,
            'requires_identity_verification' => $requiresVerification,
            'triggered_rules' => $fraudAnalysis['triggered_rules'],
        ];
    }

    /**
     * Check booking velocity limits for a customer.
     * Requirements: 14.1 - Booking velocity monitoring
     */
    public function checkBookingVelocityLimits(string $tenantId, int $customerId, int $eventId): array
    {
        // Check customer velocity
        $customerVelocity = $this->fraudDetectionService->monitorCustomerVelocity($tenantId, $customerId, 60);

        // Check if customer has exceeded reasonable booking limits
        $exceedsLimits = false;
        $reason = null;

        if ($customerVelocity['total_attempts'] > 10) {
            $exceedsLimits = true;
            $reason = "Too many booking attempts ({$customerVelocity['total_attempts']}) in the last hour";
        }

        if ($customerVelocity['failed_attempts'] > 5 && $customerVelocity['failure_rate'] > 70) {
            $exceedsLimits = true;
            $reason = "High failure rate ({$customerVelocity['failure_rate']}%) with multiple failed attempts";
        }

        if ($customerVelocity['total_tickets_requested'] > 50) {
            $exceedsLimits = true;
            $reason = "Excessive ticket requests ({$customerVelocity['total_tickets_requested']}) in the last hour";
        }

        return [
            'exceeds_limits' => $exceedsLimits,
            'reason' => $reason,
            'velocity_data' => $customerVelocity,
        ];
    }

    /**
     * Validate identity verification status for high-risk bookings.
     * Requirements: 14.2 - Identity verification escalation
     */
    public function validateIdentityVerification(string $tenantId, int $customerId, float $riskScore): array
    {
        $requiresVerification = $this->fraudDetectionService->requiresIdentityVerification(
            $tenantId,
            $customerId,
            $riskScore
        );

        if (! $requiresVerification) {
            return [
                'verification_required' => false,
                'verification_level' => 'none',
            ];
        }

        // Check current verification status
        $verification = DB::table('identity_verifications')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->where('verification_status', 'verified')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderBy('created_at', 'desc')
            ->first();

        $currentLevel = $verification ? $verification->verification_level : 'none';
        $requiredLevel = $riskScore >= 80 ? 'full' : 'basic';

        $isVerified = $verification &&
                     ($verification->verification_level === $requiredLevel ||
                      ($requiredLevel === 'basic' && $verification->verification_level === 'full'));

        return [
            'verification_required' => ! $isVerified,
            'current_level' => $currentLevel,
            'required_level' => $requiredLevel,
            'verification_status' => $verification ? $verification->verification_status : 'none',
        ];
    }

    /**
     * Resolve tenant context for booking operations.
     *
     * Accept explicit tenant IDs for trusted internal jobs, otherwise require
     * canonical tenancy context from middleware.
     */
    private function resolveTenantId(?string $tenantId = null): string
    {
        if (is_string($tenantId) && trim($tenantId) !== '') {
            return trim($tenantId);
        }

        if (tenancy()->initialized) {
            return (string) tenant()->id;
        }

        throw new \RuntimeException('Tenant context not initialized for BookingService');
    }
}

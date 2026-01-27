<?php

namespace Modules\EventManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\EventManagement\Http\Requests\CreateBookingRequest;
use Modules\EventManagement\Http\Requests\PaymentCallbackRequest;
use Modules\EventManagement\Http\Requests\CancelBookingRequest;
use Modules\EventManagement\Services\BookingService;
use Modules\EventManagement\Services\TicketService;
use Modules\EventManagement\Exceptions\FeatureNotEnabledException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BookingController extends Controller
{
    protected BookingService $bookingService;
    protected TicketService $ticketService;

    public function __construct(BookingService $bookingService, TicketService $ticketService)
    {
        $this->bookingService = $bookingService;
        $this->ticketService = $ticketService;
    }

    /**
     * Display a listing of bookings for admin
     * Requirements: 7.1 - REST endpoints under /api/v1 namespace
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $eventId = $request->query('event_id');
            
            if ($eventId) {
                $bookings = $this->bookingService->getEventBookings($eventId);
            } else {
                // Get all bookings with pagination
                $bookings = $this->getAllBookings($request->query());
            }
            
            return response()->json([
                'data' => $bookings,
                'message' => 'Bookings retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Store a newly created booking
     * Requirements: 7.1, 7.5 - CRUD endpoints with proper validation
     */
    public function store(CreateBookingRequest $request): JsonResponse
    {
        try {
            // Check if booking can be created
            $validationData = $request->validated();
            $attendeeCount = count($validationData['attendees'] ?? []);
            
            $canBook = $this->bookingService->canCreateBooking(
                $validationData['event_id'],
                $attendeeCount
            );
            
            if (!$canBook['can_book']) {
                return response()->json([
                    'error' => [
                        'code' => 'BOOKING_NOT_ALLOWED',
                        'message' => $canBook['reason'],
                        'details' => [
                            'event_id' => $validationData['event_id'],
                            'requested_tickets' => $attendeeCount,
                            'available_capacity' => $canBook['available_capacity']
                        ],
                        'request_id' => request()->header('X-Request-ID', uniqid())
                    ]
                ], Response::HTTP_CONFLICT);
            }

            $booking = $this->bookingService->createBooking($validationData);
            
            return response()->json([
                'data' => $booking,
                'message' => 'Booking created successfully'
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Display the specified booking
     * Requirements: 7.1 - Booking detail endpoints
     */
    public function show(int $id): JsonResponse
    {
        try {
            $booking = $this->bookingService->getBookingById($id);
            
            return response()->json([
                'data' => $booking,
                'message' => 'Booking retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Display booking by reference (public endpoint)
     * Requirements: 7.1 - Public booking lookup
     */
    public function showByReference(string $reference): JsonResponse
    {
        try {
            $booking = $this->bookingService->getBookingByReference($reference);
            
            return response()->json([
                'data' => $booking,
                'message' => 'Booking retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Initiate payment for booking
     * Requirements: 7.1, 7.4 - State management endpoints
     */
    public function initiatePayment(int $id): JsonResponse
    {
        try {
            $booking = $this->bookingService->transitionState($id, 'pending');
            
            return response()->json([
                'data' => $booking,
                'message' => 'Payment initiated successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Process payment callback
     * Requirements: 7.4 - Payment callback handling
     * Requirements: 2.7 - Support idempotency for payment callbacks
     */
    public function paymentCallback(PaymentCallbackRequest $request): JsonResponse
    {
        try {
            $validatedData = $request->validated();
            
            $booking = $this->bookingService->processPaymentCallback(
                $validatedData['booking_reference'],
                $validatedData
            );
            
            // If payment confirmed and booking is now confirmed, generate tickets
            if ($booking->state === 'confirmed') {
                $booking = $this->bookingService->transitionState($booking->id, 'booked');
                $this->ticketService->generateTickets($booking->id);
            }
            
            return response()->json([
                'data' => $booking,
                'message' => 'Payment callback processed successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Confirm booking (manual confirmation)
     * Requirements: 7.1, 7.4 - State management endpoints
     */
    public function confirm(int $id): JsonResponse
    {
        try {
            $booking = $this->bookingService->transitionState($id, 'confirmed');
            
            return response()->json([
                'data' => $booking,
                'message' => 'Booking confirmed successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Complete booking (generate tickets)
     * Requirements: 7.1, 7.4 - State management endpoints
     */
    public function complete(int $id): JsonResponse
    {
        try {
            $booking = $this->bookingService->transitionState($id, 'booked');
            
            // Generate tickets for the booking
            $tickets = $this->ticketService->generateTickets($booking->id);
            
            return response()->json([
                'data' => [
                    'booking' => $booking,
                    'tickets' => $tickets
                ],
                'message' => 'Booking completed and tickets generated successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Cancel booking
     * Requirements: 7.1, 7.4 - Booking cancellation endpoints
     */
    public function cancel(CancelBookingRequest $request, int $id): JsonResponse
    {
        try {
            $validatedData = $request->validated();
            
            $booking = $this->bookingService->cancelBooking($id, $validatedData['reason']);
            
            return response()->json([
                'data' => $booking,
                'message' => 'Booking cancelled successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Check booking availability for event
     * Requirements: 7.1 - Availability checking endpoint
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'event_id' => ['required', 'integer', 'exists:events,id'],
                'requested_tickets' => ['required', 'integer', 'min:1']
            ]);

            $canBook = $this->bookingService->canCreateBooking(
                $request->event_id,
                $request->requested_tickets
            );
            
            return response()->json([
                'data' => $canBook,
                'message' => 'Availability checked successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Get booking state transitions
     * Requirements: 7.1 - State management information
     */
    public function getStateTransitions(int $id): JsonResponse
    {
        try {
            $booking = $this->bookingService->getBookingById($id);
            
            $validTransitions = $this->getValidTransitionsForState($booking->state);
            
            return response()->json([
                'data' => [
                    'current_state' => $booking->state,
                    'valid_transitions' => $validTransitions,
                    'booking_id' => $booking->id
                ],
                'message' => 'State transitions retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Get all bookings with filters (admin only)
     */
    private function getAllBookings(array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = \Modules\EventManagement\Models\Booking::with(['event', 'attendees', 'tickets']);

        // Apply filters
        if (isset($filters['state'])) {
            $query->where('state', $filters['state']);
        }

        if (isset($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        if (isset($filters['buyer_email'])) {
            $query->where('buyer_email', 'like', '%' . $filters['buyer_email'] . '%');
        }

        return $query->orderBy('created_at', 'desc')->paginate(20);
    }

    /**
     * Get valid transitions for current state
     */
    private function getValidTransitionsForState(string $currentState): array
    {
        $transitions = [
            'new' => ['pending', 'cancelled'],
            'pending' => ['confirmed', 'cancelled'],
            'confirmed' => ['booked', 'cancelled'],
            'booked' => ['cancelled'],
            'cancelled' => []
        ];

        return $transitions[$currentState] ?? [];
    }

    /**
     * Handle exceptions and return consistent error responses
     * Requirements: 7.5 - Consistent error responses with proper HTTP status codes
     */
    protected function handleException(\Exception $e): JsonResponse
    {
        $requestId = request()->header('X-Request-ID', uniqid());

        if ($e instanceof FeatureNotEnabledException) {
            return response()->json([
                'error' => [
                    'code' => 'FEATURE_NOT_ENABLED',
                    'message' => $e->getMessage(),
                    'request_id' => $requestId
                ]
            ], Response::HTTP_FORBIDDEN);
        }

        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'error' => [
                    'code' => 'BOOKING_NOT_FOUND',
                    'message' => 'Booking not found',
                    'request_id' => $requestId
                ]
            ], Response::HTTP_NOT_FOUND);
        }

        if ($e instanceof \InvalidArgumentException) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_ARGUMENT',
                    'message' => $e->getMessage(),
                    'request_id' => $requestId
                ]
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Log unexpected errors
        \Log::error('Booking API Error', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'request_id' => $requestId
        ]);

        return response()->json([
            'error' => [
                'code' => 'INTERNAL_SERVER_ERROR',
                'message' => 'An unexpected error occurred',
                'request_id' => $requestId
            ]
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
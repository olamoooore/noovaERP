<?php

namespace Modules\EventManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\EventManagement\Http\Requests\ValidateTicketRequest;
use Modules\EventManagement\Http\Requests\ReissueTicketRequest;
use Modules\EventManagement\Services\TicketService;
use Modules\EventManagement\Exceptions\FeatureNotEnabledException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TicketController extends Controller
{
    protected TicketService $ticketService;

    public function __construct(TicketService $ticketService)
    {
        $this->ticketService = $ticketService;
    }

    /**
     * Display a listing of tickets for a booking
     * Requirements: 7.1 - REST endpoints under /api/v1 namespace
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $bookingId = $request->query('booking_id');
            $eventId = $request->query('event_id');

            if ($bookingId) {
                $tickets = $this->ticketService->getBookingTickets($bookingId);
            } elseif ($eventId) {
                $tickets = $this->ticketService->getEventTickets($eventId);
            } else {
                return response()->json([
                    'error' => [
                        'code' => 'MISSING_PARAMETER',
                        'message' => 'Either booking_id or event_id parameter is required',
                        'request_id' => $request->header('X-Request-ID', uniqid())
                    ]
                ], Response::HTTP_BAD_REQUEST);
            }

            return response()->json([
                'data' => $tickets,
                'message' => 'Tickets retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Display the specified ticket
     * Requirements: 7.1 - REST endpoints with proper validation
     */
    public function show(string $ticketCode): JsonResponse
    {
        try {
            $ticket = $this->ticketService->getTicketByCode($ticketCode);

            if (!$ticket) {
                return response()->json([
                    'error' => [
                        'code' => 'TICKET_NOT_FOUND',
                        'message' => 'Ticket not found',
                        'request_id' => request()->header('X-Request-ID', uniqid())
                    ]
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'data' => $ticket,
                'message' => 'Ticket retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Validate a ticket for entry
     * Requirements: 3.5 - Ticket validation for event entry
     */
    public function validate(ValidateTicketRequest $request): JsonResponse
    {
        try {
            $validationData = $request->validated();
            $result = $this->ticketService->validateTicket(
                $validationData['ticket_code'],
                $validationData['event_id'] ?? null
            );

            if ($result['valid']) {
                return response()->json([
                    'data' => [
                        'valid' => true,
                        'ticket' => $result['ticket'],
                        'attendee' => $result['attendee'],
                        'event' => $result['event'],
                        'validation_timestamp' => now()->toISOString()
                    ],
                    'message' => 'Ticket is valid'
                ]);
            } else {
                return response()->json([
                    'data' => [
                        'valid' => false,
                        'reason' => $result['reason'],
                        'validation_timestamp' => now()->toISOString()
                    ],
                    'message' => 'Ticket validation failed'
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Mark ticket as used for entry
     * Requirements: 3.5 - Track ticket usage
     */
    public function markUsed(string $ticketCode): JsonResponse
    {
        try {
            $result = $this->ticketService->markTicketAsUsed($ticketCode);

            if ($result['success']) {
                return response()->json([
                    'data' => [
                        'ticket_code' => $ticketCode,
                        'used_at' => $result['used_at'],
                        'status' => 'used'
                    ],
                    'message' => 'Ticket marked as used successfully'
                ]);
            } else {
                return response()->json([
                    'error' => [
                        'code' => 'TICKET_USAGE_FAILED',
                        'message' => $result['reason'],
                        'request_id' => request()->header('X-Request-ID', uniqid())
                    ]
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Reissue a ticket
     * Requirements: 3.4 - Ticket reissuance process
     */
    public function reissue(ReissueTicketRequest $request): JsonResponse
    {
        try {
            $validationData = $request->validated();
            $result = $this->ticketService->reissueTicket(
                $validationData['original_ticket_code'],
                $validationData['reason'] ?? 'Ticket reissue requested'
            );

            return response()->json([
                'data' => [
                    'original_ticket' => $result['original_ticket'],
                    'new_ticket' => $result['new_ticket'],
                    'reissue_reason' => $result['reason'],
                    'reissued_at' => $result['reissued_at']
                ],
                'message' => 'Ticket reissued successfully'
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Generate QR code for ticket
     * Requirements: 3.2 - QR/barcode generation
     */
    public function qrCode(string $ticketCode): JsonResponse
    {
        try {
            $qrData = $this->ticketService->generateQRCode($ticketCode);

            if (!$qrData) {
                return response()->json([
                    'error' => [
                        'code' => 'TICKET_NOT_FOUND',
                        'message' => 'Ticket not found or invalid',
                        'request_id' => request()->header('X-Request-ID', uniqid())
                    ]
                ], Response::HTTP_NOT_FOUND);
            }

            return response()->json([
                'data' => [
                    'ticket_code' => $ticketCode,
                    'qr_code_data' => $qrData['qr_data'],
                    'qr_code_svg' => $qrData['qr_svg'],
                    'generated_at' => now()->toISOString()
                ],
                'message' => 'QR code generated successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Get ticket statistics for an event
     * Requirements: Analytics and reporting
     */
    public function statistics(int $eventId): JsonResponse
    {
        try {
            $stats = $this->ticketService->getTicketStatistics($eventId);

            return response()->json([
                'data' => $stats,
                'message' => 'Ticket statistics retrieved successfully'
            ]);
        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Download tickets for a booking
     */
    public function download(int $bookingId): Response
    {
        // Stub implementation - return a dummy PDF
        return response('PDF Content', 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="tickets.pdf"',
        ]);
    }

    /**
     * Handle exceptions and return appropriate JSON response
     */
    protected function handleException(\Exception $e): JsonResponse
    {
        if ($e instanceof FeatureNotEnabledException) {
            return response()->json([
                'error' => [
                    'code' => 'FEATURE_NOT_ENABLED',
                    'message' => $e->getMessage(),
                    'request_id' => request()->header('X-Request-ID', uniqid())
                ]
            ], Response::HTTP_FORBIDDEN);
        }

        // Log the error for debugging
        \Log::error('TicketController error', [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'request_id' => request()->header('X-Request-ID', uniqid())
        ]);

        return response()->json([
            'error' => [
                'code' => 'INTERNAL_ERROR',
                'message' => 'An internal error occurred',
                'request_id' => request()->header('X-Request-ID', uniqid())
            ]
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
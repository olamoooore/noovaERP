<?php

namespace Modules\EventManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\EventManagement\Services\PurchaseService;
use Modules\EventManagement\Exceptions\InvalidAcquisitionModeException;
use Modules\EventManagement\Exceptions\SaleNotStartedException;
use Modules\EventManagement\Exceptions\SaleClosedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Purchase Controller
 * 
 * Handles ticket purchase operations with acquisition mode and sale window validation.
 * Requirements: 3.4, 4.4, 8.1, 8.2, 8.3, 8.7
 */
class PurchaseController extends Controller
{
    protected PurchaseService $purchaseService;

    public function __construct(PurchaseService $purchaseService)
    {
        $this->purchaseService = $purchaseService;
    }

    /**
     * Purchase tickets for an event
     * 
     * Requirements: 3.4 - Direct purchase functionality
     * Requirements: 4.4 - Prevent purchase for bidding events
     * Requirements: 8.1, 8.2, 8.3 - Sale window enforcement
     * Requirements: 8.7 - Descriptive error messages
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function purchaseTickets(Request $request): JsonResponse
    {
        try {
            // Validate request
            $validated = $request->validate([
                'event_id' => ['required', 'integer', 'exists:events,id'],
                'customer_id' => ['required', 'integer', 'exists:customers,id'],
                'quantity' => ['required', 'integer', 'min:1'],
                'ticket_type_id' => ['nullable', 'integer', 'exists:event_ticket_types,id'],
                'attendees' => ['nullable', 'array'],
                'attendees.*.name' => ['required_with:attendees', 'string', 'max:255'],
                'attendees.*.email' => ['required_with:attendees', 'email', 'max:255'],
                'attendees.*.phone' => ['nullable', 'string', 'max:20'],
            ]);

            // Attempt to purchase tickets
            $booking = $this->purchaseService->purchaseTickets($validated);

            Log::info('Ticket purchase successful', [
                'event_id' => $validated['event_id'],
                'customer_id' => $validated['customer_id'],
                'quantity' => $validated['quantity'],
                'booking_id' => $booking->id,
            ]);

            return response()->json([
                'data' => $booking,
                'message' => 'Tickets purchased successfully'
            ], Response::HTTP_CREATED);

        } catch (InvalidAcquisitionModeException $e) {
            return $this->handleAcquisitionModeError($e);
        } catch (SaleNotStartedException $e) {
            return $this->handleSaleWindowError($e, 'not_started');
        } catch (SaleClosedException $e) {
            return $this->handleSaleWindowError($e, 'closed');
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->handleValidationError($e);
        } catch (\Exception $e) {
            return $this->handleGenericError($e);
        }
    }

    /**
     * Handle acquisition mode errors
     * Requirements: 4.4, 8.7 - Descriptive error messages
     * 
     * @param InvalidAcquisitionModeException $e
     * @return JsonResponse
     */
    protected function handleAcquisitionModeError(InvalidAcquisitionModeException $e): JsonResponse
    {
        $requestId = request()->header('X-Request-ID', uniqid());

        Log::warning('Purchase attempt on bidding-only event', [
            'error' => $e->getMessage(),
            'request_id' => $requestId,
        ]);

        return response()->json([
            'error' => [
                'code' => 'INVALID_ACQUISITION_MODE',
                'message' => $e->getMessage(),
                'details' => [
                    'allowed_action' => 'bid',
                    'attempted_action' => 'purchase',
                ],
                'request_id' => $requestId,
            ]
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * Handle sale window errors
     * Requirements: 8.1, 8.2, 8.7 - Descriptive error messages for sale window violations
     * 
     * @param \Exception $e
     * @param string $status
     * @return JsonResponse
     */
    protected function handleSaleWindowError(\Exception $e, string $status): JsonResponse
    {
        $requestId = request()->header('X-Request-ID', uniqid());

        $statusCode = Response::HTTP_FORBIDDEN;
        $errorCode = $status === 'not_started' ? 'SALE_NOT_STARTED' : 'SALE_CLOSED';

        Log::info('Purchase attempt outside sale window', [
            'status' => $status,
            'error' => $e->getMessage(),
            'request_id' => $requestId,
        ]);

        return response()->json([
            'error' => [
                'code' => $errorCode,
                'message' => $e->getMessage(),
                'details' => [
                    'sale_status' => $status,
                ],
                'request_id' => $requestId,
            ]
        ], $statusCode);
    }

    /**
     * Handle validation errors
     * Requirements: 8.7 - Descriptive error messages
     * 
     * @param \Illuminate\Validation\ValidationException $e
     * @return JsonResponse
     */
    protected function handleValidationError(\Illuminate\Validation\ValidationException $e): JsonResponse
    {
        $requestId = request()->header('X-Request-ID', uniqid());

        return response()->json([
            'error' => [
                'code' => 'VALIDATION_ERROR',
                'message' => 'The provided data is invalid',
                'validation_errors' => $e->errors(),
                'request_id' => $requestId,
            ]
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Handle generic errors
     * Requirements: 8.7 - Descriptive error messages
     * 
     * @param \Exception $e
     * @return JsonResponse
     */
    protected function handleGenericError(\Exception $e): JsonResponse
    {
        $requestId = request()->header('X-Request-ID', uniqid());

        Log::error('Purchase error', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'request_id' => $requestId,
        ]);

        // Check for specific error types
        if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'error' => [
                    'code' => 'RESOURCE_NOT_FOUND',
                    'message' => 'The requested resource was not found',
                    'request_id' => $requestId,
                ]
            ], Response::HTTP_NOT_FOUND);
        }

        if ($e instanceof \InvalidArgumentException) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_ARGUMENT',
                    'message' => $e->getMessage(),
                    'request_id' => $requestId,
                ]
            ], Response::HTTP_BAD_REQUEST);
        }

        return response()->json([
            'error' => [
                'code' => 'INTERNAL_SERVER_ERROR',
                'message' => 'An unexpected error occurred while processing your purchase',
                'request_id' => $requestId,
            ]
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}

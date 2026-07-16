<?php

namespace Modules\EventManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\EventManagement\Exceptions\InvalidAcquisitionModeException;
use Modules\EventManagement\Exceptions\SaleClosedException;
use Modules\EventManagement\Exceptions\SaleNotStartedException;
use Modules\EventManagement\Services\AccessControlService;
use Modules\EventManagement\Services\BiddingService;

/**
 * Bidding Controller
 *
 * Handles ticket bidding operations with acquisition mode and sale window validation.
 * Requirements: 3.5, 4.5, 8.1, 8.2, 8.3, 8.7
 */
class BiddingController extends Controller
{
    protected BiddingService $biddingService;

    protected AccessControlService $accessControlService;

    public function __construct(
        BiddingService $biddingService,
        AccessControlService $accessControlService
    ) {
        $this->biddingService = $biddingService;
        $this->accessControlService = $accessControlService;
    }

    /**
     * Submit a bid for event tickets
     *
     * Requirements: 3.5 - Bidding functionality
     * Requirements: 4.5 - Prevent bidding for purchase events
     * Requirements: 8.1, 8.2, 8.3 - Sale window enforcement
     * Requirements: 8.7 - Descriptive error messages
     */
    public function submitBid(Request $request): JsonResponse
    {
        try {
            // Validate request
            $validated = $request->validate([
                'event_id' => ['required', 'integer', 'exists:events,id'],
                'customer_id' => ['required', 'integer', 'exists:customers,id'],
                'bid_amount' => ['required', 'numeric', 'min:0.01'],
                'quantity' => ['required', 'integer', 'min:1'],
            ]);

            // Submit the bid
            $bid = $this->biddingService->submitBid($validated);

            Log::info('Bid submitted successfully', [
                'event_id' => $validated['event_id'],
                'customer_id' => $validated['customer_id'],
                'bid_amount' => $validated['bid_amount'],
                'quantity' => $validated['quantity'],
                'bid_id' => $bid->id,
            ]);

            return response()->json([
                'data' => $bid,
                'message' => 'Bid submitted successfully',
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
     * Get bids for an event (staff only)
     *
     * Requirements: 9.2 - Staff can view bids
     */
    public function getBids(Request $request, int $eventId): JsonResponse
    {
        try {
            // Check if user is staff
            $user = $request->user();
            if (! $this->accessControlService->canViewInventory($user)) {
                return response()->json([
                    'error' => [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to view bids',
                        'request_id' => request()->header('X-Request-ID', uniqid()),
                    ],
                ], Response::HTTP_FORBIDDEN);
            }

            // Get bids for the event
            $bids = $this->biddingService->getBidsForEvent($eventId);

            return response()->json([
                'data' => $bids,
                'message' => 'Bids retrieved successfully',
            ]);

        } catch (\Exception $e) {
            return $this->handleGenericError($e);
        }
    }

    /**
     * Handle acquisition mode errors
     * Requirements: 4.5, 8.7 - Descriptive error messages
     */
    protected function handleAcquisitionModeError(InvalidAcquisitionModeException $e): JsonResponse
    {
        $requestId = request()->header('X-Request-ID', uniqid());

        Log::warning('Bid attempt on purchase-only event', [
            'error' => $e->getMessage(),
            'request_id' => $requestId,
        ]);

        return response()->json([
            'error' => [
                'code' => 'INVALID_ACQUISITION_MODE',
                'message' => $e->getMessage(),
                'details' => [
                    'allowed_action' => 'purchase',
                    'attempted_action' => 'bid',
                ],
                'request_id' => $requestId,
            ],
        ], Response::HTTP_BAD_REQUEST);
    }

    /**
     * Handle sale window errors
     * Requirements: 8.1, 8.2, 8.7 - Descriptive error messages for sale window violations
     */
    protected function handleSaleWindowError(\Exception $e, string $status): JsonResponse
    {
        $requestId = request()->header('X-Request-ID', uniqid());

        $statusCode = Response::HTTP_FORBIDDEN;
        $errorCode = $status === 'not_started' ? 'SALE_NOT_STARTED' : 'SALE_CLOSED';

        Log::info('Bid attempt outside sale window', [
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
            ],
        ], $statusCode);
    }

    /**
     * Handle validation errors
     * Requirements: 8.7 - Descriptive error messages
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
            ],
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Accept or reject a bid (staff/admin only)
     *
     * Requirements: Bid management functionality
     */
    public function handleBidAction(Request $request, int $bidId, string $action): JsonResponse
    {
        try {
            // Validate action
            if (! in_array($action, ['accept', 'reject'])) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVALID_ACTION',
                        'message' => 'Action must be either "accept" or "reject"',
                        'request_id' => request()->header('X-Request-ID', uniqid()),
                    ],
                ], 400);
            }

            // Check if user is staff/admin
            $user = $request->user();
            if (! $this->accessControlService->canViewInventory($user)) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to manage bids',
                        'request_id' => request()->header('X-Request-ID', uniqid()),
                    ],
                ], 403);
            }

            // Validate additional data
            $validated = $request->validate([
                'admin_notes' => 'nullable|string|max:500',
                'reason' => 'nullable|string|max:255',
            ]);

            // Process the bid action
            $result = $this->biddingService->processBidAction(
                $bidId,
                $action,
                $user->id,
                $validated['admin_notes'] ?? null,
                $validated['reason'] ?? null
            );

            Log::info('Bid action processed', [
                'bid_id' => $bidId,
                'action' => $action,
                'admin_id' => $user->id,
                'request_id' => request()->header('X-Request-ID', uniqid()),
            ]);

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => "Bid {$action}ed successfully",
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'BID_NOT_FOUND',
                    'message' => 'The specified bid was not found',
                    'request_id' => request()->header('X-Request-ID', uniqid()),
                ],
            ], 404);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_BID_STATE',
                    'message' => $e->getMessage(),
                    'request_id' => request()->header('X-Request-ID', uniqid()),
                ],
            ], 400);
        } catch (\Exception $e) {
            return $this->handleGenericError($e);
        }
    }

    /**
     * Get bid details (staff/admin only)
     */
    public function getBidDetails(Request $request, int $bidId): JsonResponse
    {
        try {
            // Check if user is staff/admin
            $user = $request->user();
            if (! $this->accessControlService->canViewInventory($user)) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to view bid details',
                        'request_id' => request()->header('X-Request-ID', uniqid()),
                    ],
                ], 403);
            }

            $bid = $this->biddingService->getBidDetails($bidId);

            return response()->json([
                'success' => true,
                'data' => $bid,
                'message' => 'Bid details retrieved successfully',
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'BID_NOT_FOUND',
                    'message' => 'The specified bid was not found',
                    'request_id' => request()->header('X-Request-ID', uniqid()),
                ],
            ], 404);
        } catch (\Exception $e) {
            return $this->handleGenericError($e);
        }
    }

    /**
     * Bulk process multiple bids (staff/admin only)
     */
    public function bulkProcessBids(Request $request): JsonResponse
    {
        try {
            // Check if user is staff/admin
            $user = $request->user();
            if (! $this->accessControlService->canViewInventory($user)) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'FORBIDDEN',
                        'message' => 'You do not have permission to manage bids',
                        'request_id' => request()->header('X-Request-ID', uniqid()),
                    ],
                ], 403);
            }

            $validated = $request->validate([
                'bids' => 'required|array|min:1|max:50',
                'bids.*.bid_id' => 'required|integer|exists:bids,id',
                'bids.*.action' => 'required|string|in:accept,reject',
                'bids.*.reason' => 'nullable|string|max:255',
                'admin_notes' => 'nullable|string|max:500',
            ]);

            $results = $this->biddingService->bulkProcessBids(
                $validated['bids'],
                $user->id,
                $validated['admin_notes'] ?? null
            );

            Log::info('Bulk bid processing completed', [
                'processed_count' => count($validated['bids']),
                'admin_id' => $user->id,
                'request_id' => request()->header('X-Request-ID', uniqid()),
            ]);

            return response()->json([
                'success' => true,
                'data' => $results,
                'message' => 'Bids processed successfully',
            ]);

        } catch (\Exception $e) {
            return $this->handleGenericError($e);
        }
    }

    protected function handleGenericError(\Exception $e): JsonResponse
    {
        $requestId = request()->header('X-Request-ID', uniqid());

        Log::error('Bidding error', [
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
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        if ($e instanceof \InvalidArgumentException) {
            return response()->json([
                'error' => [
                    'code' => 'INVALID_ARGUMENT',
                    'message' => $e->getMessage(),
                    'request_id' => $requestId,
                ],
            ], Response::HTTP_BAD_REQUEST);
        }

        return response()->json([
            'error' => [
                'code' => 'INTERNAL_SERVER_ERROR',
                'message' => 'An unexpected error occurred while processing your bid',
                'request_id' => $requestId,
            ],
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}

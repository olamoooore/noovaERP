<?php

namespace Modules\EventManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Modules\EventManagement\Services\OperationalCheckInService;
use Modules\EventManagement\Exceptions\EventManagementException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * OperationalCheckInController
 * 
 * Handles operational check-in API endpoints for staff use including
 * single check-in, bulk check-in, queue management, and capacity monitoring.
 * 
 * Requirements: 15.1, 15.3
 */
class OperationalCheckInController extends Controller
{
    private OperationalCheckInService $checkInService;

    public function __construct(OperationalCheckInService $checkInService)
    {
        $this->checkInService = $checkInService;
    }

    /**
     * Check in a single ticket
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function checkInTicket(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'ticket_code' => 'required|string|max:20',
                'counter_id' => 'required|integer|exists:check_in_counters,id',
                'zone_code' => 'nullable|string|max:20',
                'staff_id' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:500',
            ]);

            $operationalData = [
                'zone_code' => $validated['zone_code'] ?? null,
                'staff_id' => $validated['staff_id'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ];

            $result = $this->checkInService->checkInTicket(
                $validated['ticket_code'],
                $validated['counter_id'],
                $operationalData
            );

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Ticket checked in successfully'
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (EventManagementException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred during check-in'
            ], 500);
        }
    }

    /**
     * Bulk check-in multiple tickets
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function bulkCheckIn(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'ticket_codes' => 'required|array|min:1|max:50',
                'ticket_codes.*' => 'required|string|max:20',
                'counter_id' => 'required|integer|exists:check_in_counters,id',
                'zone_code' => 'nullable|string|max:20',
                'staff_id' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:500',
            ]);

            $operationalData = [
                'zone_code' => $validated['zone_code'] ?? null,
                'staff_id' => $validated['staff_id'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ];

            $result = $this->checkInService->bulkCheckIn(
                $validated['ticket_codes'],
                $validated['counter_id'],
                $operationalData
            );

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => "Bulk check-in completed: {$result['successful']} successful, {$result['failed']} failed"
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred during bulk check-in'
            ], 500);
        }
    }

    /**
     * Check in by booking reference (group check-in)
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function checkInByBooking(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'booking_reference' => 'required|string|max:20',
                'counter_id' => 'required|integer|exists:check_in_counters,id',
                'zone_code' => 'nullable|string|max:20',
                'staff_id' => 'nullable|string|max:255',
                'notes' => 'nullable|string|max:500',
            ]);

            $operationalData = [
                'zone_code' => $validated['zone_code'] ?? null,
                'staff_id' => $validated['staff_id'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ];

            $result = $this->checkInService->checkInByBookingReference(
                $validated['booking_reference'],
                $validated['counter_id'],
                $operationalData
            );

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => "Group check-in completed for booking {$validated['booking_reference']}"
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (EventManagementException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred during group check-in'
            ], 500);
        }
    }

    /**
     * Get check-in statistics for an event
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function getCheckInStatistics(int $eventId): JsonResponse
    {
        try {
            $statistics = $this->checkInService->getCheckInStatistics($eventId);

            return response()->json([
                'success' => true,
                'data' => $statistics,
                'message' => 'Check-in statistics retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving statistics'
            ], 500);
        }
    }

    /**
     * Get queue management data for crowd control
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function getQueueManagement(int $eventId): JsonResponse
    {
        try {
            $queueData = $this->checkInService->getQueueManagementData($eventId);

            return response()->json([
                'success' => true,
                'data' => $queueData,
                'message' => 'Queue management data retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving queue data'
            ], 500);
        }
    }

    /**
     * Monitor zone capacity in real-time
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function monitorZoneCapacity(int $eventId): JsonResponse
    {
        try {
            $capacityData = $this->checkInService->monitorZoneCapacity($eventId);

            return response()->json([
                'success' => true,
                'data' => $capacityData,
                'message' => 'Zone capacity data retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while monitoring zone capacity'
            ], 500);
        }
    }

    /**
     * Emergency check-in override for staff
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function emergencyOverride(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'ticket_code' => 'required|string|max:20',
                'counter_id' => 'required|integer|exists:check_in_counters,id',
                'override_reason' => 'required|string|max:500',
                'zone_code' => 'nullable|string|max:20',
                'staff_id' => 'nullable|string|max:255',
            ]);

            $operationalData = [
                'zone_code' => $validated['zone_code'] ?? null,
                'staff_id' => $validated['staff_id'] ?? null,
            ];

            $result = $this->checkInService->emergencyCheckInOverride(
                $validated['ticket_code'],
                $validated['counter_id'],
                $validated['override_reason'],
                $operationalData
            );

            return response()->json([
                'success' => true,
                'data' => $result,
                'message' => 'Emergency override completed successfully'
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (EventManagementException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred during emergency override'
            ], 500);
        }
    }

    /**
     * Get operational check-in dashboard data
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function getOperationalDashboard(int $eventId): JsonResponse
    {
        try {
            // Combine all operational data for dashboard
            $checkInStats = $this->checkInService->getCheckInStatistics($eventId);
            $queueData = $this->checkInService->getQueueManagementData($eventId);
            $capacityData = $this->checkInService->monitorZoneCapacity($eventId);

            $dashboard = [
                'event_id' => $eventId,
                'check_in_statistics' => $checkInStats,
                'queue_management' => $queueData,
                'zone_capacity' => $capacityData,
                'last_updated' => now(),
            ];

            return response()->json([
                'success' => true,
                'data' => $dashboard,
                'message' => 'Operational dashboard data retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving dashboard data'
            ], 500);
        }
    }
}
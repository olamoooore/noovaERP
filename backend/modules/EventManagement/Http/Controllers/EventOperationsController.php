<?php

namespace Modules\EventManagement\Http\Controllers;

use Modules\EventManagement\Services\EventOperationsService;
use Modules\EventManagement\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

/**
 * EventOperationsController
 * 
 * Handles live event management and operations endpoints.
 * Provides real-time dashboard data, zone management, queue monitoring,
 * and incident logging for operational staff.
 * 
 * Requirements: 15.1, 15.4, 15.5
 */
class EventOperationsController extends Controller
{
    private EventOperationsService $operationsService;
    private AuditService $auditService;

    public function __construct(
        EventOperationsService $operationsService,
        AuditService $auditService
    ) {
        $this->operationsService = $operationsService;
        $this->auditService = $auditService;

        // Apply middleware for staff access
        $this->middleware(['auth:sanctum', 'rbac.event_management']);
        $this->middleware('rate_limit:operations')->only([
            'getLiveDashboard',
            'updateZoneOccupancy',
            'updateCounterQueue'
        ]);
    }

    /**
     * Get live event operations dashboard
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function getLiveDashboard(int $eventId): JsonResponse
    {
        try {
            $dashboard = $this->operationsService->getLiveEventDashboard($eventId);

            return response()->json([
                'success' => true,
                'data' => $dashboard,
                'message' => 'Live dashboard data retrieved successfully'
            ]);

        } catch (\Exception $e) {
            try {
                $this->auditService->logActivity(
                    'operations_error',
                    'event',
                    $eventId,
                    ['error' => $e->getMessage()]
                );
            } catch (\Throwable $auditEx) {
                // Never let audit failure mask the real error
            }

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve live dashboard data',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get real-time check-in metrics
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function getCheckInMetrics(int $eventId): JsonResponse
    {
        try {
            $tenantId = tenant('id');
            $metrics = $this->operationsService->getCheckInMetrics($eventId, $tenantId);

            return response()->json([
                'success' => true,
                'data' => $metrics,
                'message' => 'Check-in metrics retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve check-in metrics',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get zone status and occupancy
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function getZoneStatus(int $eventId): JsonResponse
    {
        try {
            $tenantId = tenant('id');
            $zones = $this->operationsService->getZoneStatus($eventId, $tenantId);

            return response()->json([
                'success' => true,
                'data' => $zones,
                'message' => 'Zone status retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve zone status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Update zone occupancy
     * 
     * @param Request $request
     * @param int $zoneId
     * @return JsonResponse
     */
    public function updateZoneOccupancy(Request $request, int $zoneId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'occupancy_change' => 'required|integer|between:-100,100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $occupancyChange = $request->input('occupancy_change');
            $updatedZone = $this->operationsService->updateZoneOccupancy($zoneId, $occupancyChange);

            return response()->json([
                'success' => true,
                'data' => $updatedZone,
                'message' => 'Zone occupancy updated successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update zone occupancy',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get queue status across all counters
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function getQueueStatus(int $eventId): JsonResponse
    {
        try {
            $tenantId = tenant('id');
            $queueStatus = $this->operationsService->getQueueStatus($eventId, $tenantId);

            return response()->json([
                'success' => true,
                'data' => $queueStatus,
                'message' => 'Queue status retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve queue status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Update check-in counter queue
     * 
     * @param Request $request
     * @param int $counterId
     * @return JsonResponse
     */
    public function updateCounterQueue(Request $request, int $counterId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'queue_change' => 'required|integer|between:-50,50',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $queueChange = $request->input('queue_change');
            $updatedCounter = $this->operationsService->updateCounterQueue($counterId, $queueChange);

            return response()->json([
                'success' => true,
                'data' => $updatedCounter,
                'message' => 'Counter queue updated successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update counter queue',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Log an operational incident
     * 
     * @param Request $request
     * @param int $eventId
     * @return JsonResponse
     */
    public function logIncident(Request $request, int $eventId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'incident_type' => 'required|in:security,medical,technical,crowd_control,weather,equipment_failure,staff_issue,customer_complaint,administrative_override,emergency,other',
                'severity' => 'required|in:low,medium,high,critical',
                'priority' => 'sometimes|in:low,normal,high,urgent',
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'initial_assessment' => 'sometimes|string',
                'zone_id' => 'sometimes|integer|exists:operational_zones,id',
                'counter_id' => 'sometimes|integer|exists:check_in_counters,id',
                'affected_areas' => 'sometimes|array',
                'people_affected' => 'sometimes|integer|min:0',
                'is_administrative_override' => 'sometimes|boolean',
                'override_type' => 'required_if:is_administrative_override,true|string',
                'override_justification' => 'required_if:is_administrative_override,true|string',
                'authorized_by' => 'required_if:is_administrative_override,true|string',
                'override_details' => 'sometimes|array',
                'metadata' => 'sometimes|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $incidentData = $validator->validated();
            $incidentData['reported_by'] = auth()->user()->id;

            $incidentId = $this->operationsService->logIncident($eventId, $incidentData);

            return response()->json([
                'success' => true,
                'data' => ['incident_id' => $incidentId],
                'message' => 'Incident logged successfully'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to log incident',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get recent incidents for an event
     * 
     * @param int $eventId
     * @param Request $request
     * @return JsonResponse
     */
    public function getRecentIncidents(int $eventId, Request $request): JsonResponse
    {
        try {
            $limit = $request->input('limit', 10);
            $tenantId = tenant('id');

            $incidents = $this->operationsService->getRecentIncidents($eventId, $tenantId, $limit);

            return response()->json([
                'success' => true,
                'data' => $incidents,
                'message' => 'Recent incidents retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve recent incidents',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Update event operational status
     * 
     * @param Request $request
     * @param int $eventId
     * @return JsonResponse
     */
    public function updateEventStatus(Request $request, int $eventId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'status_type' => 'required|in:event_status,checkin_status,capacity_status,zone_status,security_status,technical_status,weather_status,emergency_status',
                'new_status' => 'required|string|max:100',
                'description' => 'sometimes|string',
                'reason' => 'sometimes|string',
                'impact_level' => 'sometimes|in:none,low,medium,high,critical',
                'attendees_affected' => 'sometimes|integer|min:0',
                'zones_affected' => 'sometimes|array',
                'public_announcement_made' => 'sometimes|boolean',
                'announcement_text' => 'required_if:public_announcement_made,true|string',
                'notification_channels' => 'sometimes|array',
                'requires_action' => 'sometimes|boolean',
                'recommended_actions' => 'required_if:requires_action,true|string',
                'escalation_triggered' => 'sometimes|boolean',
                'metadata' => 'sometimes|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $statusData = $validator->validated();
            $statusData['changed_by'] = auth()->user()->id;

            $statusLogId = $this->operationsService->updateEventStatus(
                $eventId,
                $statusData['status_type'],
                $statusData['new_status'],
                $statusData
            );

            return response()->json([
                'success' => true,
                'data' => ['status_log_id' => $statusLogId],
                'message' => 'Event status updated successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update event status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get current event operational status
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function getCurrentStatus(int $eventId): JsonResponse
    {
        try {
            $tenantId = tenant('id');
            $status = $this->operationsService->getCurrentEventStatus($eventId, $tenantId);

            return response()->json([
                'success' => true,
                'data' => $status,
                'message' => 'Current event status retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve current event status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Generate operational report for an event
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function generateOperationalReport(int $eventId): JsonResponse
    {
        try {
            $report = $this->operationsService->generateOperationalReport($eventId);

            try {
                $this->auditService->logActivity(
                    'operations_report',
                    'event',
                    $eventId
                );
            } catch (\Throwable $auditEx) {
                // Audit failure must not break the response
            }

            return response()->json([
                'success' => true,
                'data' => $report,
                'message' => 'Operational report generated successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate operational report',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get staff status for an event
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function getStaffStatus(int $eventId): JsonResponse
    {
        try {
            $tenantId = tenant('id');
            $staffStatus = $this->operationsService->getStaffStatus($eventId, $tenantId);

            return response()->json([
                'success' => true,
                'data' => $staffStatus,
                'message' => 'Staff status retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve staff status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get operational dashboard summary from view
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function getDashboardSummary(int $eventId): JsonResponse
    {
        try {
            $tenantId = tenant('id');

            $summary = \DB::table('operational_dashboard')
                ->where('event_id', $eventId)
                ->where('tenant_id', $tenantId)
                ->first();

            if (!$summary) {
                return response()->json([
                    'success' => false,
                    'message' => 'Event not found or no operational data available'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $summary,
                'message' => 'Dashboard summary retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve dashboard summary',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
}
<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\EventManagement\Models\IncidentLog;
use Modules\EventManagement\Services\AuditService;

/**
 * IncidentController
 *
 * Handles incident tracking, escalation workflows, and administrative
 * override management for event operations.
 *
 * Requirements: 15.4, 15.5
 */
class IncidentController extends Controller
{
    private AuditService $auditService;

    public function __construct(AuditService $auditService)
    {
        $this->auditService = $auditService;

        // Apply middleware for staff access
        $this->middleware(['auth:sanctum', 'rbac.event_management']);
        $this->middleware('rate_limit:incident_management')->only([
            'createIncident',
            'updateIncident',
            'resolveIncident',
        ]);
    }

    /**
     * Get incidents for an event
     */
    public function getEventIncidents(int $eventId, Request $request): JsonResponse
    {
        try {
            $tenantId = tenant('id');

            $query = IncidentLog::forEvent($eventId)
                ->forTenant($tenantId)
                ->orderBy('reported_at', 'desc');

            // Apply filters
            if ($request->has('status')) {
                $query->byStatus($request->input('status'));
            }

            if ($request->has('incident_type')) {
                $query->byType($request->input('incident_type'));
            }

            if ($request->has('severity')) {
                $query->bySeverity($request->input('severity'));
            }

            if ($request->has('priority')) {
                $query->byPriority($request->input('priority'));
            }

            if ($request->has('open_only') && $request->boolean('open_only')) {
                $query->open();
            }

            if ($request->has('critical_only') && $request->boolean('critical_only')) {
                $query->critical();
            }

            $perPage = $request->input('per_page', 15);
            $incidents = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $incidents,
                'message' => 'Event incidents retrieved successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve event incidents',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get incident by ID
     */
    public function getIncident(int $incidentId): JsonResponse
    {
        try {
            $tenantId = tenant('id');

            $incident = IncidentLog::forTenant($tenantId)
                ->with(['event', 'operationalZone', 'checkInCounter'])
                ->find($incidentId);

            if (! $incident) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incident not found',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $incident,
                'message' => 'Incident retrieved successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve incident',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Create a new incident
     */
    public function createIncident(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'event_id' => 'required|integer|exists:events,id',
                'zone_id' => 'sometimes|integer|exists:operational_zones,id',
                'counter_id' => 'sometimes|integer|exists:check_in_counters,id',
                'incident_type' => 'required|in:security,medical,technical,crowd_control,weather,equipment_failure,staff_issue,customer_complaint,administrative_override,emergency,other',
                'severity' => 'required|in:low,medium,high,critical',
                'priority' => 'sometimes|in:low,normal,high,urgent',
                'title' => 'required|string|max:255',
                'description' => 'required|string',
                'initial_assessment' => 'sometimes|string',
                'affected_areas' => 'sometimes|array',
                'people_affected' => 'sometimes|integer|min:0',
                'is_administrative_override' => 'sometimes|boolean',
                'override_type' => 'required_if:is_administrative_override,true|string',
                'override_justification' => 'required_if:is_administrative_override,true|string',
                'authorized_by' => 'required_if:is_administrative_override,true|string',
                'override_details' => 'sometimes|array',
                'requires_follow_up' => 'sometimes|boolean',
                'follow_up_date' => 'required_if:requires_follow_up,true|date|after:today',
                'metadata' => 'sometimes|array',
                'attachments' => 'sometimes|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $incidentData = $validator->validated();
            $incidentData['tenant_id'] = tenant('id');
            $incidentData['reported_by'] = auth()->user()->id;
            $incidentData['reported_at'] = now();
            $incidentData['status'] = 'open';

            $incident = IncidentLog::create($incidentData);

            $this->auditService->logActivity(
                'incident_created',
                'New incident created',
                [
                    'incident_id' => $incident->id,
                    'event_id' => $incident->event_id,
                    'incident_type' => $incident->incident_type,
                    'severity' => $incident->severity,
                ]
            );

            return response()->json([
                'success' => true,
                'data' => $incident,
                'message' => 'Incident created successfully',
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create incident',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update an incident
     */
    public function updateIncident(Request $request, int $incidentId): JsonResponse
    {
        try {
            $tenantId = tenant('id');

            $incident = IncidentLog::forTenant($tenantId)->find($incidentId);

            if (! $incident) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incident not found',
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'severity' => 'sometimes|in:low,medium,high,critical',
                'priority' => 'sometimes|in:low,normal,high,urgent',
                'title' => 'sometimes|string|max:255',
                'description' => 'sometimes|string',
                'initial_assessment' => 'sometimes|string',
                'affected_areas' => 'sometimes|array',
                'people_affected' => 'sometimes|integer|min:0',
                'assigned_to' => 'sometimes|string',
                'resolution_notes' => 'sometimes|string',
                'actions_taken' => 'sometimes|string',
                'requires_follow_up' => 'sometimes|boolean',
                'follow_up_date' => 'required_if:requires_follow_up,true|date|after:today',
                'prevention_measures' => 'sometimes|string',
                'policy_update_required' => 'sometimes|boolean',
                'metadata' => 'sometimes|array',
                'attachments' => 'sometimes|array',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $updateData = $validator->validated();
            $updateData['updated_by'] = auth()->user()->id;

            $incident->update($updateData);

            $this->auditService->logActivity(
                'incident_updated',
                'Incident updated',
                [
                    'incident_id' => $incident->id,
                    'updates' => array_keys($updateData),
                ]
            );

            return response()->json([
                'success' => true,
                'data' => $incident->fresh(),
                'message' => 'Incident updated successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update incident',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Acknowledge an incident
     */
    public function acknowledgeIncident(Request $request, int $incidentId): JsonResponse
    {
        try {
            $tenantId = tenant('id');

            $incident = IncidentLog::forTenant($tenantId)->find($incidentId);

            if (! $incident) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incident not found',
                ], 404);
            }

            if ($incident->status !== 'open') {
                return response()->json([
                    'success' => false,
                    'message' => 'Incident has already been acknowledged',
                ], 422);
            }

            $incident->acknowledge(auth()->user()->id);

            $this->auditService->logActivity(
                'incident_acknowledged',
                'Incident acknowledged',
                ['incident_id' => $incident->id]
            );

            return response()->json([
                'success' => true,
                'data' => $incident->fresh(),
                'message' => 'Incident acknowledged successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to acknowledge incident',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Assign incident to staff member
     */
    public function assignIncident(Request $request, int $incidentId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'assigned_to' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $tenantId = tenant('id');

            $incident = IncidentLog::forTenant($tenantId)->find($incidentId);

            if (! $incident) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incident not found',
                ], 404);
            }

            $assignedTo = $request->input('assigned_to');
            $incident->assignTo($assignedTo);

            $this->auditService->logActivity(
                'incident_assigned',
                'Incident assigned',
                [
                    'incident_id' => $incident->id,
                    'assigned_to' => $assignedTo,
                ]
            );

            return response()->json([
                'success' => true,
                'data' => $incident->fresh(),
                'message' => 'Incident assigned successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign incident',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Mark incident as in progress
     */
    public function markInProgress(int $incidentId): JsonResponse
    {
        try {
            $tenantId = tenant('id');

            $incident = IncidentLog::forTenant($tenantId)->find($incidentId);

            if (! $incident) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incident not found',
                ], 404);
            }

            if (! in_array($incident->status, ['open', 'acknowledged'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incident cannot be marked as in progress from current status',
                ], 422);
            }

            $incident->markInProgress();

            $this->auditService->logActivity(
                'incident_in_progress',
                'Incident marked as in progress',
                ['incident_id' => $incident->id]
            );

            return response()->json([
                'success' => true,
                'data' => $incident->fresh(),
                'message' => 'Incident marked as in progress',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to mark incident as in progress',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Resolve an incident
     */
    public function resolveIncident(Request $request, int $incidentId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'resolution_notes' => 'required|string',
                'actions_taken' => 'sometimes|string',
                'prevention_measures' => 'sometimes|string',
                'policy_update_required' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $tenantId = tenant('id');

            $incident = IncidentLog::forTenant($tenantId)->find($incidentId);

            if (! $incident) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incident not found',
                ], 404);
            }

            if ($incident->isResolved()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incident is already resolved',
                ], 422);
            }

            $resolutionNotes = $request->input('resolution_notes');
            $actionsTaken = $request->input('actions_taken');

            $incident->resolve(auth()->user()->id, $resolutionNotes, $actionsTaken);

            // Update additional fields if provided
            if ($request->has('prevention_measures')) {
                $incident->prevention_measures = $request->input('prevention_measures');
            }

            if ($request->has('policy_update_required')) {
                $incident->policy_update_required = $request->boolean('policy_update_required');
            }

            $incident->save();

            $this->auditService->logActivity(
                'incident_resolved',
                'Incident resolved',
                [
                    'incident_id' => $incident->id,
                    'resolved_by' => auth()->user()->id,
                ]
            );

            return response()->json([
                'success' => true,
                'data' => $incident->fresh(),
                'message' => 'Incident resolved successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to resolve incident',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Escalate an incident
     */
    public function escalateIncident(Request $request, int $incidentId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'escalation_reason' => 'required|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $tenantId = tenant('id');

            $incident = IncidentLog::forTenant($tenantId)->find($incidentId);

            if (! $incident) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incident not found',
                ], 404);
            }

            if ($incident->isEscalated()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incident has already been escalated',
                ], 422);
            }

            $escalationReason = $request->input('escalation_reason');
            $incident->escalate($escalationReason);

            $this->auditService->logActivity(
                'incident_escalated',
                'Incident escalated to management',
                [
                    'incident_id' => $incident->id,
                    'escalation_reason' => $escalationReason,
                ]
            );

            return response()->json([
                'success' => true,
                'data' => $incident->fresh(),
                'message' => 'Incident escalated successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to escalate incident',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Close an incident
     */
    public function closeIncident(int $incidentId): JsonResponse
    {
        try {
            $tenantId = tenant('id');

            $incident = IncidentLog::forTenant($tenantId)->find($incidentId);

            if (! $incident) {
                return response()->json([
                    'success' => false,
                    'message' => 'Incident not found',
                ], 404);
            }

            if ($incident->status !== 'resolved') {
                return response()->json([
                    'success' => false,
                    'message' => 'Incident must be resolved before it can be closed',
                ], 422);
            }

            $incident->close();

            $this->auditService->logActivity(
                'incident_closed',
                'Incident closed',
                ['incident_id' => $incident->id]
            );

            return response()->json([
                'success' => true,
                'data' => $incident->fresh(),
                'message' => 'Incident closed successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to close incident',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get incident statistics for an event
     */
    public function getIncidentStatistics(int $eventId): JsonResponse
    {
        try {
            $tenantId = tenant('id');

            $stats = DB::table('incident_logs')
                ->where('event_id', $eventId)
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->select([
                    DB::raw('COUNT(*) as total_incidents'),
                    DB::raw('COUNT(CASE WHEN status IN ("open", "acknowledged", "in_progress") THEN 1 END) as open_incidents'),
                    DB::raw('COUNT(CASE WHEN status IN ("resolved", "closed") THEN 1 END) as resolved_incidents'),
                    DB::raw('COUNT(CASE WHEN severity = "critical" THEN 1 END) as critical_incidents'),
                    DB::raw('COUNT(CASE WHEN severity = "high" THEN 1 END) as high_incidents'),
                    DB::raw('COUNT(CASE WHEN is_administrative_override = 1 THEN 1 END) as admin_overrides'),
                    DB::raw('COUNT(CASE WHEN escalated_to_management = 1 THEN 1 END) as escalated_incidents'),
                    DB::raw('AVG(CASE WHEN resolved_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, reported_at, resolved_at) END) as avg_resolution_time_minutes'),
                ])
                ->first();

            // Get incident breakdown by type
            $typeBreakdown = DB::table('incident_logs')
                ->where('event_id', $eventId)
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->select([
                    'incident_type',
                    DB::raw('COUNT(*) as count'),
                    DB::raw('COUNT(CASE WHEN status IN ("resolved", "closed") THEN 1 END) as resolved_count'),
                ])
                ->groupBy('incident_type')
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => $stats,
                    'type_breakdown' => $typeBreakdown,
                ],
                'message' => 'Incident statistics retrieved successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve incident statistics',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}

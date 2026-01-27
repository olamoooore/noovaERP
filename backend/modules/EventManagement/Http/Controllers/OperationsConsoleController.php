<?php

namespace Modules\EventManagement\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\EventManagement\Services\OfflineOperationsService;
use Modules\EventManagement\Services\StaffOverrideService;
use Illuminate\Support\Facades\Validator;
use Exception;

/**
 * OperationsConsoleController - Task 9.4
 * 
 * Enhanced operations console UI controller with offline mode indicators,
 * conflict resolution interface, override reason capture, and staff scoping.
 * 
 * Requirements: 25.4, 25.5
 */
class OperationsConsoleController extends Controller
{
    private OfflineOperationsService $offlineOpsService;
    private StaffOverrideService $overrideService;

    public function __construct(
        OfflineOperationsService $offlineOpsService,
        StaffOverrideService $overrideService
    ) {
        $this->offlineOpsService = $offlineOpsService;
        $this->overrideService = $overrideService;
    }

    /**
     * Get operations console dashboard data
     */
    public function getDashboard(Request $request): JsonResponse
    {
        try {
            $staffId = $request->user()->id;
            $eventId = $request->input('event_id');
            $deviceId = $request->input('device_id');

            // Get device offline capability status
            $deviceStatus = $this->offlineOpsService->getOfflineCapabilityStatus($deviceId);

            // Get pending conflicts for resolution
            $pendingConflicts = $this->offlineOpsService->getPendingConflicts([
                'event_id' => $eventId,
                'requires_manual' => true
            ]);

            // Get pending approvals for supervisor
            $pendingApprovals = $this->overrideService->getPendingApprovals($staffId, [
                'event_id' => $eventId
            ]);

            // Get staff assignment details
            $staffAssignment = $this->getStaffAssignment($staffId, $eventId);

            // Get recent override patterns
            $overridePatterns = $this->overrideService->detectOverridePatterns($staffId);

            return response()->json([
                'success' => true,
                'dashboard' => [
                    'device_status' => $deviceStatus,
                    'staff_assignment' => $staffAssignment,
                    'pending_conflicts' => $pendingConflicts,
                    'pending_approvals' => $pendingApprovals,
                    'override_patterns' => $overridePatterns,
                    'offline_mode' => [
                        'enabled' => $deviceStatus['capable'],
                        'is_offline' => $deviceStatus['is_offline'],
                        'pending_operations' => $deviceStatus['pending_operations'],
                        'sync_health' => $deviceStatus['sync_health']
                    ]
                ]
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to load dashboard',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Queue offline check-in operation
     */
    public function queueOfflineCheckin(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ticket_id' => 'required|integer',
            'event_id' => 'required|integer',
            'device_id' => 'required|string',
            'checkin_timestamp' => 'nullable|date',
            'additional_data' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $checkinData = [
                'ticket_id' => $request->input('ticket_id'),
                'staff_id' => $request->user()->id,
                'event_id' => $request->input('event_id'),
                'device_id' => $request->input('device_id'),
                'checkin_timestamp' => $request->input('checkin_timestamp', now()),
                'additional_data' => $request->input('additional_data', [])
            ];

            $result = $this->offlineOpsService->queueOfflineCheckin($checkinData);

            return response()->json($result, $result['success'] ? 200 : 400);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to queue check-in',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Synchronize offline operations
     */
    public function synchronizeOperations(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'device_id' => 'required|string',
            'sync_type' => 'nullable|in:full_sync,incremental_sync,emergency_sync',
            'event_id' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $options = [
                'sync_type' => $request->input('sync_type', 'incremental_sync'),
                'event_id' => $request->input('event_id')
            ];

            $result = $this->offlineOpsService->synchronizeOfflineOperations(
                $request->input('device_id'),
                $options
            );

            return response()->json($result);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Synchronization failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get conflict resolution interface data
     */
    public function getConflictResolution(Request $request, string $conflictId): JsonResponse
    {
        try {
            // Get conflict details
            $conflicts = $this->offlineOpsService->getPendingConflicts([
                'conflict_id' => $conflictId
            ]);

            if (empty($conflicts['conflicts'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Conflict not found'
                ], 404);
            }

            $conflict = $conflicts['conflicts'][0];

            // Get resolution options based on conflict type
            $resolutionOptions = $this->getConflictResolutionOptions($conflict);

            return response()->json([
                'success' => true,
                'conflict' => $conflict,
                'resolution_options' => $resolutionOptions,
                'recommended_action' => $this->getRecommendedResolution($conflict)
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to load conflict resolution',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resolve synchronization conflict
     */
    public function resolveConflict(Request $request, string $conflictId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'resolution_strategy' => 'required|in:offline_wins,online_wins,merge_data,manual_resolution,create_duplicate,reject_offline',
            'resolution_notes' => 'nullable|string|max:1000',
            'resolution_data' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $resolutionData = [
                'notes' => $request->input('resolution_notes'),
                'data' => $request->input('resolution_data', [])
            ];

            $result = $this->offlineOpsService->resolveConflict(
                $conflictId,
                $request->input('resolution_strategy'),
                $resolutionData,
                $request->user()->id
            );

            return response()->json($result, $result['success'] ? 200 : 400);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to resolve conflict',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create staff override with reason capture
     */
    public function createOverride(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'override_type' => 'required|string',
            'target_entity_type' => 'required|string',
            'target_entity_id' => 'required|integer',
            'override_reason' => 'required|string|min:10|max:1000',
            'override_category' => 'required|in:technical_issue,customer_service,emergency_situation,system_error,policy_exception,security_concern,other',
            'event_id' => 'nullable|integer',
            'is_emergency' => 'nullable|boolean',
            'override_data' => 'required|array',
            'context_data' => 'nullable|array'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $overrideData = [
                'staff_id' => $request->user()->id,
                'override_type' => $request->input('override_type'),
                'target_entity_type' => $request->input('target_entity_type'),
                'target_entity_id' => $request->input('target_entity_id'),
                'override_reason' => $request->input('override_reason'),
                'override_category' => $request->input('override_category'),
                'event_id' => $request->input('event_id'),
                'is_emergency' => $request->input('is_emergency', false),
                'override_data' => $request->input('override_data'),
                'context_data' => $request->input('context_data', []),
                'device_id' => $request->input('device_id'),
                'ip_address' => $request->ip()
            ];

            $result = $this->overrideService->createOverride($overrideData);

            return response()->json($result, $result['success'] ? 201 : 400);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to create override',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process supervisor approval
     */
    public function processSupervisorApproval(Request $request, int $overrideId): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'decision' => 'required|in:approved,rejected',
            'approval_notes' => 'nullable|string|max:1000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $result = $this->overrideService->processSupervisorApproval(
                $overrideId,
                $request->user()->id,
                $request->input('decision'),
                $request->input('approval_notes', '')
            );

            return response()->json($result, $result['success'] ? 200 : 400);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to process approval',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get override audit trail
     */
    public function getOverrideAuditTrail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'entity_type' => 'required|string',
            'entity_id' => 'required|integer',
            'staff_id' => 'nullable|integer',
            'override_type' => 'nullable|string',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'limit' => 'nullable|integer|min:1|max:100'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $options = array_filter([
                'staff_id' => $request->input('staff_id'),
                'override_type' => $request->input('override_type'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'limit' => $request->input('limit', 50)
            ]);

            $result = $this->overrideService->getOverrideAuditTrail(
                $request->input('entity_type'),
                $request->input('entity_id'),
                $options
            );

            return response()->json($result);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get audit trail',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get staff zone assignments and permissions
     */
    public function getStaffZoneAssignments(Request $request): JsonResponse
    {
        try {
            $staffId = $request->user()->id;
            $eventId = $request->input('event_id');

            $assignments = $this->getStaffZonePermissions($staffId, $eventId);

            return response()->json([
                'success' => true,
                'staff_id' => $staffId,
                'event_id' => $eventId,
                'assignments' => $assignments
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to get zone assignments',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get override analytics for operations monitoring
     */
    public function getOverrideAnalytics(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'event_id' => 'nullable|integer',
            'staff_id' => 'nullable|integer'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $filters = array_filter([
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'event_id' => $request->input('event_id'),
                'staff_id' => $request->input('staff_id')
            ]);

            $result = $this->overrideService->generateOverrideAnalytics($filters);

            return response()->json($result);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics',
                'message' => $e->getMessage()
            ], 500);
        }
    }
    // Private helper methods

    private function getStaffAssignment(int $staffId, ?int $eventId): array
    {
        $query = \DB::table('staff_assignments')
            ->where('staff_id', $staffId)
            ->where('assignment_status', 'active');

        if ($eventId) {
            $query->where('event_id', $eventId);
        }

        $assignment = $query->first();

        if (!$assignment) {
            return [
                'assigned' => false,
                'message' => 'No active assignment found'
            ];
        }

        return [
            'assigned' => true,
            'assignment_role' => $assignment->assignment_role,
            'zone_permissions' => json_decode($assignment->zone_permissions, true),
            'operation_permissions' => json_decode($assignment->operation_permissions, true),
            'can_override' => $assignment->can_override,
            'can_approve_overrides' => $assignment->can_approve_overrides,
            'is_supervisor' => $assignment->is_supervisor,
            'assignment_start' => $assignment->assignment_start,
            'assignment_end' => $assignment->assignment_end
        ];
    }

    private function getConflictResolutionOptions(array $conflict): array
    {
        $options = [];

        switch ($conflict['type']) {
            case 'duplicate_checkin':
                $options = [
                    'offline_wins' => [
                        'label' => 'Use Offline Check-in',
                        'description' => 'Accept the offline check-in time and update online record',
                        'risk_level' => 'low'
                    ],
                    'online_wins' => [
                        'label' => 'Keep Online Check-in',
                        'description' => 'Reject offline check-in, keep existing online record',
                        'risk_level' => 'low'
                    ],
                    'manual_resolution' => [
                        'label' => 'Manual Review',
                        'description' => 'Escalate to supervisor for manual decision',
                        'risk_level' => 'medium'
                    ]
                ];
                break;

            case 'ticket_not_found':
                $options = [
                    'create_duplicate' => [
                        'label' => 'Create Missing Ticket',
                        'description' => 'Create ticket record based on offline data',
                        'risk_level' => 'high'
                    ],
                    'reject_offline' => [
                        'label' => 'Reject Offline Operation',
                        'description' => 'Discard offline check-in due to invalid ticket',
                        'risk_level' => 'low'
                    ]
                ];
                break;

            case 'timestamp_mismatch':
                $options = [
                    'offline_wins' => [
                        'label' => 'Use Offline Timestamp',
                        'description' => 'Accept offline timestamp as authoritative',
                        'risk_level' => 'medium'
                    ],
                    'online_wins' => [
                        'label' => 'Use Online Timestamp',
                        'description' => 'Use online system timestamp',
                        'risk_level' => 'medium'
                    ],
                    'merge_data' => [
                        'label' => 'Merge Timestamps',
                        'description' => 'Create composite record with both timestamps',
                        'risk_level' => 'low'
                    ]
                ];
                break;

            default:
                $options = [
                    'manual_resolution' => [
                        'label' => 'Manual Resolution Required',
                        'description' => 'This conflict type requires supervisor review',
                        'risk_level' => 'high'
                    ]
                ];
        }

        return $options;
    }

    private function getRecommendedResolution(array $conflict): array
    {
        $recommendations = [
            'duplicate_checkin' => [
                'strategy' => 'offline_wins',
                'reason' => 'Offline check-ins typically have more accurate timestamps',
                'confidence' => 'medium'
            ],
            'ticket_not_found' => [
                'strategy' => 'reject_offline',
                'reason' => 'Invalid tickets should not be processed',
                'confidence' => 'high'
            ],
            'timestamp_mismatch' => [
                'strategy' => 'merge_data',
                'reason' => 'Preserves both timestamps for audit purposes',
                'confidence' => 'high'
            ],
            'data_integrity_violation' => [
                'strategy' => 'manual_resolution',
                'reason' => 'Data integrity issues require supervisor review',
                'confidence' => 'high'
            ]
        ];

        return $recommendations[$conflict['type']] ?? [
            'strategy' => 'manual_resolution',
            'reason' => 'Unknown conflict type requires manual review',
            'confidence' => 'low'
        ];
    }

    private function getStaffZonePermissions(int $staffId, ?int $eventId): array
    {
        // Get staff assignments
        $query = \DB::table('staff_assignments as sa')
            ->leftJoin('event_zones as ez', function ($join) {
                $join->on('sa.event_id', '=', 'ez.event_id');
            })
            ->where('sa.staff_id', $staffId)
            ->where('sa.assignment_status', 'active');

        if ($eventId) {
            $query->where('sa.event_id', $eventId);
        }

        $assignments = $query->get();

        $zonePermissions = [];
        foreach ($assignments as $assignment) {
            $zonePerms = json_decode($assignment->zone_permissions, true) ?? [];
            $operationPerms = json_decode($assignment->operation_permissions, true) ?? [];

            foreach ($zonePerms as $zoneCode) {
                if (!isset($zonePermissions[$zoneCode])) {
                    $zonePermissions[$zoneCode] = [
                        'zone_code' => $zoneCode,
                        'zone_name' => $assignment->zone_name ?? $zoneCode,
                        'zone_type' => $assignment->zone_type ?? 'general',
                        'operations' => [],
                        'requires_supervisor' => $assignment->requires_supervisor ?? false
                    ];
                }

                $zonePermissions[$zoneCode]['operations'] = array_unique(
                    array_merge($zonePermissions[$zoneCode]['operations'], $operationPerms)
                );
            }
        }

        return array_values($zonePermissions);
    }
}
<?php

namespace Modules\EventManagement\Http\Controllers;

use Modules\EventManagement\Services\StaffManagementService;
use Modules\EventManagement\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

/**
 * StaffController
 * 
 * Handles staff assignment, monitoring, and performance tracking endpoints.
 * Provides staff management capabilities for event operations with
 * role-based access control and comprehensive audit logging.
 * 
 * Requirements: 15.2, 15.5
 */
class StaffController extends Controller
{
    private StaffManagementService $staffService;
    private AuditService $auditService;

    public function __construct(
        StaffManagementService $staffService,
        AuditService $auditService
    ) {
        $this->staffService = $staffService;
        $this->auditService = $auditService;

        // Apply middleware for admin/manager access
        $this->middleware(['auth:sanctum', 'rbac.event_management']);
        $this->middleware('rate_limit:staff_management')->only([
            'assignStaff',
            'updateAssignment',
            'removeAssignment'
        ]);
    }

    /**
     * Assign staff member to an event
     * 
     * @param Request $request
     * @param int $eventId
     * @return JsonResponse
     */
    public function assignStaff(Request $request, int $eventId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'staff_user_id' => 'required|string',
                'role' => 'required|in:manager,coordinator,security,usher,technical,medical,customer_service,merchandise,cleaning,volunteer',
                'zone' => 'sometimes|string|max:20',
                'responsibilities' => 'sometimes|string',
                'permissions' => 'sometimes|array',
                'shift_start' => 'sometimes|date',
                'shift_end' => 'sometimes|date|after:shift_start',
                'is_lead' => 'sometimes|boolean',
                'can_override' => 'sometimes|boolean',
                'contact_number' => 'sometimes|string|max:20',
                'emergency_contact' => 'sometimes|string|max:20',
                'special_instructions' => 'sometimes|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $assignmentData = $validator->validated();
            $assignmentId = $this->staffService->assignStaffToEvent($eventId, $assignmentData['staff_user_id'], $assignmentData);

            return response()->json([
                'success' => true,
                'data' => ['assignment_id' => $assignmentId],
                'message' => 'Staff member assigned successfully'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to assign staff member',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Update staff assignment
     * 
     * @param Request $request
     * @param int $assignmentId
     * @return JsonResponse
     */
    public function updateAssignment(Request $request, int $assignmentId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'zone' => 'sometimes|string|max:20',
                'responsibilities' => 'sometimes|string',
                'permissions' => 'sometimes|array',
                'shift_start' => 'sometimes|date',
                'shift_end' => 'sometimes|date|after:shift_start',
                'is_lead' => 'sometimes|boolean',
                'can_override' => 'sometimes|boolean',
                'contact_number' => 'sometimes|string|max:20',
                'emergency_contact' => 'sometimes|string|max:20',
                'special_instructions' => 'sometimes|string',
                'performance_rating' => 'sometimes|integer|between:1,5',
                'performance_notes' => 'sometimes|string',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $updateData = $validator->validated();
            $success = $this->staffService->updateStaffAssignment($assignmentId, $updateData);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Staff assignment updated successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to update staff assignment'
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update staff assignment',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Check staff member in/out
     * 
     * @param Request $request
     * @param int $assignmentId
     * @return JsonResponse
     */
    public function checkInOut(Request $request, int $assignmentId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'action' => 'required|in:check_in,check_out',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $action = $request->input('action');
            $success = $this->staffService->checkInOutStaff($assignmentId, $action);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => "Staff member {$action} successful"
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => "Failed to {$action} staff member"
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process check-in/out',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Update staff member status
     * 
     * @param Request $request
     * @param int $assignmentId
     * @return JsonResponse
     */
    public function updateStatus(Request $request, int $assignmentId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:assigned,checked_in,active,break,checked_out,absent',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $status = $request->input('status');
            $success = $this->staffService->updateStaffStatus($assignmentId, $status);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Staff status updated successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to update staff status'
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update staff status',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get staff assignments for an event
     * 
     * @param int $eventId
     * @param Request $request
     * @return JsonResponse
     */
    public function getEventStaffAssignments(int $eventId, Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['role', 'zone', 'status', 'is_lead']);
            $assignments = $this->staffService->getEventStaffAssignments($eventId, $filters);

            return response()->json([
                'success' => true,
                'data' => $assignments,
                'message' => 'Staff assignments retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve staff assignments',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get staff member's assignment history
     * 
     * @param string $staffUserId
     * @param Request $request
     * @return JsonResponse
     */
    public function getStaffHistory(string $staffUserId, Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['date_from', 'date_to', 'role']);
            $history = $this->staffService->getStaffAssignmentHistory($staffUserId, $filters);

            return response()->json([
                'success' => true,
                'data' => $history,
                'message' => 'Staff assignment history retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve staff assignment history',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get staff performance metrics
     * 
     * @param string $staffUserId
     * @param Request $request
     * @return JsonResponse
     */
    public function getStaffPerformance(string $staffUserId, Request $request): JsonResponse
    {
        try {
            $filters = $request->only(['date_from', 'date_to', 'role']);
            $performance = $this->staffService->calculateStaffPerformance($staffUserId, $filters);

            return response()->json([
                'success' => true,
                'data' => $performance,
                'message' => 'Staff performance metrics retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve staff performance metrics',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get staff zone access permissions
     * 
     * @param string $staffUserId
     * @param int $eventId
     * @return JsonResponse
     */
    public function getStaffZoneAccess(string $staffUserId, int $eventId): JsonResponse
    {
        try {
            $access = $this->staffService->getStaffZoneAccess($staffUserId, $eventId);

            return response()->json([
                'success' => true,
                'data' => $access,
                'message' => 'Staff zone access retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve staff zone access',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Generate staff scheduling report
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function generateSchedulingReport(int $eventId): JsonResponse
    {
        try {
            $report = $this->staffService->generateStaffSchedulingReport($eventId);

            $this->auditService->logActivity(
                'staff_report',
                'Staff scheduling report generated',
                ['event_id' => $eventId]
            );

            return response()->json([
                'success' => true,
                'data' => $report,
                'message' => 'Staff scheduling report generated successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate staff scheduling report',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Remove staff assignment
     * 
     * @param Request $request
     * @param int $assignmentId
     * @return JsonResponse
     */
    public function removeAssignment(Request $request, int $assignmentId): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'reason' => 'sometimes|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $reason = $request->input('reason');
            $success = $this->staffService->removeStaffAssignment($assignmentId, $reason);

            if ($success) {
                return response()->json([
                    'success' => true,
                    'message' => 'Staff assignment removed successfully'
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to remove staff assignment'
                ], 500);
            }

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to remove staff assignment',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get available staff roles
     * 
     * @return JsonResponse
     */
    public function getAvailableRoles(): JsonResponse
    {
        try {
            $roles = [
                'manager' => 'Event Manager',
                'coordinator' => 'Event Coordinator',
                'security' => 'Security Staff',
                'usher' => 'Usher',
                'technical' => 'Technical Staff',
                'medical' => 'Medical Staff',
                'customer_service' => 'Customer Service',
                'merchandise' => 'Merchandise Staff',
                'cleaning' => 'Cleaning Staff',
                'volunteer' => 'Volunteer',
            ];

            return response()->json([
                'success' => true,
                'data' => $roles,
                'message' => 'Available staff roles retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve available roles',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get staff assignment by ID
     * 
     * @param int $assignmentId
     * @return JsonResponse
     */
    public function getAssignment(int $assignmentId): JsonResponse
    {
        try {
            $tenantId = auth()->user()->tenant_id;

            $assignment = \DB::table('staff_assignments')
                ->where('id', $assignmentId)
                ->where('tenant_id', $tenantId)
                ->whereNull('deleted_at')
                ->first();

            if (!$assignment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Staff assignment not found'
                ], 404);
            }

            // Decode JSON fields
            $assignment->permissions = json_decode($assignment->permissions ?? '[]', true);
            $assignment->metadata = json_decode($assignment->metadata ?? '[]', true);

            return response()->json([
                'success' => true,
                'data' => $assignment,
                'message' => 'Staff assignment retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve staff assignment',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get an aggregate performance report for all staff across all events.
     *
     * @return JsonResponse
     */
    public function getPerformanceReport(): JsonResponse
    {
        try {
            if (!\Schema::hasTable('staff_assignments')) {
                return response()->json([
                    'success' => true,
                    'data'    => [],
                    'message' => 'No staff performance data available yet.',
                ]);
            }

            $tenantId = auth()->user()->tenant_id ?? null;

            $hasPerfRating = \Schema::hasColumn('staff_assignments', 'performance_rating');
            $hasStatus     = \Schema::hasColumn('staff_assignments', 'status');
            $hasDeletedAt  = \Schema::hasColumn('staff_assignments', 'deleted_at');

            $query = \DB::table('staff_assignments as sa')
                ->select(array_filter([
                    'sa.staff_user_id',
                    \DB::raw('COUNT(DISTINCT sa.event_id) as total_events'),
                    $hasPerfRating ? \DB::raw('AVG(NULLIF(sa.performance_rating, 0)) as avg_rating') : \DB::raw('NULL as avg_rating'),
                    $hasStatus ? \DB::raw("SUM(CASE WHEN sa.status = 'completed' THEN 1 ELSE 0 END) as completed_assignments") : \DB::raw('0 as completed_assignments'),
                    $hasStatus ? \DB::raw("SUM(CASE WHEN sa.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_assignments") : \DB::raw('0 as cancelled_assignments'),
                    \DB::raw('COUNT(sa.id) as total_assignments'),
                ]));

            if ($hasDeletedAt) {
                $query->whereNull('sa.deleted_at');
            }

            if ($tenantId && \Schema::hasColumn('staff_assignments', 'tenant_id')) {
                $query->where('sa.tenant_id', $tenantId);
            }

            $rows = $query->groupBy('sa.staff_user_id')->get();

            return response()->json([
                'success' => true,
                'data'    => $rows,
                'message' => 'Staff performance report retrieved successfully',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve performance report',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }
}
<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Exceptions\EventManagementException;
use Modules\EventManagement\Models\Event;

/**
 * StaffManagementService
 *
 * Handles staff role assignment, zone-based access control, scheduling,
 * and performance tracking for event operations.
 *
 * Requirements: 15.2, 15.5
 */
class StaffManagementService
{
    private AuditService $auditService;

    private TenantSchemaManager $tenantManager;

    public function __construct(
        AuditService $auditService,
        TenantSchemaManager $tenantManager
    ) {
        $this->auditService = $auditService;
        $this->tenantManager = $tenantManager;
    }

    /**
     * Assign staff member to an event with specific role and zone
     *
     * @return int Assignment ID
     */
    public function assignStaffToEvent(int $eventId, string $staffUserId, array $assignmentData): int
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();

        // Validate event exists
        $event = Event::where('tenant_id', $tenantId)->findOrFail($eventId);

        // Check for existing assignment
        $existingAssignment = DB::table('staff_assignments')
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->where('staff_user_id', $staffUserId)
            ->where('role', $assignmentData['role'])
            ->whereNull('deleted_at')
            ->first();

        if ($existingAssignment) {
            throw new EventManagementException(
                "Staff member {$staffUserId} is already assigned to role {$assignmentData['role']} for this event"
            );
        }

        // Validate zone exists if specified
        if (isset($assignmentData['zone'])) {
            $zone = DB::table('operational_zones')
                ->where('event_id', $eventId)
                ->where('tenant_id', $tenantId)
                ->where('zone_code', $assignmentData['zone'])
                ->whereNull('deleted_at')
                ->first();

            if (! $zone) {
                throw new EventManagementException("Zone {$assignmentData['zone']} not found for this event");
            }
        }

        // Create assignment
        $assignmentId = DB::table('staff_assignments')->insertGetId([
            'tenant_id' => $tenantId,
            'event_id' => $eventId,
            'staff_user_id' => $staffUserId,
            'role' => $assignmentData['role'],
            'zone' => $assignmentData['zone'] ?? null,
            'responsibilities' => $assignmentData['responsibilities'] ?? null,
            'permissions' => isset($assignmentData['permissions'])
                ? json_encode($assignmentData['permissions'])
                : null,
            'shift_start' => $assignmentData['shift_start'] ?? null,
            'shift_end' => $assignmentData['shift_end'] ?? null,
            'is_lead' => $assignmentData['is_lead'] ?? false,
            'can_override' => $assignmentData['can_override'] ?? false,
            'contact_number' => $assignmentData['contact_number'] ?? null,
            'emergency_contact' => $assignmentData['emergency_contact'] ?? null,
            'special_instructions' => $assignmentData['special_instructions'] ?? null,
            'status' => 'assigned',
            'created_by' => auth()->user()->id ?? 'system',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Log the assignment
        $this->auditService->logActivity(
            'staff_assigned',
            'Staff member assigned to event',
            [
                'assignment_id' => $assignmentId,
                'event_id' => $eventId,
                'staff_user_id' => $staffUserId,
                'role' => $assignmentData['role'],
                'zone' => $assignmentData['zone'] ?? null,
                'is_lead' => $assignmentData['is_lead'] ?? false,
            ]
        );

        return $assignmentId;
    }

    /**
     * Update staff assignment details
     *
     * @return bool Success status
     */
    public function updateStaffAssignment(int $assignmentId, array $updateData): bool
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();

        $assignment = DB::table('staff_assignments')
            ->where('id', $assignmentId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if (! $assignment) {
            throw new EventManagementException("Staff assignment not found: {$assignmentId}");
        }

        // Prepare update data
        $updates = array_filter([
            'zone' => $updateData['zone'] ?? null,
            'responsibilities' => $updateData['responsibilities'] ?? null,
            'permissions' => isset($updateData['permissions'])
                ? json_encode($updateData['permissions'])
                : null,
            'shift_start' => $updateData['shift_start'] ?? null,
            'shift_end' => $updateData['shift_end'] ?? null,
            'is_lead' => $updateData['is_lead'] ?? null,
            'can_override' => $updateData['can_override'] ?? null,
            'contact_number' => $updateData['contact_number'] ?? null,
            'emergency_contact' => $updateData['emergency_contact'] ?? null,
            'special_instructions' => $updateData['special_instructions'] ?? null,
            'performance_rating' => $updateData['performance_rating'] ?? null,
            'performance_notes' => $updateData['performance_notes'] ?? null,
        ], function ($value) {
            return $value !== null;
        });

        $updates['updated_at'] = now();
        $updates['updated_by'] = auth()->user()->id ?? 'system';

        $success = DB::table('staff_assignments')
            ->where('id', $assignmentId)
            ->update($updates);

        if ($success) {
            $this->auditService->logActivity(
                'staff_assignment_updated',
                'Staff assignment updated',
                [
                    'assignment_id' => $assignmentId,
                    'updates' => $updates,
                ]
            );
        }

        return (bool) $success;
    }

    /**
     * Check staff member in/out for their shift
     *
     * @param  string  $action  'check_in' or 'check_out'
     * @return bool Success status
     */
    public function checkInOutStaff(int $assignmentId, string $action): bool
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();

        $assignment = DB::table('staff_assignments')
            ->where('id', $assignmentId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if (! $assignment) {
            throw new EventManagementException("Staff assignment not found: {$assignmentId}");
        }

        $updates = ['updated_at' => now()];

        if ($action === 'check_in') {
            if ($assignment->status === 'checked_in' || $assignment->status === 'active') {
                throw new EventManagementException('Staff member is already checked in');
            }

            $updates['status'] = 'checked_in';
            $updates['checked_in_at'] = now();

        } elseif ($action === 'check_out') {
            if ($assignment->status === 'checked_out') {
                throw new EventManagementException('Staff member is already checked out');
            }

            $updates['status'] = 'checked_out';
            $updates['checked_out_at'] = now();

        } else {
            throw new EventManagementException("Invalid action: {$action}");
        }

        $success = DB::table('staff_assignments')
            ->where('id', $assignmentId)
            ->update($updates);

        if ($success) {
            $this->auditService->logActivity(
                'staff_check_in_out',
                "Staff member {$action}",
                [
                    'assignment_id' => $assignmentId,
                    'action' => $action,
                    'timestamp' => now(),
                ]
            );
        }

        return (bool) $success;
    }

    /**
     * Update staff member status during event
     *
     * @return bool Success status
     */
    public function updateStaffStatus(int $assignmentId, string $status): bool
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();

        $validStatuses = ['assigned', 'checked_in', 'active', 'break', 'checked_out', 'absent'];

        if (! in_array($status, $validStatuses)) {
            throw new EventManagementException("Invalid status: {$status}");
        }

        $assignment = DB::table('staff_assignments')
            ->where('id', $assignmentId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if (! $assignment) {
            throw new EventManagementException("Staff assignment not found: {$assignmentId}");
        }

        $success = DB::table('staff_assignments')
            ->where('id', $assignmentId)
            ->update([
                'status' => $status,
                'updated_at' => now(),
            ]);

        if ($success) {
            $this->auditService->logActivity(
                'staff_status_updated',
                'Staff status updated',
                [
                    'assignment_id' => $assignmentId,
                    'previous_status' => $assignment->status,
                    'new_status' => $status,
                ]
            );
        }

        return (bool) $success;
    }

    /**
     * Get staff assignments for an event
     *
     * @return Collection Staff assignments
     */
    public function getEventStaffAssignments(int $eventId, array $filters = []): Collection
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();

        $query = DB::table('staff_assignments')
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at');

        // Apply filters
        if (isset($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (isset($filters['zone'])) {
            $query->where('zone', $filters['zone']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['is_lead'])) {
            $query->where('is_lead', $filters['is_lead']);
        }

        return $query->orderBy('role')
            ->orderBy('zone')
            ->orderBy('is_lead', 'desc')
            ->get();
    }

    /**
     * Get staff member's assignment history
     *
     * @return Collection Assignment history
     */
    public function getStaffAssignmentHistory(string $staffUserId, array $filters = []): Collection
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();

        $query = DB::table('staff_assignments')
            ->join('events', 'staff_assignments.event_id', '=', 'events.id')
            ->where('staff_assignments.staff_user_id', $staffUserId)
            ->where('staff_assignments.tenant_id', $tenantId)
            ->whereNull('staff_assignments.deleted_at');

        // Apply filters
        if (isset($filters['date_from'])) {
            $query->where('events.event_date', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->where('events.event_date', '<=', $filters['date_to']);
        }

        if (isset($filters['role'])) {
            $query->where('staff_assignments.role', $filters['role']);
        }

        return $query->select([
            'staff_assignments.*',
            'events.title as event_title',
            'events.event_date',
            'events.venue',
        ])
            ->orderBy('events.event_date', 'desc')
            ->get();
    }

    /**
     * Calculate staff performance metrics
     *
     * @return array Performance metrics
     */
    public function calculateStaffPerformance(string $staffUserId, array $filters = []): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();

        $assignments = $this->getStaffAssignmentHistory($staffUserId, $filters);

        if ($assignments->isEmpty()) {
            return [
                'staff_user_id' => $staffUserId,
                'total_assignments' => 0,
                'average_rating' => null,
                'attendance_rate' => null,
                'leadership_assignments' => 0,
                'roles_performed' => [],
                'zones_worked' => [],
            ];
        }

        $totalAssignments = $assignments->count();
        $ratedAssignments = $assignments->whereNotNull('performance_rating');
        $averageRating = $ratedAssignments->avg('performance_rating');

        $attendedAssignments = $assignments->whereNotIn('status', ['absent']);
        $attendanceRate = ($attendedAssignments->count() / $totalAssignments) * 100;

        $leadershipAssignments = $assignments->where('is_lead', true)->count();

        $rolesPerformed = $assignments->pluck('role')->unique()->values();
        $zonesWorked = $assignments->whereNotNull('zone')->pluck('zone')->unique()->values();

        return [
            'staff_user_id' => $staffUserId,
            'total_assignments' => $totalAssignments,
            'average_rating' => $averageRating ? round($averageRating, 2) : null,
            'attendance_rate' => round($attendanceRate, 2),
            'leadership_assignments' => $leadershipAssignments,
            'leadership_rate' => round(($leadershipAssignments / $totalAssignments) * 100, 2),
            'roles_performed' => $rolesPerformed,
            'zones_worked' => $zonesWorked,
            'recent_assignments' => $assignments->take(5),
        ];
    }

    /**
     * Get zone-based access permissions for staff member
     *
     * @return array Access permissions
     */
    public function getStaffZoneAccess(string $staffUserId, int $eventId): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();

        $assignments = DB::table('staff_assignments')
            ->where('staff_user_id', $staffUserId)
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->get();

        $zoneAccess = [];
        $globalPermissions = [];

        foreach ($assignments as $assignment) {
            $permissions = json_decode($assignment->permissions ?? '[]', true);

            if ($assignment->zone) {
                $zoneAccess[$assignment->zone] = [
                    'role' => $assignment->role,
                    'is_lead' => (bool) $assignment->is_lead,
                    'can_override' => (bool) $assignment->can_override,
                    'permissions' => $permissions,
                    'status' => $assignment->status,
                ];
            }

            // Collect global permissions
            $globalPermissions = array_merge($globalPermissions, $permissions);
        }

        return [
            'staff_user_id' => $staffUserId,
            'event_id' => $eventId,
            'zone_access' => $zoneAccess,
            'global_permissions' => array_unique($globalPermissions),
            'has_override_capability' => $assignments->where('can_override', true)->isNotEmpty(),
            'is_lead_anywhere' => $assignments->where('is_lead', true)->isNotEmpty(),
        ];
    }

    /**
     * Generate staff scheduling report for an event
     *
     * @return array Scheduling report
     */
    public function generateStaffSchedulingReport(int $eventId): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();

        $assignments = $this->getEventStaffAssignments($eventId);

        // Group by role
        $roleBreakdown = $assignments->groupBy('role')->map(function ($roleAssignments, $role) {
            return [
                'role' => $role,
                'total_assigned' => $roleAssignments->count(),
                'leads' => $roleAssignments->where('is_lead', true)->count(),
                'checked_in' => $roleAssignments->whereIn('status', ['checked_in', 'active'])->count(),
                'absent' => $roleAssignments->where('status', 'absent')->count(),
                'zones_covered' => $roleAssignments->whereNotNull('zone')->pluck('zone')->unique()->count(),
            ];
        });

        // Group by zone
        $zoneBreakdown = $assignments->whereNotNull('zone')->groupBy('zone')->map(function ($zoneAssignments, $zone) {
            return [
                'zone' => $zone,
                'total_assigned' => $zoneAssignments->count(),
                'roles_present' => $zoneAssignments->pluck('role')->unique()->count(),
                'leads' => $zoneAssignments->where('is_lead', true)->count(),
                'active_staff' => $zoneAssignments->whereIn('status', ['checked_in', 'active'])->count(),
            ];
        });

        // Calculate coverage metrics
        $totalAssigned = $assignments->count();
        $totalCheckedIn = $assignments->whereIn('status', ['checked_in', 'active'])->count();
        $totalAbsent = $assignments->where('status', 'absent')->count();
        $attendanceRate = $totalAssigned > 0 ? (($totalAssigned - $totalAbsent) / $totalAssigned) * 100 : 0;

        return [
            'event_id' => $eventId,
            'summary' => [
                'total_assigned' => $totalAssigned,
                'total_checked_in' => $totalCheckedIn,
                'total_absent' => $totalAbsent,
                'attendance_rate' => round($attendanceRate, 2),
                'total_leads' => $assignments->where('is_lead', true)->count(),
                'roles_covered' => $assignments->pluck('role')->unique()->count(),
                'zones_covered' => $assignments->whereNotNull('zone')->pluck('zone')->unique()->count(),
            ],
            'role_breakdown' => $roleBreakdown->values(),
            'zone_breakdown' => $zoneBreakdown->values(),
            'coverage_gaps' => $this->identifyStaffingGaps($assignments),
            'generated_at' => now(),
        ];
    }

    /**
     * Remove staff assignment
     *
     * @return bool Success status
     */
    public function removeStaffAssignment(int $assignmentId, ?string $reason = null): bool
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();

        $assignment = DB::table('staff_assignments')
            ->where('id', $assignmentId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if (! $assignment) {
            throw new EventManagementException("Staff assignment not found: {$assignmentId}");
        }

        $success = DB::table('staff_assignments')
            ->where('id', $assignmentId)
            ->update([
                'deleted_at' => now(),
                'updated_by' => auth()->user()->id ?? 'system',
            ]);

        if ($success) {
            $this->auditService->logActivity(
                'staff_assignment_removed',
                'Staff assignment removed',
                [
                    'assignment_id' => $assignmentId,
                    'staff_user_id' => $assignment->staff_user_id,
                    'event_id' => $assignment->event_id,
                    'role' => $assignment->role,
                    'reason' => $reason,
                ]
            );
        }

        return (bool) $success;
    }

    /**
     * Identify staffing gaps and coverage issues
     *
     * @return array Coverage gaps
     */
    private function identifyStaffingGaps(Collection $assignments): array
    {
        $gaps = [];

        // Check for roles without leads
        $roleGroups = $assignments->groupBy('role');
        foreach ($roleGroups as $role => $roleAssignments) {
            $hasLead = $roleAssignments->where('is_lead', true)->isNotEmpty();
            if (! $hasLead && $roleAssignments->count() > 1) {
                $gaps[] = [
                    'type' => 'missing_lead',
                    'role' => $role,
                    'message' => "Role '{$role}' has {$roleAssignments->count()} staff but no lead assigned",
                ];
            }
        }

        // Check for zones without coverage
        $zoneGroups = $assignments->whereNotNull('zone')->groupBy('zone');
        foreach ($zoneGroups as $zone => $zoneAssignments) {
            $activeStaff = $zoneAssignments->whereIn('status', ['checked_in', 'active'])->count();
            if ($activeStaff === 0) {
                $gaps[] = [
                    'type' => 'no_active_staff',
                    'zone' => $zone,
                    'message' => "Zone '{$zone}' has no active staff members",
                ];
            }
        }

        // Check for high absence rates
        $totalAssigned = $assignments->count();
        $absentCount = $assignments->where('status', 'absent')->count();
        if ($totalAssigned > 0 && ($absentCount / $totalAssigned) > 0.2) {
            $gaps[] = [
                'type' => 'high_absence_rate',
                'message' => "High absence rate: {$absentCount} out of {$totalAssigned} staff absent",
            ];
        }

        return $gaps;
    }
}

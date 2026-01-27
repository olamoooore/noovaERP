<?php

namespace Modules\EventManagement\Services;

use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\Ticket;
use Modules\EventManagement\Exceptions\EventManagementException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * EventOperationsService
 * 
 * Handles live event operations and real-time data aggregation.
 * Provides live event dashboard data, real-time check-in monitoring,
 * queue management, and incident logging for operational staff.
 * 
 * Requirements: 15.1, 15.2, 15.4
 */
class EventOperationsService
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
     * Get live event dashboard data with real-time metrics
     * 
     * @param int $eventId
     * @return array Live event dashboard data
     */
    public function getLiveEventDashboard(int $eventId): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();
        
        $event = Event::where('tenant_id', $tenantId)->findOrFail($eventId);
        
        // Get real-time check-in data
        $checkInData = $this->getCheckInMetrics($eventId, $tenantId);
        
        // Get zone status
        $zoneData = $this->getZoneStatus($eventId, $tenantId);
        
        // Get staff status
        $staffData = $this->getStaffStatus($eventId, $tenantId);
        
        // Get queue status
        $queueData = $this->getQueueStatus($eventId, $tenantId);
        
        // Get recent incidents
        $recentIncidents = $this->getRecentIncidents($eventId, $tenantId);
        
        // Get current event status
        $currentStatus = $this->getCurrentEventStatus($eventId, $tenantId);
        
        $dashboardData = [
            'event_id' => $eventId,
            'event_title' => $event->title,
            'event_date' => $event->event_date,
            'event_status' => $event->status,
            'current_operational_status' => $currentStatus,
            'check_in_metrics' => $checkInData,
            'zone_status' => $zoneData,
            'staff_status' => $staffData,
            'queue_status' => $queueData,
            'recent_incidents' => $recentIncidents,
            'last_updated' => now(),
        ];

        // Log dashboard access
        $this->auditService->logActivity(
            'operations_dashboard_access',
            'Live event dashboard accessed',
            ['event_id' => $eventId, 'dashboard_data' => $dashboardData]
        );

        return $dashboardData;
    }

    /**
     * Get real-time check-in monitoring data
     * 
     * @param int $eventId
     * @param string $tenantId
     * @return array Check-in metrics
     */
    public function getCheckInMetrics(int $eventId, string $tenantId): array
    {
        // Get total tickets and check-in status
        $totalTickets = Ticket::whereHas('booking', function ($query) use ($eventId, $tenantId) {
            $query->where('event_id', $eventId)
                  ->where('tenant_id', $tenantId)
                  ->where('status', 'confirmed');
        })->count();

        $checkedInTickets = Ticket::whereHas('booking', function ($query) use ($eventId, $tenantId) {
            $query->where('event_id', $eventId)
                  ->where('tenant_id', $tenantId)
                  ->where('status', 'confirmed');
        })->where('status', 'checked_in')->count();

        $pendingCheckIns = $totalTickets - $checkedInTickets;
        $checkInRate = $totalTickets > 0 ? ($checkedInTickets / $totalTickets) * 100 : 0;

        // Get hourly check-in velocity
        $hourlyCheckIns = Ticket::whereHas('booking', function ($query) use ($eventId, $tenantId) {
            $query->where('event_id', $eventId)
                  ->where('tenant_id', $tenantId)
                  ->where('status', 'confirmed');
        })
        ->where('status', 'checked_in')
        ->where('checked_in_at', '>=', now()->subHours(24))
        ->select(
            DB::raw('HOUR(checked_in_at) as hour'),
            DB::raw('COUNT(*) as checkins_count')
        )
        ->groupBy('hour')
        ->orderBy('hour')
        ->get();

        // Calculate current velocity (last hour)
        $currentHourCheckIns = Ticket::whereHas('booking', function ($query) use ($eventId, $tenantId) {
            $query->where('event_id', $eventId)
                  ->where('tenant_id', $tenantId)
                  ->where('status', 'confirmed');
        })
        ->where('status', 'checked_in')
        ->where('checked_in_at', '>=', now()->subHour())
        ->count();

        return [
            'total_tickets' => $totalTickets,
            'checked_in_tickets' => $checkedInTickets,
            'pending_check_ins' => $pendingCheckIns,
            'check_in_rate' => round($checkInRate, 2),
            'current_hour_velocity' => $currentHourCheckIns,
            'hourly_check_ins' => $hourlyCheckIns,
            'peak_hour' => $hourlyCheckIns->sortByDesc('checkins_count')->first(),
        ];
    }

    /**
     * Get zone status and occupancy data
     * 
     * @param int $eventId
     * @param string $tenantId
     * @return Collection Zone status data
     */
    public function getZoneStatus(int $eventId, string $tenantId): Collection
    {
        return DB::table('operational_zones')
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->select([
                'id',
                'zone_code',
                'zone_name',
                'zone_type',
                'max_capacity',
                'current_occupancy',
                'occupancy_percentage',
                'status',
                'opened_at',
                'closed_at',
                'status_notes'
            ])
            ->get();
    }

    /**
     * Get staff status and assignments
     * 
     * @param int $eventId
     * @param string $tenantId
     * @return Collection Staff status data
     */
    public function getStaffStatus(int $eventId, string $tenantId): Collection
    {
        return DB::table('staff_assignments')
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->select([
                'id',
                'staff_user_id',
                'role',
                'zone',
                'is_lead',
                'status',
                'checked_in_at',
                'checked_out_at',
                'contact_number',
                'performance_rating'
            ])
            ->get()
            ->groupBy('role');
    }

    /**
     * Get queue status across all check-in counters
     * 
     * @param int $eventId
     * @param string $tenantId
     * @return array Queue status summary
     */
    public function getQueueStatus(int $eventId, string $tenantId): array
    {
        $counters = DB::table('check_in_counters')
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->get();

        $totalQueueLength = $counters->sum('current_queue_length');
        $activeCounters = $counters->where('status', 'active')->count();
        $totalCounters = $counters->count();
        $averageProcessingTime = $counters->where('average_processing_time', '>', 0)
                                         ->avg('average_processing_time');

        // Find bottlenecks (counters with long queues)
        $bottlenecks = $counters->where('current_queue_length', '>', 10)
                                ->sortByDesc('current_queue_length')
                                ->take(5);

        return [
            'total_queue_length' => $totalQueueLength,
            'active_counters' => $activeCounters,
            'total_counters' => $totalCounters,
            'average_processing_time' => round($averageProcessingTime ?? 0, 2),
            'bottlenecks' => $bottlenecks,
            'counter_details' => $counters,
        ];
    }

    /**
     * Get recent incidents for the event
     * 
     * @param int $eventId
     * @param string $tenantId
     * @param int $limit
     * @return Collection Recent incidents
     */
    public function getRecentIncidents(int $eventId, string $tenantId, int $limit = 10): Collection
    {
        return DB::table('incident_logs')
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderBy('reported_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get current operational status of the event
     * 
     * @param int $eventId
     * @param string $tenantId
     * @return array Current status information
     */
    public function getCurrentEventStatus(int $eventId, string $tenantId): array
    {
        $latestStatus = DB::table('event_status_logs')
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->orderBy('status_changed_at', 'desc')
            ->first();

        if (!$latestStatus) {
            return [
                'status' => 'unknown',
                'status_description' => 'No status recorded',
                'changed_at' => null,
                'changed_by' => null,
            ];
        }

        return [
            'status' => $latestStatus->current_status,
            'status_description' => $latestStatus->status_description,
            'changed_at' => $latestStatus->status_changed_at,
            'changed_by' => $latestStatus->changed_by,
            'impact_level' => $latestStatus->impact_level,
            'attendees_affected' => $latestStatus->attendees_affected,
        ];
    }

    /**
     * Update zone occupancy in real-time
     * 
     * @param int $zoneId
     * @param int $occupancyChange
     * @return array Updated zone data
     */
    public function updateZoneOccupancy(int $zoneId, int $occupancyChange): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();
        
        $zone = DB::table('operational_zones')
            ->where('id', $zoneId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if (!$zone) {
            throw new EventManagementException("Zone not found: {$zoneId}");
        }

        $newOccupancy = max(0, $zone->current_occupancy + $occupancyChange);
        $occupancyPercentage = $zone->max_capacity > 0 
            ? ($newOccupancy / $zone->max_capacity) * 100 
            : 0;

        // Determine new status based on occupancy
        $newStatus = $zone->status;
        if ($occupancyPercentage >= 100) {
            $newStatus = 'full';
        } elseif ($occupancyPercentage >= 90) {
            $newStatus = 'busy';
        } elseif ($occupancyPercentage > 0) {
            $newStatus = 'open';
        }

        // Update zone
        DB::table('operational_zones')
            ->where('id', $zoneId)
            ->update([
                'current_occupancy' => $newOccupancy,
                'occupancy_percentage' => round($occupancyPercentage, 2),
                'status' => $newStatus,
                'updated_at' => now(),
            ]);

        // Log the change
        $this->auditService->logActivity(
            'zone_occupancy_update',
            'Zone occupancy updated',
            [
                'zone_id' => $zoneId,
                'occupancy_change' => $occupancyChange,
                'new_occupancy' => $newOccupancy,
                'occupancy_percentage' => $occupancyPercentage,
                'new_status' => $newStatus,
            ]
        );

        return [
            'zone_id' => $zoneId,
            'current_occupancy' => $newOccupancy,
            'occupancy_percentage' => round($occupancyPercentage, 2),
            'status' => $newStatus,
            'max_capacity' => $zone->max_capacity,
        ];
    }

    /**
     * Update check-in counter queue length
     * 
     * @param int $counterId
     * @param int $queueChange
     * @return array Updated counter data
     */
    public function updateCounterQueue(int $counterId, int $queueChange): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();
        
        $counter = DB::table('check_in_counters')
            ->where('id', $counterId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if (!$counter) {
            throw new EventManagementException("Counter not found: {$counterId}");
        }

        $newQueueLength = max(0, $counter->current_queue_length + $queueChange);
        
        // Update processing metrics if queue decreased (someone was processed)
        $updates = [
            'current_queue_length' => $newQueueLength,
            'last_activity_at' => now(),
            'updated_at' => now(),
        ];

        if ($queueChange < 0) {
            // Someone was processed
            $updates['total_processed'] = $counter->total_processed + abs($queueChange);
            
            // Update hourly processing rate
            $currentHour = now()->format('H');
            $hourlyProcessed = DB::table('check_in_counters')
                ->where('id', $counterId)
                ->where('updated_at', '>=', now()->startOfHour())
                ->sum('total_processed');
            
            $updates['checkins_per_hour'] = $hourlyProcessed;
        }

        DB::table('check_in_counters')
            ->where('id', $counterId)
            ->update($updates);

        // Log the change
        $this->auditService->logActivity(
            'counter_queue_update',
            'Check-in counter queue updated',
            [
                'counter_id' => $counterId,
                'queue_change' => $queueChange,
                'new_queue_length' => $newQueueLength,
                'total_processed' => $updates['total_processed'] ?? $counter->total_processed,
            ]
        );

        return [
            'counter_id' => $counterId,
            'current_queue_length' => $newQueueLength,
            'total_processed' => $updates['total_processed'] ?? $counter->total_processed,
            'checkins_per_hour' => $updates['checkins_per_hour'] ?? $counter->checkins_per_hour,
        ];
    }

    /**
     * Log an operational incident
     * 
     * @param int $eventId
     * @param array $incidentData
     * @return int Incident ID
     */
    public function logIncident(int $eventId, array $incidentData): int
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();
        
        $incidentId = DB::table('incident_logs')->insertGetId([
            'tenant_id' => $tenantId,
            'event_id' => $eventId,
            'zone_id' => $incidentData['zone_id'] ?? null,
            'counter_id' => $incidentData['counter_id'] ?? null,
            'incident_type' => $incidentData['incident_type'],
            'severity' => $incidentData['severity'] ?? 'medium',
            'priority' => $incidentData['priority'] ?? 'normal',
            'title' => $incidentData['title'],
            'description' => $incidentData['description'],
            'initial_assessment' => $incidentData['initial_assessment'] ?? null,
            'affected_areas' => json_encode($incidentData['affected_areas'] ?? []),
            'people_affected' => $incidentData['people_affected'] ?? null,
            'reported_by' => $incidentData['reported_by'],
            'reported_at' => now(),
            'status' => 'open',
            'is_administrative_override' => $incidentData['is_administrative_override'] ?? false,
            'override_type' => $incidentData['override_type'] ?? null,
            'override_justification' => $incidentData['override_justification'] ?? null,
            'authorized_by' => $incidentData['authorized_by'] ?? null,
            'override_details' => isset($incidentData['override_details']) 
                ? json_encode($incidentData['override_details']) 
                : null,
            'metadata' => isset($incidentData['metadata']) 
                ? json_encode($incidentData['metadata']) 
                : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Log the incident creation
        $this->auditService->logActivity(
            'incident_logged',
            'Operational incident logged',
            [
                'incident_id' => $incidentId,
                'event_id' => $eventId,
                'incident_type' => $incidentData['incident_type'],
                'severity' => $incidentData['severity'] ?? 'medium',
                'title' => $incidentData['title'],
            ]
        );

        return $incidentId;
    }

    /**
     * Update event operational status
     * 
     * @param int $eventId
     * @param string $statusType
     * @param string $newStatus
     * @param array $statusData
     * @return int Status log ID
     */
    public function updateEventStatus(int $eventId, string $statusType, string $newStatus, array $statusData = []): int
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();
        
        // Get previous status
        $previousStatus = DB::table('event_status_logs')
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->where('status_type', $statusType)
            ->orderBy('status_changed_at', 'desc')
            ->first();

        $statusLogId = DB::table('event_status_logs')->insertGetId([
            'tenant_id' => $tenantId,
            'event_id' => $eventId,
            'status_type' => $statusType,
            'previous_status' => $previousStatus->current_status ?? null,
            'current_status' => $newStatus,
            'status_description' => $statusData['description'] ?? null,
            'changed_by' => $statusData['changed_by'] ?? auth()->user()->id ?? 'system',
            'change_reason' => $statusData['reason'] ?? null,
            'status_data' => isset($statusData['data']) ? json_encode($statusData['data']) : null,
            'affected_components' => isset($statusData['affected_components']) 
                ? json_encode($statusData['affected_components']) 
                : null,
            'status_changed_at' => now(),
            'effective_until' => $statusData['effective_until'] ?? null,
            'impact_level' => $statusData['impact_level'] ?? 'none',
            'attendees_affected' => $statusData['attendees_affected'] ?? null,
            'zones_affected' => isset($statusData['zones_affected']) 
                ? json_encode($statusData['zones_affected']) 
                : null,
            'impact_description' => $statusData['impact_description'] ?? null,
            'public_announcement_made' => $statusData['public_announcement_made'] ?? false,
            'announcement_text' => $statusData['announcement_text'] ?? null,
            'notification_channels' => isset($statusData['notification_channels']) 
                ? json_encode($statusData['notification_channels']) 
                : null,
            'announced_at' => $statusData['announced_at'] ?? null,
            'requires_action' => $statusData['requires_action'] ?? false,
            'recommended_actions' => $statusData['recommended_actions'] ?? null,
            'staff_assignments_changed' => isset($statusData['staff_assignments_changed']) 
                ? json_encode($statusData['staff_assignments_changed']) 
                : null,
            'escalation_triggered' => $statusData['escalation_triggered'] ?? false,
            'metadata' => isset($statusData['metadata']) 
                ? json_encode($statusData['metadata']) 
                : null,
            'created_at' => now(),
        ]);

        // Log the status change
        $this->auditService->logActivity(
            'event_status_changed',
            'Event operational status changed',
            [
                'event_id' => $eventId,
                'status_type' => $statusType,
                'previous_status' => $previousStatus->current_status ?? null,
                'new_status' => $newStatus,
                'changed_by' => $statusData['changed_by'] ?? auth()->user()->id ?? 'system',
                'impact_level' => $statusData['impact_level'] ?? 'none',
            ]
        );

        return $statusLogId;
    }

    /**
     * Generate operational report for an event
     * 
     * @param int $eventId
     * @return array Operational report data
     */
    public function generateOperationalReport(int $eventId): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();
        
        $event = Event::where('tenant_id', $tenantId)->findOrFail($eventId);
        
        // Get summary data from operational dashboard view
        $dashboardSummary = DB::table('operational_dashboard')
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->first();

        // Get detailed incident summary
        $incidentSummary = DB::table('incident_logs')
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->select([
                'incident_type',
                'severity',
                DB::raw('COUNT(*) as count'),
                DB::raw('AVG(TIMESTAMPDIFF(MINUTE, reported_at, resolved_at)) as avg_resolution_time')
            ])
            ->groupBy('incident_type', 'severity')
            ->get();

        // Get staff performance summary
        $staffPerformance = DB::table('staff_assignments')
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->select([
                'role',
                DB::raw('COUNT(*) as staff_count'),
                DB::raw('AVG(performance_rating) as avg_rating'),
                DB::raw('COUNT(CASE WHEN status = "absent" THEN 1 END) as absent_count')
            ])
            ->groupBy('role')
            ->get();

        // Get zone utilization summary
        $zoneUtilization = DB::table('operational_zones')
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->select([
                'zone_type',
                DB::raw('COUNT(*) as zone_count'),
                DB::raw('AVG(occupancy_percentage) as avg_occupancy'),
                DB::raw('MAX(occupancy_percentage) as peak_occupancy')
            ])
            ->groupBy('zone_type')
            ->get();

        return [
            'event_id' => $eventId,
            'event_title' => $event->title,
            'event_date' => $event->event_date,
            'report_generated_at' => now(),
            'dashboard_summary' => $dashboardSummary,
            'incident_summary' => $incidentSummary,
            'staff_performance' => $staffPerformance,
            'zone_utilization' => $zoneUtilization,
            'recommendations' => $this->generateOperationalRecommendations($incidentSummary, $staffPerformance, $zoneUtilization),
        ];
    }

    /**
     * Generate operational recommendations based on report data
     * 
     * @param Collection $incidentSummary
     * @param Collection $staffPerformance
     * @param Collection $zoneUtilization
     * @return array Recommendations
     */
    private function generateOperationalRecommendations(
        Collection $incidentSummary, 
        Collection $staffPerformance, 
        Collection $zoneUtilization
    ): array {
        $recommendations = [];

        // Analyze incidents
        $criticalIncidents = $incidentSummary->where('severity', 'critical')->sum('count');
        if ($criticalIncidents > 0) {
            $recommendations[] = [
                'type' => 'incident_management',
                'priority' => 'high',
                'message' => "Review critical incident procedures - {$criticalIncidents} critical incidents occurred",
            ];
        }

        // Analyze staff performance
        $lowRatedRoles = $staffPerformance->where('avg_rating', '<', 3.0);
        foreach ($lowRatedRoles as $role) {
            $recommendations[] = [
                'type' => 'staff_training',
                'priority' => 'medium',
                'message' => "Consider additional training for {$role->role} staff (avg rating: {$role->avg_rating})",
            ];
        }

        // Analyze zone utilization
        $overUtilizedZones = $zoneUtilization->where('peak_occupancy', '>', 95);
        foreach ($overUtilizedZones as $zone) {
            $recommendations[] = [
                'type' => 'capacity_planning',
                'priority' => 'medium',
                'message' => "Consider increasing capacity for {$zone->zone_type} zones (peak: {$zone->peak_occupancy}%)",
            ];
        }

        return $recommendations;
    }
}
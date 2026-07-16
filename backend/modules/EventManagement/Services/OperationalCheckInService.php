<?php

namespace Modules\EventManagement\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\EventManagement\Exceptions\EventManagementException;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\Ticket;

/**
 * OperationalCheckInService
 *
 * Extends the existing check-in system for operational use with bulk check-in
 * capabilities, queue management, crowd control features, and real-time
 * capacity monitoring per zone.
 *
 * Requirements: 15.1, 15.3
 */
class OperationalCheckInService
{
    private TicketService $ticketService;

    private EventOperationsService $operationsService;

    private AuditService $auditService;

    private TenantSchemaManager $tenantManager;

    public function __construct(
        TicketService $ticketService,
        EventOperationsService $operationsService,
        AuditService $auditService,
        TenantSchemaManager $tenantManager
    ) {
        $this->ticketService = $ticketService;
        $this->operationsService = $operationsService;
        $this->auditService = $auditService;
        $this->tenantManager = $tenantManager;
    }

    /**
     * Check in a single ticket with operational features
     *
     * @return array Check-in result
     */
    public function checkInTicket(string $ticketCode, int $counterId, array $operationalData = []): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();

        return DB::transaction(function () use ($ticketCode, $counterId, $operationalData, $tenantId) {
            // Validate ticket
            $validation = $this->ticketService->validateTicket($ticketCode);

            if (! $validation['valid']) {
                throw new EventManagementException("Invalid ticket: {$validation['message']}");
            }

            $ticket = $validation['ticket'];
            $event = $validation['event'];
            $attendee = $validation['attendee'];

            // Validate counter exists and is active
            $counter = $this->validateCounter($counterId, $event->id, $tenantId);

            // Check if ticket is already checked in
            if ($ticket->status === 'checked_in') {
                throw new EventManagementException("Ticket {$ticketCode} is already checked in");
            }

            // Check event capacity if specified
            if (isset($operationalData['zone_code'])) {
                $this->validateZoneCapacity($operationalData['zone_code'], $event->id, $tenantId);
            }

            // Mark ticket as checked in
            $ticket->update([
                'status' => 'checked_in',
                'checked_in_at' => now(),
                'checked_in_counter_id' => $counterId,
                'checked_in_zone' => $operationalData['zone_code'] ?? null,
                'checked_in_by' => $operationalData['staff_id'] ?? auth()->user()->id ?? 'system',
            ]);

            // Update counter queue (process one person)
            $this->operationsService->updateCounterQueue($counterId, -1);

            // Update zone occupancy if specified
            if (isset($operationalData['zone_code'])) {
                $zone = $this->getZoneByCode($operationalData['zone_code'], $event->id, $tenantId);
                if ($zone) {
                    $this->operationsService->updateZoneOccupancy($zone->id, 1);
                }
            }

            // Log the check-in
            $this->auditService->logActivity(
                'operational_ticket_checkin',
                'Ticket checked in operationally',
                [
                    'ticket_code' => $ticketCode,
                    'counter_id' => $counterId,
                    'event_id' => $event->id,
                    'attendee_name' => $attendee->attendee_name,
                    'zone_code' => $operationalData['zone_code'] ?? null,
                    'staff_id' => $operationalData['staff_id'] ?? null,
                ]
            );

            return [
                'success' => true,
                'ticket_code' => $ticketCode,
                'attendee_name' => $attendee->attendee_name,
                'event_title' => $event->title,
                'checked_in_at' => $ticket->checked_in_at,
                'counter_code' => $counter->counter_code,
                'zone_code' => $operationalData['zone_code'] ?? null,
                'message' => 'Check-in successful',
            ];
        });
    }

    /**
     * Bulk check-in multiple tickets for staff operations
     *
     * @return array Bulk check-in results
     */
    public function bulkCheckIn(array $ticketCodes, int $counterId, array $operationalData = []): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();
        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($ticketCodes as $ticketCode) {
            try {
                $result = $this->checkInTicket($ticketCode, $counterId, $operationalData);
                $results[] = $result;
                $successCount++;
            } catch (\Exception $e) {
                $results[] = [
                    'success' => false,
                    'ticket_code' => $ticketCode,
                    'error' => $e->getMessage(),
                    'message' => 'Check-in failed',
                ];
                $failureCount++;
            }
        }

        // Log bulk operation
        $this->auditService->logActivity(
            'bulk_ticket_checkin',
            'Bulk ticket check-in operation',
            [
                'counter_id' => $counterId,
                'total_tickets' => count($ticketCodes),
                'successful' => $successCount,
                'failed' => $failureCount,
                'staff_id' => $operationalData['staff_id'] ?? null,
            ]
        );

        return [
            'total_processed' => count($ticketCodes),
            'successful' => $successCount,
            'failed' => $failureCount,
            'success_rate' => count($ticketCodes) > 0 ? ($successCount / count($ticketCodes)) * 100 : 0,
            'results' => $results,
        ];
    }

    /**
     * Check in by booking reference (group check-in)
     *
     * @return array Group check-in result
     */
    public function checkInByBookingReference(string $bookingReference, int $counterId, array $operationalData = []): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();

        // Find booking
        $booking = Booking::where('booking_reference', $bookingReference)
            ->where('tenant_id', $tenantId)
            ->with(['tickets', 'event'])
            ->first();

        if (! $booking) {
            throw new EventManagementException("Booking not found: {$bookingReference}");
        }

        // Get all active tickets for the booking
        $activeTickets = $booking->tickets->where('status', 'active');

        if ($activeTickets->isEmpty()) {
            throw new EventManagementException("No active tickets found for booking {$bookingReference}");
        }

        // Validate counter supports group check-in
        $counter = $this->validateCounter($counterId, $booking->event->id, $tenantId);

        if (! $counter->supports_group_checkin) {
            throw new EventManagementException("Counter {$counter->counter_code} does not support group check-in");
        }

        if ($counter->max_group_size && $activeTickets->count() > $counter->max_group_size) {
            throw new EventManagementException(
                "Group size ({$activeTickets->count()}) exceeds counter limit ({$counter->max_group_size})"
            );
        }

        // Perform bulk check-in
        $ticketCodes = $activeTickets->pluck('ticket_code')->toArray();
        $bulkResult = $this->bulkCheckIn($ticketCodes, $counterId, $operationalData);

        return [
            'booking_reference' => $bookingReference,
            'group_size' => $activeTickets->count(),
            'bulk_result' => $bulkResult,
        ];
    }

    /**
     * Get real-time check-in statistics for an event
     *
     * @return array Check-in statistics
     */
    public function getCheckInStatistics(int $eventId): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();

        // Get total tickets for the event
        $totalTickets = DB::table('tickets')
            ->join('bookings', 'tickets.booking_id', '=', 'bookings.id')
            ->where('bookings.event_id', $eventId)
            ->where('bookings.tenant_id', $tenantId)
            ->where('bookings.status', 'confirmed')
            ->count();

        // Get checked-in tickets
        $checkedInTickets = DB::table('tickets')
            ->join('bookings', 'tickets.booking_id', '=', 'bookings.id')
            ->where('bookings.event_id', $eventId)
            ->where('bookings.tenant_id', $tenantId)
            ->where('tickets.status', 'checked_in')
            ->count();

        // Get check-in velocity (last hour)
        $recentCheckIns = DB::table('tickets')
            ->join('bookings', 'tickets.booking_id', '=', 'bookings.id')
            ->where('bookings.event_id', $eventId)
            ->where('bookings.tenant_id', $tenantId)
            ->where('tickets.status', 'checked_in')
            ->where('tickets.checked_in_at', '>=', now()->subHour())
            ->count();

        // Get check-ins by counter
        $counterStats = DB::table('tickets')
            ->join('bookings', 'tickets.booking_id', '=', 'bookings.id')
            ->join('check_in_counters', 'tickets.checked_in_counter_id', '=', 'check_in_counters.id')
            ->where('bookings.event_id', $eventId)
            ->where('bookings.tenant_id', $tenantId)
            ->where('tickets.status', 'checked_in')
            ->select([
                'check_in_counters.counter_code',
                'check_in_counters.counter_name',
                DB::raw('COUNT(*) as checkins_count'),
            ])
            ->groupBy('check_in_counters.id', 'check_in_counters.counter_code', 'check_in_counters.counter_name')
            ->get();

        // Get check-ins by zone
        $zoneStats = DB::table('tickets')
            ->join('bookings', 'tickets.booking_id', '=', 'bookings.id')
            ->where('bookings.event_id', $eventId)
            ->where('bookings.tenant_id', $tenantId)
            ->where('tickets.status', 'checked_in')
            ->whereNotNull('tickets.checked_in_zone')
            ->select([
                'tickets.checked_in_zone as zone_code',
                DB::raw('COUNT(*) as checkins_count'),
            ])
            ->groupBy('tickets.checked_in_zone')
            ->get();

        $checkInRate = $totalTickets > 0 ? ($checkedInTickets / $totalTickets) * 100 : 0;
        $pendingCheckIns = $totalTickets - $checkedInTickets;

        return [
            'event_id' => $eventId,
            'total_tickets' => $totalTickets,
            'checked_in_tickets' => $checkedInTickets,
            'pending_check_ins' => $pendingCheckIns,
            'check_in_rate' => round($checkInRate, 2),
            'recent_velocity' => $recentCheckIns,
            'counter_breakdown' => $counterStats,
            'zone_breakdown' => $zoneStats,
            'last_updated' => now(),
        ];
    }

    /**
     * Get queue management data for crowd control
     *
     * @return array Queue management data
     */
    public function getQueueManagementData(int $eventId): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();

        // Get all counters for the event
        $counters = DB::table('check_in_counters')
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->get();

        $totalQueueLength = $counters->sum('current_queue_length');
        $activeCounters = $counters->where('status', 'active')->count();
        $busyCounters = $counters->where('status', 'busy')->count();

        // Calculate average wait time
        $avgProcessingTime = $counters->where('average_processing_time', '>', 0)
            ->avg('average_processing_time');
        $avgWaitTime = $avgProcessingTime && $activeCounters > 0
            ? ($totalQueueLength / $activeCounters) * $avgProcessingTime
            : 0;

        // Identify bottlenecks
        $bottlenecks = $counters->where('current_queue_length', '>', 15)
            ->sortByDesc('current_queue_length')
            ->take(5)
            ->values();

        // Get recommendations
        $recommendations = $this->generateQueueRecommendations($counters, $totalQueueLength, $activeCounters);

        return [
            'event_id' => $eventId,
            'total_queue_length' => $totalQueueLength,
            'active_counters' => $activeCounters,
            'busy_counters' => $busyCounters,
            'total_counters' => $counters->count(),
            'average_wait_time' => round($avgWaitTime, 1),
            'bottlenecks' => $bottlenecks,
            'recommendations' => $recommendations,
            'counter_details' => $counters,
            'last_updated' => now(),
        ];
    }

    /**
     * Monitor zone capacity in real-time
     *
     * @return array Zone capacity monitoring data
     */
    public function monitorZoneCapacity(int $eventId): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();

        // Get all zones for the event
        $zones = DB::table('operational_zones')
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->get();

        $capacityAlerts = [];
        $zoneStatus = [];

        foreach ($zones as $zone) {
            $occupancyStatus = 'normal';
            $alertLevel = 'none';

            if ($zone->occupancy_percentage >= 100) {
                $occupancyStatus = 'full';
                $alertLevel = 'critical';
                $capacityAlerts[] = [
                    'zone_code' => $zone->zone_code,
                    'zone_name' => $zone->zone_name,
                    'alert_level' => 'critical',
                    'message' => 'Zone is at full capacity',
                    'occupancy_percentage' => $zone->occupancy_percentage,
                ];
            } elseif ($zone->occupancy_percentage >= 90) {
                $occupancyStatus = 'nearly_full';
                $alertLevel = 'warning';
                $capacityAlerts[] = [
                    'zone_code' => $zone->zone_code,
                    'zone_name' => $zone->zone_name,
                    'alert_level' => 'warning',
                    'message' => 'Zone is nearly at capacity',
                    'occupancy_percentage' => $zone->occupancy_percentage,
                ];
            } elseif ($zone->occupancy_percentage >= 75) {
                $occupancyStatus = 'busy';
                $alertLevel = 'info';
            }

            $zoneStatus[] = [
                'zone_code' => $zone->zone_code,
                'zone_name' => $zone->zone_name,
                'zone_type' => $zone->zone_type,
                'max_capacity' => $zone->max_capacity,
                'current_occupancy' => $zone->current_occupancy,
                'occupancy_percentage' => $zone->occupancy_percentage,
                'available_capacity' => $zone->max_capacity - $zone->current_occupancy,
                'status' => $zone->status,
                'occupancy_status' => $occupancyStatus,
                'alert_level' => $alertLevel,
            ];
        }

        return [
            'event_id' => $eventId,
            'total_zones' => $zones->count(),
            'zones_at_capacity' => collect($zoneStatus)->where('occupancy_percentage', '>=', 100)->count(),
            'zones_nearly_full' => collect($zoneStatus)->where('occupancy_percentage', '>=', 90)->count(),
            'capacity_alerts' => $capacityAlerts,
            'zone_status' => $zoneStatus,
            'last_updated' => now(),
        ];
    }

    /**
     * Emergency check-in override for staff
     *
     * @return array Override result
     */
    public function emergencyCheckInOverride(string $ticketCode, int $counterId, string $overrideReason, array $operationalData = []): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();

        // Validate staff has override permissions
        $staffId = $operationalData['staff_id'] ?? auth()->user()->id;
        if (! $this->validateStaffOverridePermission($staffId, $counterId, $tenantId)) {
            throw new EventManagementException('Staff member does not have override permissions');
        }

        return DB::transaction(function () use ($ticketCode, $counterId, $overrideReason, $operationalData) {
            // Try to find ticket even if invalid
            $ticket = Ticket::where('ticket_code', $ticketCode)->first();

            if (! $ticket) {
                throw new EventManagementException("Ticket not found: {$ticketCode}");
            }

            // Force check-in regardless of current status
            $previousStatus = $ticket->status;
            $ticket->update([
                'status' => 'checked_in',
                'checked_in_at' => now(),
                'checked_in_counter_id' => $counterId,
                'checked_in_zone' => $operationalData['zone_code'] ?? null,
                'checked_in_by' => $operationalData['staff_id'] ?? auth()->user()->id ?? 'system',
                'override_reason' => $overrideReason,
                'is_override' => true,
            ]);

            // Log the override
            $this->auditService->logActivity(
                'emergency_checkin_override',
                'Emergency check-in override performed',
                [
                    'ticket_code' => $ticketCode,
                    'counter_id' => $counterId,
                    'previous_status' => $previousStatus,
                    'override_reason' => $overrideReason,
                    'staff_id' => $operationalData['staff_id'] ?? null,
                ]
            );

            return [
                'success' => true,
                'ticket_code' => $ticketCode,
                'previous_status' => $previousStatus,
                'override_reason' => $overrideReason,
                'checked_in_at' => $ticket->checked_in_at,
                'message' => 'Emergency override successful',
            ];
        });
    }

    /**
     * Validate counter exists and is operational
     *
     * @return object Counter data
     */
    private function validateCounter(int $counterId, int $eventId, string $tenantId): object
    {
        $counter = DB::table('check_in_counters')
            ->where('id', $counterId)
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();

        if (! $counter) {
            throw new EventManagementException('Counter not found or not available for this event');
        }

        if (! in_array($counter->status, ['active', 'busy'])) {
            throw new EventManagementException("Counter {$counter->counter_code} is not active");
        }

        return $counter;
    }

    /**
     * Validate zone capacity before check-in
     */
    private function validateZoneCapacity(string $zoneCode, int $eventId, string $tenantId): void
    {
        $zone = $this->getZoneByCode($zoneCode, $eventId, $tenantId);

        if (! $zone) {
            throw new EventManagementException("Zone {$zoneCode} not found");
        }

        if ($zone->status === 'full') {
            throw new EventManagementException("Zone {$zoneCode} is at full capacity");
        }

        if ($zone->max_capacity && $zone->current_occupancy >= $zone->max_capacity) {
            throw new EventManagementException("Zone {$zoneCode} has reached maximum capacity");
        }
    }

    /**
     * Get zone by code
     *
     * @return object|null Zone data
     */
    private function getZoneByCode(string $zoneCode, int $eventId, string $tenantId): ?object
    {
        return DB::table('operational_zones')
            ->where('zone_code', $zoneCode)
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->first();
    }

    /**
     * Validate staff override permission
     *
     * @return bool Has permission
     */
    private function validateStaffOverridePermission(string $staffId, int $counterId, string $tenantId): bool
    {
        // Check if staff has override capability
        $assignment = DB::table('staff_assignments')
            ->where('staff_user_id', $staffId)
            ->where('tenant_id', $tenantId)
            ->where('can_override', true)
            ->whereIn('status', ['checked_in', 'active'])
            ->whereNull('deleted_at')
            ->first();

        return $assignment !== null;
    }

    /**
     * Generate queue management recommendations
     *
     * @param  Collection  $counters
     * @return array Recommendations
     */
    private function generateQueueRecommendations($counters, int $totalQueueLength, int $activeCounters): array
    {
        $recommendations = [];

        // Check for long queues
        $longQueues = $counters->where('current_queue_length', '>', 20);
        if ($longQueues->isNotEmpty()) {
            $recommendations[] = [
                'type' => 'long_queue_alert',
                'priority' => 'high',
                'message' => 'Multiple counters have long queues (>20 people)',
                'affected_counters' => $longQueues->pluck('counter_code')->toArray(),
            ];
        }

        // Check for inactive counters when queues are long
        $inactiveCounters = $counters->where('status', 'closed')->count();
        if ($inactiveCounters > 0 && $totalQueueLength > 50) {
            $recommendations[] = [
                'type' => 'activate_counters',
                'priority' => 'medium',
                'message' => "Consider activating {$inactiveCounters} inactive counters to reduce wait times",
            ];
        }

        // Check for uneven distribution
        if ($activeCounters > 1) {
            $activeCounterQueues = $counters->where('status', 'active')->pluck('current_queue_length');
            $maxQueue = $activeCounterQueues->max();
            $minQueue = $activeCounterQueues->min();

            if ($maxQueue - $minQueue > 10) {
                $recommendations[] = [
                    'type' => 'redistribute_queues',
                    'priority' => 'medium',
                    'message' => 'Uneven queue distribution detected - consider redirecting customers',
                ];
            }
        }

        return $recommendations;
    }
}

<?php

namespace Modules\EventManagement\Services;

use Modules\EventManagement\Models\BookingFacts;
use Modules\EventManagement\Models\AnalyticsFacts;
use Modules\EventManagement\Models\FactReconciliationLog;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * AnalyticsReconciliationService
 * 
 * Provides reconciliation between source data and analytics facts
 * to ensure data consistency and detect drift.
 */
class AnalyticsReconciliationService
{
    /**
     * Perform comprehensive reconciliation for an event
     */
    public function reconcileEvent(int $eventId, ?Carbon $reconciliationDate = null): array
    {
        $reconciliationDate = $reconciliationDate ?? now()->toDateString();
        
        $results = [];
        
        // Revenue reconciliation
        $results['revenue'] = $this->reconcileRevenue($eventId, $reconciliationDate);
        
        // Capacity reconciliation
        $results['capacity'] = $this->reconcileCapacity($eventId, $reconciliationDate);
        
        // Booking count reconciliation
        $results['booking_count'] = $this->reconcileBookingCount($eventId, $reconciliationDate);
        
        // Analytics consistency check
        $results['analytics_consistency'] = $this->checkAnalyticsConsistency($eventId, $reconciliationDate);
        
        return $results;
    }

    /**
     * Reconcile revenue between source bookings and analytics facts
     */
    public function reconcileRevenue(int $eventId, string $reconciliationDate): array
    {
        // Get source revenue data from bookings
        $sourceRevenue = DB::table('bookings')
            ->where('event_id', $eventId)
            ->whereIn('state', ['confirmed', 'booked'])
            ->whereDate('created_at', '<=', $reconciliationDate)
            ->sum('total_amount');

        // Get revenue from analytics facts
        $factRevenue = AnalyticsFacts::forEvent($eventId)
            ->revenueGenerating()
            ->whereDate('fact_timestamp', '<=', $reconciliationDate)
            ->sum(DB::raw("JSON_EXTRACT(fact_data, '$.revenue_amount')"));

        // Get revenue from booking facts (alternative calculation)
        $bookingFactsRevenue = BookingFacts::join('bookings', 'booking_facts.booking_id', '=', 'bookings.id')
            ->where('bookings.event_id', $eventId)
            ->whereIn('bookings.state', ['confirmed', 'booked'])
            ->whereDate('booking_facts.created_at', '<=', $reconciliationDate)
            ->sum(DB::raw("JSON_EXTRACT(booking_facts.pricing_snapshot, '$.total_amount')"));

        $discrepancies = [];
        $status = FactReconciliationLog::STATUS_PASSED;

        // Check for discrepancies
        if (abs($sourceRevenue - $factRevenue) > 0.01) {
            $discrepancies['source_vs_analytics_facts'] = [
                'source' => $sourceRevenue,
                'analytics_facts' => $factRevenue,
                'difference' => $sourceRevenue - $factRevenue
            ];
            $status = FactReconciliationLog::STATUS_FAILED;
        }

        if (abs($sourceRevenue - $bookingFactsRevenue) > 0.01) {
            $discrepancies['source_vs_booking_facts'] = [
                'source' => $sourceRevenue,
                'booking_facts' => $bookingFactsRevenue,
                'difference' => $sourceRevenue - $bookingFactsRevenue
            ];
            $status = FactReconciliationLog::STATUS_FAILED;
        }

        // Log reconciliation result
        $reconciliationLog = FactReconciliationLog::create([
            'reconciliation_type' => FactReconciliationLog::TYPE_REVENUE_RECONCILIATION,
            'event_id' => $eventId,
            'reconciliation_date' => $reconciliationDate,
            'source_data_summary' => [
                'total_revenue' => $sourceRevenue,
                'booking_count' => DB::table('bookings')
                    ->where('event_id', $eventId)
                    ->whereIn('state', ['confirmed', 'booked'])
                    ->whereDate('created_at', '<=', $reconciliationDate)
                    ->count()
            ],
            'fact_data_summary' => [
                'analytics_facts_revenue' => $factRevenue,
                'booking_facts_revenue' => $bookingFactsRevenue,
                'analytics_facts_count' => AnalyticsFacts::forEvent($eventId)
                    ->revenueGenerating()
                    ->whereDate('fact_timestamp', '<=', $reconciliationDate)
                    ->count()
            ],
            'discrepancies' => $discrepancies,
            'status' => $status,
            'notes' => empty($discrepancies) ? 'Revenue reconciliation passed' : 'Revenue discrepancies detected'
        ]);

        return [
            'reconciliation_id' => $reconciliationLog->id,
            'status' => $status,
            'source_revenue' => $sourceRevenue,
            'analytics_facts_revenue' => $factRevenue,
            'booking_facts_revenue' => $bookingFactsRevenue,
            'discrepancies' => $discrepancies
        ];
    }

    /**
     * Reconcile capacity utilization
     */
    public function reconcileCapacity(int $eventId, string $reconciliationDate): array
    {
        $event = Event::findOrFail($eventId);
        
        // Get source capacity data
        $sourceTicketsSold = DB::table('bookings')
            ->join('booking_attendees', 'bookings.id', '=', 'booking_attendees.booking_id')
            ->where('bookings.event_id', $eventId)
            ->whereIn('bookings.state', ['confirmed', 'booked'])
            ->whereDate('bookings.created_at', '<=', $reconciliationDate)
            ->count();

        // Get capacity from analytics facts
        $factTicketsSold = AnalyticsFacts::forEvent($eventId)
            ->whereIn('fact_type', [AnalyticsFacts::FACT_TYPE_BOOKING_CREATED, AnalyticsFacts::FACT_TYPE_TICKET_ISSUED])
            ->whereDate('fact_timestamp', '<=', $reconciliationDate)
            ->sum(DB::raw("JSON_EXTRACT(fact_data, '$.ticket_quantity')"));

        // Get capacity from booking facts
        $bookingFactsTickets = BookingFacts::join('bookings', 'booking_facts.booking_id', '=', 'bookings.id')
            ->join('booking_attendees', 'bookings.id', '=', 'booking_attendees.booking_id')
            ->where('bookings.event_id', $eventId)
            ->whereIn('bookings.state', ['confirmed', 'booked'])
            ->whereDate('booking_facts.created_at', '<=', $reconciliationDate)
            ->count();

        $discrepancies = [];
        $status = FactReconciliationLog::STATUS_PASSED;

        if ($sourceTicketsSold !== $factTicketsSold) {
            $discrepancies['source_vs_analytics_facts'] = [
                'source' => $sourceTicketsSold,
                'analytics_facts' => $factTicketsSold,
                'difference' => $sourceTicketsSold - $factTicketsSold
            ];
            $status = FactReconciliationLog::STATUS_FAILED;
        }

        if ($sourceTicketsSold !== $bookingFactsTickets) {
            $discrepancies['source_vs_booking_facts'] = [
                'source' => $sourceTicketsSold,
                'booking_facts' => $bookingFactsTickets,
                'difference' => $sourceTicketsSold - $bookingFactsTickets
            ];
            $status = FactReconciliationLog::STATUS_FAILED;
        }

        // Log reconciliation result
        $reconciliationLog = FactReconciliationLog::create([
            'reconciliation_type' => FactReconciliationLog::TYPE_CAPACITY_RECONCILIATION,
            'event_id' => $eventId,
            'reconciliation_date' => $reconciliationDate,
            'source_data_summary' => [
                'total_capacity' => $event->capacity,
                'tickets_sold' => $sourceTicketsSold,
                'capacity_utilization' => $event->capacity > 0 ? ($sourceTicketsSold / $event->capacity) * 100 : 0
            ],
            'fact_data_summary' => [
                'analytics_facts_tickets' => $factTicketsSold,
                'booking_facts_tickets' => $bookingFactsTickets
            ],
            'discrepancies' => $discrepancies,
            'status' => $status,
            'notes' => empty($discrepancies) ? 'Capacity reconciliation passed' : 'Capacity discrepancies detected'
        ]);

        return [
            'reconciliation_id' => $reconciliationLog->id,
            'status' => $status,
            'event_capacity' => $event->capacity,
            'source_tickets_sold' => $sourceTicketsSold,
            'analytics_facts_tickets' => $factTicketsSold,
            'booking_facts_tickets' => $bookingFactsTickets,
            'discrepancies' => $discrepancies
        ];
    }

    /**
     * Reconcile booking counts
     */
    public function reconcileBookingCount(int $eventId, string $reconciliationDate): array
    {
        // Get source booking count
        $sourceBookingCount = Booking::where('event_id', $eventId)
            ->whereDate('created_at', '<=', $reconciliationDate)
            ->count();

        // Get booking count from facts
        $factBookingCount = BookingFacts::join('bookings', 'booking_facts.booking_id', '=', 'bookings.id')
            ->where('bookings.event_id', $eventId)
            ->whereDate('booking_facts.created_at', '<=', $reconciliationDate)
            ->count();

        $discrepancies = [];
        $status = FactReconciliationLog::STATUS_PASSED;

        if ($sourceBookingCount !== $factBookingCount) {
            $discrepancies['booking_count_mismatch'] = [
                'source' => $sourceBookingCount,
                'facts' => $factBookingCount,
                'difference' => $sourceBookingCount - $factBookingCount
            ];
            $status = FactReconciliationLog::STATUS_WARNING; // Warning, not failure
        }

        // Log reconciliation result
        $reconciliationLog = FactReconciliationLog::create([
            'reconciliation_type' => FactReconciliationLog::TYPE_BOOKING_COUNT_RECONCILIATION,
            'event_id' => $eventId,
            'reconciliation_date' => $reconciliationDate,
            'source_data_summary' => [
                'total_bookings' => $sourceBookingCount,
                'confirmed_bookings' => Booking::where('event_id', $eventId)
                    ->whereIn('state', ['confirmed', 'booked'])
                    ->whereDate('created_at', '<=', $reconciliationDate)
                    ->count()
            ],
            'fact_data_summary' => [
                'booking_facts_count' => $factBookingCount
            ],
            'discrepancies' => $discrepancies,
            'status' => $status,
            'notes' => empty($discrepancies) ? 'Booking count reconciliation passed' : 'Minor booking count discrepancies detected'
        ]);

        return [
            'reconciliation_id' => $reconciliationLog->id,
            'status' => $status,
            'source_booking_count' => $sourceBookingCount,
            'fact_booking_count' => $factBookingCount,
            'discrepancies' => $discrepancies
        ];
    }

    /**
     * Check analytics consistency across different fact types
     */
    public function checkAnalyticsConsistency(int $eventId, string $reconciliationDate): array
    {
        // Check consistency between different analytics fact types
        $bookingCreatedFacts = AnalyticsFacts::forEvent($eventId)
            ->ofType(AnalyticsFacts::FACT_TYPE_BOOKING_CREATED)
            ->whereDate('fact_timestamp', '<=', $reconciliationDate)
            ->count();

        $paymentConfirmedFacts = AnalyticsFacts::forEvent($eventId)
            ->ofType(AnalyticsFacts::FACT_TYPE_PAYMENT_CONFIRMED)
            ->whereDate('fact_timestamp', '<=', $reconciliationDate)
            ->count();

        $ticketIssuedFacts = AnalyticsFacts::forEvent($eventId)
            ->ofType(AnalyticsFacts::FACT_TYPE_TICKET_ISSUED)
            ->whereDate('fact_timestamp', '<=', $reconciliationDate)
            ->count();

        $discrepancies = [];
        $status = FactReconciliationLog::STATUS_PASSED;

        // Payment confirmed should not exceed booking created
        if ($paymentConfirmedFacts > $bookingCreatedFacts) {
            $discrepancies['payment_exceeds_booking'] = [
                'booking_created' => $bookingCreatedFacts,
                'payment_confirmed' => $paymentConfirmedFacts,
                'excess' => $paymentConfirmedFacts - $bookingCreatedFacts
            ];
            $status = FactReconciliationLog::STATUS_FAILED;
        }

        // Ticket issued should not exceed payment confirmed
        if ($ticketIssuedFacts > $paymentConfirmedFacts) {
            $discrepancies['tickets_exceed_payments'] = [
                'payment_confirmed' => $paymentConfirmedFacts,
                'ticket_issued' => $ticketIssuedFacts,
                'excess' => $ticketIssuedFacts - $paymentConfirmedFacts
            ];
            $status = FactReconciliationLog::STATUS_FAILED;
        }

        // Log reconciliation result
        $reconciliationLog = FactReconciliationLog::create([
            'reconciliation_type' => FactReconciliationLog::TYPE_ANALYTICS_CONSISTENCY,
            'event_id' => $eventId,
            'reconciliation_date' => $reconciliationDate,
            'source_data_summary' => [
                'booking_created_facts' => $bookingCreatedFacts,
                'payment_confirmed_facts' => $paymentConfirmedFacts,
                'ticket_issued_facts' => $ticketIssuedFacts
            ],
            'fact_data_summary' => [
                'consistency_checks_performed' => 2,
                'logical_flow_validated' => empty($discrepancies)
            ],
            'discrepancies' => $discrepancies,
            'status' => $status,
            'notes' => empty($discrepancies) ? 'Analytics consistency validated' : 'Analytics flow inconsistencies detected'
        ]);

        return [
            'reconciliation_id' => $reconciliationLog->id,
            'status' => $status,
            'booking_created_facts' => $bookingCreatedFacts,
            'payment_confirmed_facts' => $paymentConfirmedFacts,
            'ticket_issued_facts' => $ticketIssuedFacts,
            'discrepancies' => $discrepancies
        ];
    }

    /**
     * Get reconciliation summary for a date range
     */
    public function getReconciliationSummary(int $eventId, string $startDate, string $endDate): array
    {
        $reconciliations = FactReconciliationLog::where('event_id', $eventId)
            ->whereBetween('reconciliation_date', [$startDate, $endDate])
            ->orderBy('reconciled_at', 'desc')
            ->get();

        $summary = [
            'total_reconciliations' => $reconciliations->count(),
            'passed' => $reconciliations->where('status', FactReconciliationLog::STATUS_PASSED)->count(),
            'failed' => $reconciliations->where('status', FactReconciliationLog::STATUS_FAILED)->count(),
            'warnings' => $reconciliations->where('status', FactReconciliationLog::STATUS_WARNING)->count(),
            'by_type' => [],
            'recent_failures' => []
        ];

        // Group by reconciliation type
        foreach ($reconciliations->groupBy('reconciliation_type') as $type => $typeReconciliations) {
            $summary['by_type'][$type] = [
                'total' => $typeReconciliations->count(),
                'passed' => $typeReconciliations->where('status', FactReconciliationLog::STATUS_PASSED)->count(),
                'failed' => $typeReconciliations->where('status', FactReconciliationLog::STATUS_FAILED)->count(),
                'warnings' => $typeReconciliations->where('status', FactReconciliationLog::STATUS_WARNING)->count()
            ];
        }

        // Get recent failures for investigation
        $summary['recent_failures'] = $reconciliations
            ->where('status', FactReconciliationLog::STATUS_FAILED)
            ->take(5)
            ->map(function ($reconciliation) {
                return [
                    'id' => $reconciliation->id,
                    'type' => $reconciliation->reconciliation_type,
                    'date' => $reconciliation->reconciliation_date,
                    'discrepancy_count' => $reconciliation->getDiscrepancyCount(),
                    'reconciled_at' => $reconciliation->reconciled_at
                ];
            })
            ->values()
            ->toArray();

        return $summary;
    }

    /**
     * Detect data drift by comparing booking facts with current master data
     */
    public function detectDataDrift(int $eventId): array
    {
        $driftDetected = [];
        
        $bookingFacts = BookingFacts::join('bookings', 'booking_facts.booking_id', '=', 'bookings.id')
            ->where('bookings.event_id', $eventId)
            ->with('booking.event', 'booking.eventTicketType')
            ->get();

        foreach ($bookingFacts as $facts) {
            $booking = $facts->booking;
            $comparison = [];

            // Check event data drift
            $currentEvent = $booking->event;
            $originalEvent = $facts->getEventData();
            
            if ($currentEvent->name !== $originalEvent['name']) {
                $comparison['event_name_changed'] = [
                    'original' => $originalEvent['name'],
                    'current' => $currentEvent->name
                ];
            }

            if ($currentEvent->capacity !== $originalEvent['capacity']) {
                $comparison['event_capacity_changed'] = [
                    'original' => $originalEvent['capacity'],
                    'current' => $currentEvent->capacity
                ];
            }

            // Check ticket type data drift
            $currentTicketType = $booking->eventTicketType;
            $originalTicketType = $facts->getTicketTypeData();
            
            if ($currentTicketType->price !== $originalTicketType['price']) {
                $comparison['ticket_price_changed'] = [
                    'original' => $originalTicketType['price'],
                    'current' => $currentTicketType->price
                ];
            }

            if (!empty($comparison)) {
                $driftDetected[] = [
                    'booking_id' => $booking->id,
                    'booking_reference' => $booking->booking_reference,
                    'facts_captured_at' => $facts->created_at,
                    'changes' => $comparison
                ];
            }
        }

        return [
            'event_id' => $eventId,
            'drift_detected' => !empty($driftDetected),
            'affected_bookings' => count($driftDetected),
            'total_bookings_checked' => $bookingFacts->count(),
            'drift_details' => $driftDetected
        ];
    }
}
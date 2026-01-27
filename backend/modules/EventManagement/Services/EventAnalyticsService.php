<?php

namespace Modules\EventManagement\Services;

use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Models\Booking;
use Modules\EventManagement\Models\Ticket;
use Modules\EventManagement\Models\EventTicketType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * EventAnalyticsService
 * 
 * Handles event performance analytics and intelligence calculations.
 * Provides KPI calculations, ticket type performance metrics, sales velocity tracking,
 * and repeat attendee identification for business intelligence.
 * 
 * Requirements: 11.1, 11.2, 11.3, 11.4
 */
class EventAnalyticsService
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
     * Calculate comprehensive KPIs for an event
     * 
     * @param int $eventId
     * @return array Event KPI data
     */
    public function calculateEventKPIs(int $eventId): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();
        
        $event = Event::where('tenant_id', $tenantId)->findOrFail($eventId);
        
        // Get all confirmed bookings for this event
        $confirmedBookings = Booking::where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->with(['tickets', 'attendees'])
            ->get();

        // Calculate core metrics
        $ticketsSold = $confirmedBookings->sum(function ($booking) {
            return $booking->tickets->count();
        });

        $totalRevenue = $confirmedBookings->sum('total_amount');
        
        $capacityUtilization = $event->capacity > 0 
            ? ($ticketsSold / $event->capacity) * 100 
            : 0;

        // Calculate no-show metrics (tickets issued but not checked in)
        $totalTickets = Ticket::whereIn('booking_id', $confirmedBookings->pluck('id'))
            ->where('tenant_id', $tenantId)
            ->count();
            
        $checkedInTickets = Ticket::whereIn('booking_id', $confirmedBookings->pluck('id'))
            ->where('tenant_id', $tenantId)
            ->where('status', 'checked_in')
            ->count();

        $noShowCount = $totalTickets - $checkedInTickets;
        $noShowRate = $totalTickets > 0 ? ($noShowCount / $totalTickets) * 100 : 0;

        // Calculate customer metrics
        $uniqueCustomers = $confirmedBookings->unique('customer_id')->count();
        $repeatCustomers = $this->calculateRepeatCustomers($eventId, $tenantId);
        $repeatCustomerRate = $uniqueCustomers > 0 
            ? ($repeatCustomers / $uniqueCustomers) * 100 
            : 0;

        // Calculate time-based metrics
        $firstSale = $confirmedBookings->min('created_at');
        $lastSale = $confirmedBookings->max('created_at');
        $salesDurationHours = $firstSale && $lastSale 
            ? Carbon::parse($firstSale)->diffInHours(Carbon::parse($lastSale))
            : 0;

        $peakSalesVelocity = $this->calculatePeakSalesVelocity($eventId, $tenantId);
        $averageTicketPrice = $ticketsSold > 0 ? $totalRevenue / $ticketsSold : 0;

        $kpis = [
            'event_id' => $eventId,
            'tenant_id' => $tenantId,
            'tickets_sold' => $ticketsSold,
            'total_revenue' => $totalRevenue,
            'capacity_utilization' => round($capacityUtilization, 2),
            'no_show_count' => $noShowCount,
            'no_show_rate' => round($noShowRate, 2),
            'average_ticket_price' => round($averageTicketPrice, 2),
            'unique_customers' => $uniqueCustomers,
            'repeat_customers' => $repeatCustomers,
            'repeat_customer_rate' => round($repeatCustomerRate, 2),
            'first_sale_at' => $firstSale,
            'last_sale_at' => $lastSale,
            'sales_duration_hours' => $salesDurationHours,
            'peak_sales_velocity' => round($peakSalesVelocity, 2),
            'calculated_at' => now(),
        ];

        // Store in analytics table
        DB::table('event_analytics')->updateOrInsert(
            ['event_id' => $eventId, 'tenant_id' => $tenantId],
            $kpis
        );

        // Audit the calculation
        $this->auditService->logActivity(
            'analytics_calculation',
            'Event KPIs calculated',
            ['event_id' => $eventId, 'kpis' => $kpis]
        );

        return $kpis;
    }

    /**
     * Get ticket type performance metrics for an event
     * 
     * @param int $eventId
     * @return Collection Ticket type performance data
     */
    public function getTicketTypePerformance(int $eventId): Collection
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();
        
        $ticketTypes = EventTicketType::where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->get();

        $performanceData = collect();

        foreach ($ticketTypes as $ticketType) {
            $performance = $this->calculateTicketTypeMetrics($ticketType);
            $performanceData->push($performance);
            
            // Store in analytics table
            DB::table('ticket_type_analytics')->updateOrInsert(
                [
                    'event_id' => $eventId,
                    'ticket_type_id' => $ticketType->id,
                    'tenant_id' => $tenantId
                ],
                $performance
            );
        }

        // Calculate rankings
        $this->calculateTicketTypeRankings($eventId, $tenantId);

        return $performanceData;
    }

    /**
     * Analyze sales velocity patterns for an event
     * 
     * @param int $eventId
     * @return array Sales velocity analysis
     */
    public function analyzeSalesVelocity(int $eventId): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();
        
        // Get hourly sales data
        $hourlySales = DB::table('bookings')
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->select(
                DB::raw('DATE(created_at) as sale_date'),
                DB::raw('HOUR(created_at) as sale_hour'),
                DB::raw('COUNT(*) as bookings_count'),
                DB::raw('SUM(total_amount) as hourly_revenue')
            )
            ->groupBy('sale_date', 'sale_hour')
            ->orderBy('sale_date')
            ->orderBy('sale_hour')
            ->get();

        // Calculate velocity metrics
        $peakHour = $hourlySales->sortByDesc('bookings_count')->first();
        $averageHourlyBookings = $hourlySales->avg('bookings_count');
        $totalSalesDays = $hourlySales->unique('sale_date')->count();

        return [
            'event_id' => $eventId,
            'hourly_sales_data' => $hourlySales,
            'peak_sales_hour' => $peakHour,
            'average_hourly_bookings' => round($averageHourlyBookings, 2),
            'total_sales_days' => $totalSalesDays,
            'velocity_trend' => $this->calculateVelocityTrend($hourlySales),
        ];
    }

    /**
     * Identify repeat attendees across events
     * 
     * @param int $eventId
     * @return Collection Repeat attendee data
     */
    public function identifyRepeatAttendees(int $eventId): Collection
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();
        
        // Get customers who booked this event
        $currentEventCustomers = Booking::where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->pluck('customer_id')
            ->unique();

        // Find their booking history
        $repeatAttendees = collect();
        
        foreach ($currentEventCustomers as $customerId) {
            $customerBookings = Booking::where('customer_id', $customerId)
                ->where('tenant_id', $tenantId)
                ->where('status', 'confirmed')
                ->where('event_id', '!=', $eventId)
                ->with('event')
                ->get();

            if ($customerBookings->count() > 0) {
                $repeatAttendees->push([
                    'customer_id' => $customerId,
                    'previous_events_count' => $customerBookings->count(),
                    'previous_events' => $customerBookings->pluck('event.title'),
                    'total_spent_previously' => $customerBookings->sum('total_amount'),
                    'first_event_date' => $customerBookings->min('event.start_date'),
                    'customer_lifetime_value' => $this->calculateCustomerLifetimeValue($customerId, $tenantId),
                ]);
            }
        }

        return $repeatAttendees->sortByDesc('previous_events_count');
    }

    /**
     * Generate comprehensive analytics report with filters
     * 
     * @param array $filters
     * @return array Analytics report data
     */
    public function generateAnalyticsReport(array $filters = []): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();
        
        $query = DB::table('event_analytics')
            ->join('events', 'event_analytics.event_id', '=', 'events.id')
            ->where('event_analytics.tenant_id', $tenantId);

        // Apply filters
        if (isset($filters['date_from'])) {
            $query->where('events.start_date', '>=', $filters['date_from']);
        }
        
        if (isset($filters['date_to'])) {
            $query->where('events.start_date', '<=', $filters['date_to']);
        }
        
        if (isset($filters['event_ids'])) {
            $query->whereIn('event_analytics.event_id', $filters['event_ids']);
        }

        $analyticsData = $query->select(
            'event_analytics.*',
            'events.title as event_title',
            'events.start_date',
            'events.capacity as event_capacity'
        )->get();

        // Calculate aggregate metrics
        $totalRevenue = $analyticsData->sum('total_revenue');
        $totalTicketsSold = $analyticsData->sum('tickets_sold');
        $averageCapacityUtilization = $analyticsData->avg('capacity_utilization');
        $averageNoShowRate = $analyticsData->avg('no_show_rate');

        return [
            'summary' => [
                'total_events' => $analyticsData->count(),
                'total_revenue' => $totalRevenue,
                'total_tickets_sold' => $totalTicketsSold,
                'average_capacity_utilization' => round($averageCapacityUtilization, 2),
                'average_no_show_rate' => round($averageNoShowRate, 2),
            ],
            'events' => $analyticsData,
            'top_performing_events' => $analyticsData->sortByDesc('total_revenue')->take(10),
            'filters_applied' => $filters,
            'generated_at' => now(),
        ];
    }

    /**
     * Calculate repeat customers for an event
     */
    private function calculateRepeatCustomers(int $eventId, string $tenantId): int
    {
        $currentEventCustomers = Booking::where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->pluck('customer_id')
            ->unique();

        $repeatCount = 0;
        foreach ($currentEventCustomers as $customerId) {
            $previousBookings = Booking::where('customer_id', $customerId)
                ->where('tenant_id', $tenantId)
                ->where('status', 'confirmed')
                ->where('event_id', '!=', $eventId)
                ->count();
                
            if ($previousBookings > 0) {
                $repeatCount++;
            }
        }

        return $repeatCount;
    }

    /**
     * Calculate peak sales velocity for an event
     */
    private function calculatePeakSalesVelocity(int $eventId, string $tenantId): float
    {
        $hourlySales = DB::table('bookings')
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->select(
                DB::raw('DATE(created_at) as sale_date'),
                DB::raw('HOUR(created_at) as sale_hour'),
                DB::raw('COUNT(*) as bookings_count')
            )
            ->groupBy('sale_date', 'sale_hour')
            ->get();

        return $hourlySales->max('bookings_count') ?? 0;
    }

    /**
     * Calculate metrics for a specific ticket type
     */
    private function calculateTicketTypeMetrics(EventTicketType $ticketType): array
    {
        $tenantId = $this->tenantManager->getCurrentTenantId();
        
        // Get bookings for this ticket type
        $bookings = Booking::where('event_id', $ticketType->event_id)
            ->where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->whereHas('tickets', function ($query) use ($ticketType) {
                $query->where('ticket_type_id', $ticketType->id);
            })
            ->with('tickets')
            ->get();

        $ticketsSold = $bookings->sum(function ($booking) use ($ticketType) {
            return $booking->tickets->where('ticket_type_id', $ticketType->id)->count();
        });

        $revenueGenerated = $ticketsSold * $ticketType->price;
        $sellThroughRate = $ticketType->capacity > 0 
            ? ($ticketsSold / $ticketType->capacity) * 100 
            : 0;

        $firstSale = $bookings->min('created_at');
        $soldOutAt = $sellThroughRate >= 100 ? $bookings->max('created_at') : null;
        $timeToSellOut = $firstSale && $soldOutAt 
            ? Carbon::parse($firstSale)->diffInHours(Carbon::parse($soldOutAt))
            : null;

        return [
            'event_id' => $ticketType->event_id,
            'ticket_type_id' => $ticketType->id,
            'tenant_id' => $tenantId,
            'tickets_sold' => $ticketsSold,
            'tickets_available' => $ticketType->capacity,
            'sell_through_rate' => round($sellThroughRate, 2),
            'revenue_generated' => $revenueGenerated,
            'average_sale_price' => $ticketType->price,
            'first_sale_at' => $firstSale,
            'sold_out_at' => $soldOutAt,
            'time_to_sell_out_hours' => $timeToSellOut,
            'unique_buyers' => $bookings->unique('customer_id')->count(),
            'calculated_at' => now(),
        ];
    }

    /**
     * Calculate rankings for ticket types within an event
     */
    private function calculateTicketTypeRankings(int $eventId, string $tenantId): void
    {
        $ticketTypes = DB::table('ticket_type_analytics')
            ->where('event_id', $eventId)
            ->where('tenant_id', $tenantId)
            ->orderBy('revenue_generated', 'desc')
            ->get();

        foreach ($ticketTypes as $index => $ticketType) {
            DB::table('ticket_type_analytics')
                ->where('id', $ticketType->id)
                ->update(['revenue_rank' => $index + 1]);
        }
    }

    /**
     * Calculate velocity trend from hourly sales data
     */
    private function calculateVelocityTrend(Collection $hourlySales): string
    {
        if ($hourlySales->count() < 2) {
            return 'insufficient_data';
        }

        $firstHalf = $hourlySales->take($hourlySales->count() / 2);
        $secondHalf = $hourlySales->skip($hourlySales->count() / 2);

        $firstHalfAvg = $firstHalf->avg('bookings_count');
        $secondHalfAvg = $secondHalf->avg('bookings_count');

        if ($secondHalfAvg > $firstHalfAvg * 1.1) {
            return 'increasing';
        } elseif ($secondHalfAvg < $firstHalfAvg * 0.9) {
            return 'decreasing';
        } else {
            return 'stable';
        }
    }

    /**
     * Calculate customer lifetime value
     */
    private function calculateCustomerLifetimeValue(int $customerId, string $tenantId): float
    {
        return Booking::where('customer_id', $customerId)
            ->where('tenant_id', $tenantId)
            ->where('status', 'confirmed')
            ->sum('total_amount');
    }
}
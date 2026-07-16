<?php

namespace Modules\EventManagement\Jobs;

use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\EventManagement\Models\Event;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Services\EventAnalyticsService;
use Stancl\Tenancy\Facades\Tenancy;

/**
 * AnalyticsAggregationJob
 *
 * Handles daily and hourly aggregation of analytics data for events.
 * Processes sales metrics, customer data, and performance indicators
 * with proper error handling and monitoring.
 *
 * Requirements: 11.1, 11.5
 */
class AnalyticsAggregationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private string $tenantId;

    private string $aggregationType; // 'hourly' or 'daily'

    private ?Carbon $targetDate;

    private ?int $targetHour;

    /**
     * Create a new job instance.
     */
    public function __construct(
        string $tenantId,
        string $aggregationType = 'daily',
        ?Carbon $targetDate = null,
        ?int $targetHour = null
    ) {
        $this->tenantId = $tenantId;
        $this->aggregationType = $aggregationType;
        $this->targetDate = $targetDate ?? now()->subDay();
        $this->targetHour = $targetHour;
    }

    /**
     * Execute the job.
     */
    public function handle(
        EventAnalyticsService $analyticsService,
        AuditService $auditService
    ): void {
        try {
            Log::info('Starting analytics aggregation', [
                'tenant_id' => $this->tenantId,
                'type' => $this->aggregationType,
                'date' => $this->targetDate->toDateString(),
                'hour' => $this->targetHour,
            ]);

            // Initialize tenancy properly using stancl/tenancy
            Tenancy::initialize($this->tenantId);

            if ($this->aggregationType === 'hourly') {
                $this->processHourlyAggregation($analyticsService);
            } else {
                $this->processDailyAggregation($analyticsService);
            }

            // Update real-time analytics for live events
            $this->updateLiveEventAnalytics($analyticsService);

            // Audit the aggregation
            $auditService->logActivity(
                'analytics_aggregation',
                "Analytics aggregation completed: {$this->aggregationType}",
                [
                    'tenant_id' => $this->tenantId,
                    'date' => $this->targetDate->toDateString(),
                    'hour' => $this->targetHour,
                ]
            );

            Log::info('Analytics aggregation completed successfully', [
                'tenant_id' => $this->tenantId,
                'type' => $this->aggregationType,
            ]);

        } catch (Exception $e) {
            Log::error('Analytics aggregation failed', [
                'tenant_id' => $this->tenantId,
                'type' => $this->aggregationType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger job retry mechanism
            throw $e;
        } finally {
            // End tenancy to clean up
            try {
                Tenancy::end();
            } catch (Exception $endEx) {
                // Ignore errors during tenancy cleanup
            }
        }
    }

    /**
     * Process hourly analytics aggregation
     */
    private function processHourlyAggregation(EventAnalyticsService $analyticsService): void
    {
        $startTime = $this->targetDate->copy()->hour($this->targetHour)->startOfHour();
        $endTime = $startTime->copy()->endOfHour();

        // Get all events that had activity during this hour
        $activeEvents = DB::table('bookings')
            ->where('tenant_id', $this->tenantId)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->distinct()
            ->pluck('event_id');

        foreach ($activeEvents as $eventId) {
            $this->aggregateHourlyMetrics($eventId, $startTime, $endTime);
        }
    }

    /**
     * Process daily analytics aggregation
     */
    private function processDailyAggregation(EventAnalyticsService $analyticsService): void
    {
        $startTime = $this->targetDate->copy()->startOfDay();
        $endTime = $this->targetDate->copy()->endOfDay();

        // Get all events that had activity during this day
        $activeEvents = DB::table('bookings')
            ->where('tenant_id', $this->tenantId)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->distinct()
            ->pluck('event_id');

        foreach ($activeEvents as $eventId) {
            $this->aggregateDailyMetrics($eventId, $startTime, $endTime);
        }

        // Also update event-level KPIs for events that ended today
        $endedEvents = Event::where('tenant_id', $this->tenantId)
            ->whereDate('end_date', $this->targetDate)
            ->pluck('id');

        foreach ($endedEvents as $eventId) {
            $analyticsService->calculateEventKPIs($eventId);
            $analyticsService->getTicketTypePerformance($eventId);
        }
    }

    /**
     * Aggregate hourly metrics for a specific event
     */
    private function aggregateHourlyMetrics(int $eventId, Carbon $startTime, Carbon $endTime): void
    {
        // Get bookings for this hour
        $bookings = DB::table('bookings')
            ->where('event_id', $eventId)
            ->where('tenant_id', $this->tenantId)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->get();

        if ($bookings->isEmpty()) {
            return;
        }

        // Calculate metrics
        $confirmedBookings = $bookings->where('status', 'confirmed');
        $cancelledBookings = $bookings->where('status', 'cancelled');

        $ticketsSold = $this->calculateTicketsSoldForBookings($confirmedBookings->pluck('id'));
        $revenueGenerated = $confirmedBookings->sum('total_amount');
        $uniqueCustomers = $confirmedBookings->unique('customer_id')->count();

        // Identify new vs returning customers
        $newCustomers = $this->countNewCustomers(
            $confirmedBookings->pluck('customer_id'),
            $startTime
        );
        $returningCustomers = $uniqueCustomers - $newCustomers;

        $totalBookings = $confirmedBookings->count();
        $cancelledBookingsCount = $cancelledBookings->count();
        $cancellationRate = $totalBookings > 0
            ? ($cancelledBookingsCount / ($totalBookings + $cancelledBookingsCount)) * 100
            : 0;

        $averageTicketsPerBooking = $totalBookings > 0
            ? $ticketsSold / $totalBookings
            : 0;

        // Determine if this is a peak hour
        $isPeakHour = $this->isPeakHour($eventId, $totalBookings, $startTime);
        $isWeekend = $startTime->isWeekend();
        $dayOfWeek = $startTime->format('l');

        // Store the metrics
        DB::table('daily_sales_metrics')->updateOrInsert(
            [
                'event_id' => $eventId,
                'tenant_id' => $this->tenantId,
                'metric_date' => $startTime->toDateString(),
                'metric_hour' => $startTime->hour,
            ],
            [
                'tickets_sold' => $ticketsSold,
                'revenue_generated' => $revenueGenerated,
                'unique_customers' => $uniqueCustomers,
                'new_customers' => $newCustomers,
                'returning_customers' => $returningCustomers,
                'total_bookings' => $totalBookings,
                'average_tickets_per_booking' => round($averageTicketsPerBooking, 2),
                'cancelled_bookings' => $cancelledBookingsCount,
                'cancellation_rate' => round($cancellationRate, 2),
                'is_peak_hour' => $isPeakHour,
                'is_weekend' => $isWeekend,
                'day_of_week' => $dayOfWeek,
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Aggregate daily metrics for a specific event
     */
    private function aggregateDailyMetrics(int $eventId, Carbon $startTime, Carbon $endTime): void
    {
        // Get all bookings for this day
        $bookings = DB::table('bookings')
            ->where('event_id', $eventId)
            ->where('tenant_id', $this->tenantId)
            ->whereBetween('created_at', [$startTime, $endTime])
            ->get();

        if ($bookings->isEmpty()) {
            return;
        }

        $confirmedBookings = $bookings->where('status', 'confirmed');
        $cancelledBookings = $bookings->where('status', 'cancelled');

        $ticketsSold = $this->calculateTicketsSoldForBookings($confirmedBookings->pluck('id'));
        $revenueGenerated = $confirmedBookings->sum('total_amount');
        $uniqueCustomers = $confirmedBookings->unique('customer_id')->count();

        $newCustomers = $this->countNewCustomers(
            $confirmedBookings->pluck('customer_id'),
            $startTime
        );
        $returningCustomers = $uniqueCustomers - $newCustomers;

        $totalBookings = $confirmedBookings->count();
        $cancelledBookingsCount = $cancelledBookings->count();
        $cancellationRate = $totalBookings > 0
            ? ($cancelledBookingsCount / ($totalBookings + $cancelledBookingsCount)) * 100
            : 0;

        $averageTicketsPerBooking = $totalBookings > 0
            ? $ticketsSold / $totalBookings
            : 0;

        $isWeekend = $startTime->isWeekend();
        $dayOfWeek = $startTime->format('l');

        // Store daily aggregate (no hour specified)
        DB::table('daily_sales_metrics')->updateOrInsert(
            [
                'event_id' => $eventId,
                'tenant_id' => $this->tenantId,
                'metric_date' => $startTime->toDateString(),
                'metric_hour' => null, // Daily aggregate
            ],
            [
                'tickets_sold' => $ticketsSold,
                'revenue_generated' => $revenueGenerated,
                'unique_customers' => $uniqueCustomers,
                'new_customers' => $newCustomers,
                'returning_customers' => $returningCustomers,
                'total_bookings' => $totalBookings,
                'average_tickets_per_booking' => round($averageTicketsPerBooking, 2),
                'cancelled_bookings' => $cancelledBookingsCount,
                'cancellation_rate' => round($cancellationRate, 2),
                'is_weekend' => $isWeekend,
                'day_of_week' => $dayOfWeek,
                'updated_at' => now(),
            ]
        );
    }

    /**
     * Update real-time analytics for live events
     */
    private function updateLiveEventAnalytics(EventAnalyticsService $analyticsService): void
    {
        // Get events that are currently live or starting soon
        $liveEvents = Event::where('tenant_id', $this->tenantId)
            ->where('start_date', '<=', now()->addHours(2))
            ->where('end_date', '>=', now()->subHours(2))
            ->pluck('id');

        foreach ($liveEvents as $eventId) {
            $analyticsService->calculateEventKPIs($eventId);
        }
    }

    /**
     * Calculate tickets sold for given booking IDs
     */
    private function calculateTicketsSoldForBookings($bookingIds): int
    {
        if ($bookingIds->isEmpty()) {
            return 0;
        }

        return DB::table('tickets')
            ->whereIn('booking_id', $bookingIds)
            ->where('tenant_id', $this->tenantId)
            ->count();
    }

    /**
     * Count new customers (first booking ever)
     */
    private function countNewCustomers($customerIds, Carbon $beforeDate): int
    {
        if ($customerIds->isEmpty()) {
            return 0;
        }

        $newCustomers = 0;
        foreach ($customerIds->unique() as $customerId) {
            $previousBookings = DB::table('bookings')
                ->where('customer_id', $customerId)
                ->where('tenant_id', $this->tenantId)
                ->where('created_at', '<', $beforeDate)
                ->count();

            if ($previousBookings === 0) {
                $newCustomers++;
            }
        }

        return $newCustomers;
    }

    /**
     * Determine if this hour is a peak hour for the event
     */
    private function isPeakHour(int $eventId, int $currentHourBookings, Carbon $hour): bool
    {
        // Get average bookings per hour for this event
        $averageHourlyBookings = DB::table('daily_sales_metrics')
            ->where('event_id', $eventId)
            ->where('tenant_id', $this->tenantId)
            ->whereNotNull('metric_hour')
            ->avg('total_bookings');

        // Consider it peak if 50% above average
        return $currentHourBookings > ($averageHourlyBookings * 1.5);
    }

    /**
     * Handle job failure
     */
    public function failed(Exception $exception): void
    {
        Log::error('Analytics aggregation job failed permanently', [
            'tenant_id' => $this->tenantId,
            'type' => $this->aggregationType,
            'date' => $this->targetDate?->toDateString(),
            'hour' => $this->targetHour,
            'error' => $exception->getMessage(),
        ]);
    }
}

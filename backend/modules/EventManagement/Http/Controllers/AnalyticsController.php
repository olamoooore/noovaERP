<?php

namespace Modules\EventManagement\Http\Controllers;

use Modules\EventManagement\Services\EventAnalyticsService;
use Modules\EventManagement\Services\AuditService;
use Modules\EventManagement\Http\Requests\AnalyticsReportRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Carbon\Carbon;

/**
 * AnalyticsController
 * 
 * Handles analytics and business intelligence endpoints for event performance.
 * Provides KPIs, sales trends, attendee insights with admin-only access control.
 * All endpoints require admin role and include comprehensive audit logging.
 * 
 * Requirements: 11.5
 */
class AnalyticsController extends Controller
{
    private EventAnalyticsService $analyticsService;
    private AuditService $auditService;

    public function __construct(
        EventAnalyticsService $analyticsService,
        AuditService $auditService
    ) {
        $this->analyticsService = $analyticsService;
        $this->auditService = $auditService;

        // Apply middleware for admin-only access
        $this->middleware(['auth:sanctum', 'rbac.event_management']);
        $this->middleware('rate_limit:analytics')->only([
            'generateReport',
            'exportReport'
        ]);
    }

    /**
     * Get event KPIs for a specific event
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function getEventKPIs(int $eventId): JsonResponse
    {
        try {
            $kpis = $this->analyticsService->calculateEventKPIs($eventId);

            $this->auditService->logActivity(
                'analytics_access',
                'Event KPIs accessed',
                ['event_id' => $eventId]
            );

            return response()->json([
                'success' => true,
                'data' => $kpis,
                'message' => 'Event KPIs retrieved successfully'
            ]);

        } catch (\Exception $e) {
            $this->auditService->logActivity(
                'analytics_error',
                'Failed to retrieve event KPIs',
                ['event_id' => $eventId, 'error' => $e->getMessage()]
            );

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve event KPIs',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get ticket type performance for an event
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function getTicketTypePerformance(int $eventId): JsonResponse
    {
        try {
            $performance = $this->analyticsService->getTicketTypePerformance($eventId);

            $this->auditService->logActivity(
                'analytics_access',
                'Ticket type performance accessed',
                ['event_id' => $eventId]
            );

            return response()->json([
                'success' => true,
                'data' => $performance,
                'message' => 'Ticket type performance retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve ticket type performance',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get sales velocity analysis for an event
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function getSalesVelocity(int $eventId): JsonResponse
    {
        try {
            $velocity = $this->analyticsService->analyzeSalesVelocity($eventId);

            $this->auditService->logActivity(
                'analytics_access',
                'Sales velocity analysis accessed',
                ['event_id' => $eventId]
            );

            return response()->json([
                'success' => true,
                'data' => $velocity,
                'message' => 'Sales velocity analysis retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sales velocity analysis',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get repeat attendee insights for an event
     * 
     * @param int $eventId
     * @return JsonResponse
     */
    public function getRepeatAttendees(int $eventId): JsonResponse
    {
        try {
            $repeatAttendees = $this->analyticsService->identifyRepeatAttendees($eventId);

            $this->auditService->logActivity(
                'analytics_access',
                'Repeat attendee insights accessed',
                ['event_id' => $eventId]
            );

            return response()->json([
                'success' => true,
                'data' => $repeatAttendees,
                'message' => 'Repeat attendee insights retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve repeat attendee insights',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Generate comprehensive analytics report with filters
     * 
     * @param AnalyticsReportRequest $request
     * @return JsonResponse
     */
    public function generateReport(AnalyticsReportRequest $request): JsonResponse
    {
        try {
            $filters = $request->validated();
            $report = $this->analyticsService->generateAnalyticsReport($filters);

            $this->auditService->logActivity(
                'analytics_report',
                'Analytics report generated',
                ['filters' => $filters]
            );

            return response()->json([
                'success' => true,
                'data' => $report,
                'message' => 'Analytics report generated successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate analytics report',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get sales trends over time
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getSalesTrends(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'date_from' => 'required|date',
                'date_to' => 'required|date|after_or_equal:date_from',
                'event_ids' => 'sometimes|array',
                'event_ids.*' => 'integer|exists:events,id',
                'granularity' => 'sometimes|in:hourly,daily,weekly',
            ]);

            $dateFrom = Carbon::parse($request->date_from);
            $dateTo = Carbon::parse($request->date_to);
            $granularity = $request->input('granularity', 'daily');
            $eventIds = $request->input('event_ids');

            $query = DB::table('daily_sales_metrics')
                ->whereBetween('metric_date', [$dateFrom, $dateTo]);

            if ($eventIds) {
                $query->whereIn('event_id', $eventIds);
            }

            if ($granularity === 'hourly') {
                $query->whereNotNull('metric_hour');
                $trends = $query->select(
                    'metric_date',
                    'metric_hour',
                    DB::raw('SUM(tickets_sold) as total_tickets'),
                    DB::raw('SUM(revenue_generated) as total_revenue'),
                    DB::raw('SUM(total_bookings) as total_bookings')
                )
                    ->groupBy('metric_date', 'metric_hour')
                    ->orderBy('metric_date')
                    ->orderBy('metric_hour')
                    ->get();
            } else {
                $query->whereNull('metric_hour'); // Daily aggregates only

                if ($granularity === 'weekly') {
                    $trends = $query->select(
                        DB::raw('YEARWEEK(metric_date) as week'),
                        DB::raw('MIN(metric_date) as week_start'),
                        DB::raw('SUM(tickets_sold) as total_tickets'),
                        DB::raw('SUM(revenue_generated) as total_revenue'),
                        DB::raw('SUM(total_bookings) as total_bookings')
                    )
                        ->groupBy('week')
                        ->orderBy('week')
                        ->get();
                } else {
                    $trends = $query->select(
                        'metric_date',
                        DB::raw('SUM(tickets_sold) as total_tickets'),
                        DB::raw('SUM(revenue_generated) as total_revenue'),
                        DB::raw('SUM(total_bookings) as total_bookings')
                    )
                        ->groupBy('metric_date')
                        ->orderBy('metric_date')
                        ->get();
                }
            }

            $this->auditService->logActivity(
                'analytics_access',
                'Sales trends accessed',
                [
                    'date_from' => $dateFrom->toDateString(),
                    'date_to' => $dateTo->toDateString(),
                    'granularity' => $granularity
                ]
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'trends' => $trends,
                    'granularity' => $granularity,
                    'date_range' => [
                        'from' => $dateFrom->toDateString(),
                        'to' => $dateTo->toDateString()
                    ]
                ],
                'message' => 'Sales trends retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sales trends',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get customer insights and segmentation
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function getCustomerInsights(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'event_ids' => 'sometimes|array',
                'event_ids.*' => 'integer|exists:events,id',
                'date_from' => 'sometimes|date',
                'date_to' => 'sometimes|date|after_or_equal:date_from',
            ]);

            $query = DB::table('bookings')
                ->where('status', 'confirmed')
                ->where('tenant_id', auth()->user()->tenant_id);

            if ($request->has('event_ids')) {
                $query->whereIn('event_id', $request->event_ids);
            }

            if ($request->has('date_from')) {
                $query->where('created_at', '>=', $request->date_from);
            }

            if ($request->has('date_to')) {
                $query->where('created_at', '<=', $request->date_to);
            }

            // Customer segmentation analysis
            $customerSegments = $query->select(
                'customer_id',
                DB::raw('COUNT(*) as booking_count'),
                DB::raw('SUM(total_amount) as total_spent'),
                DB::raw('AVG(total_amount) as avg_booking_value'),
                DB::raw('MIN(created_at) as first_booking'),
                DB::raw('MAX(created_at) as last_booking')
            )
                ->groupBy('customer_id')
                ->get();

            // Segment customers
            $segments = [
                'new_customers' => $customerSegments->where('booking_count', 1)->count(),
                'repeat_customers' => $customerSegments->where('booking_count', '>', 1)->count(),
                'vip_customers' => $customerSegments->where('total_spent', '>', 1000)->count(),
                'high_frequency' => $customerSegments->where('booking_count', '>', 5)->count(),
            ];

            $insights = [
                'total_customers' => $customerSegments->count(),
                'segments' => $segments,
                'average_customer_value' => $customerSegments->avg('total_spent'),
                'average_bookings_per_customer' => $customerSegments->avg('booking_count'),
                'customer_retention_rate' => $segments['repeat_customers'] > 0
                    ? ($segments['repeat_customers'] / $customerSegments->count()) * 100
                    : 0,
            ];

            $this->auditService->logActivity(
                'analytics_access',
                'Customer insights accessed',
                $request->only(['event_ids', 'date_from', 'date_to'])
            );

            return response()->json([
                'success' => true,
                'data' => $insights,
                'message' => 'Customer insights retrieved successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve customer insights',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Export analytics report to CSV
     * 
     * @param AnalyticsReportRequest $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function exportReport(AnalyticsReportRequest $request)
    {
        try {
            $filters = $request->validated();
            $report = $this->analyticsService->generateAnalyticsReport($filters);

            $this->auditService->logActivity(
                'analytics_export',
                'Analytics report exported',
                ['filters' => $filters]
            );

            $filename = 'analytics_report_' . now()->format('Y-m-d_H-i-s') . '.csv';

            return Response::streamDownload(function () use ($report) {
                $handle = fopen('php://output', 'w');

                // CSV headers
                fputcsv($handle, [
                    'Event ID',
                    'Event Title',
                    'Start Date',
                    'Tickets Sold',
                    'Total Revenue',
                    'Capacity Utilization (%)',
                    'No Show Rate (%)',
                    'Unique Customers',
                    'Repeat Customers',
                    'Average Ticket Price',
                    'Sales Duration (Hours)',
                    'Peak Sales Velocity'
                ]);

                // CSV data
                foreach ($report['events'] as $event) {
                    fputcsv($handle, [
                        $event->event_id,
                        $event->event_title,
                        $event->start_date,
                        $event->tickets_sold,
                        $event->total_revenue,
                        $event->capacity_utilization,
                        $event->no_show_rate,
                        $event->unique_customers,
                        $event->repeat_customers,
                        $event->average_ticket_price,
                        $event->sales_duration_hours,
                        $event->peak_sales_velocity
                    ]);
                }

                fclose($handle);
            }, $filename, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export analytics report',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    /**
     * Get analytics dashboard summary
     * 
     * @return JsonResponse
     */
    public function getDashboardSummary(): JsonResponse
    {
        try {
            $tenantId = auth()->user()->tenant_id;

            // Get summary metrics for the last 30 days
            $thirtyDaysAgo = now()->subDays(30);

            $summary = DB::table('event_analytics')
                ->join('events', 'event_analytics.event_id', '=', 'events.id')
                ->where('event_analytics.tenant_id', $tenantId)
                ->where('events.start_date', '>=', $thirtyDaysAgo)
                ->select(
                    DB::raw('COUNT(*) as total_events'),
                    DB::raw('SUM(tickets_sold) as total_tickets_sold'),
                    DB::raw('SUM(total_revenue) as total_revenue'),
                    DB::raw('AVG(capacity_utilization) as avg_capacity_utilization'),
                    DB::raw('AVG(no_show_rate) as avg_no_show_rate')
                )
                ->first();

            // Get top performing events
            $topEvents = DB::table('event_analytics')
                ->join('events', 'event_analytics.event_id', '=', 'events.id')
                ->where('event_analytics.tenant_id', $tenantId)
                ->where('events.start_date', '>=', $thirtyDaysAgo)
                ->select(
                    'events.title',
                    'event_analytics.total_revenue',
                    'event_analytics.tickets_sold',
                    'event_analytics.capacity_utilization'
                )
                ->orderBy('total_revenue', 'desc')
                ->limit(5)
                ->get();

            $this->auditService->logActivity(
                'analytics_access',
                'Dashboard summary accessed',
                []
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => $summary,
                    'top_events' => $topEvents,
                    'period' => '30 days'
                ],
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
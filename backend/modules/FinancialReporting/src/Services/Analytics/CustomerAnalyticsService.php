<?php

namespace Modules\FinancialReporting\Services\Analytics;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\FinancialReporting\DTOs\ReportFilter;

class CustomerAnalyticsService
{
    /**
     * Calculate Customer Lifetime Value (CLV)
     */
    public function getCustomerLifetimeValue(ReportFilter $filter): array
    {
        if (!$this->tableExists('invoices') || !$this->tableExists('customers')) {
            return [
                'title' => 'Customer Lifetime Value',
                'data' => [],
                'message' => 'Required tables not available',
            ];
        }

        $startDate = $filter->startDate ?? Carbon::now()->subYear();
        $endDate = $filter->endDate ?? Carbon::now();

        $customers = DB::table('customers as c')
            ->leftJoin('invoices as i', function($join) use ($startDate, $endDate) {
                $join->on('i.customer_id', '=', 'c.id')
                    ->whereBetween('i.invoice_date', [$startDate, $endDate])
                    ->whereIn('i.status', ['paid', 'partial']);
            })
            ->select([
                'c.id',
                'c.name',
                'c.email',
                'c.created_at',
                DB::raw('COUNT(DISTINCT i.id) as total_orders'),
                DB::raw('COALESCE(SUM(i.total), 0) as total_revenue'),
                DB::raw('COALESCE(AVG(i.total), 0) as average_order_value'),
                DB::raw('MAX(i.invoice_date) as last_purchase_date'),
                DB::raw('MIN(i.invoice_date) as first_purchase_date'),
            ])
            ->groupBy('c.id', 'c.name', 'c.email', 'c.created_at')
            ->having(DB::raw('COUNT(DISTINCT i.id)'), '>', 0)
            ->get();

        $data = $customers->map(function($customer) use ($startDate, $endDate) {
            $daysSinceFirstPurchase = $customer->first_purchase_date 
                ? Carbon::parse($customer->first_purchase_date)->diffInDays($endDate)
                : 0;
            
            $purchaseFrequency = $daysSinceFirstPurchase > 0 
                ? $customer->total_orders / ($daysSinceFirstPurchase / 30) // orders per month
                : 0;

            // Simple CLV = Average Order Value × Purchase Frequency × Customer Lifespan (in months)
            // Assuming 24 month average lifespan
            $estimatedLifespan = 24;
            $clv = $customer->average_order_value * $purchaseFrequency * $estimatedLifespan;

            return [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'total_orders' => (int) $customer->total_orders,
                'total_revenue' => round($customer->total_revenue, 2),
                'average_order_value' => round($customer->average_order_value, 2),
                'purchase_frequency' => round($purchaseFrequency, 2),
                'customer_lifetime_value' => round($clv, 2),
                'first_purchase_date' => $customer->first_purchase_date,
                'last_purchase_date' => $customer->last_purchase_date,
                'days_since_last_purchase' => $customer->last_purchase_date 
                    ? Carbon::parse($customer->last_purchase_date)->diffInDays($endDate)
                    : null,
            ];
        })->sortByDesc('customer_lifetime_value')->values()->toArray();

        $summary = [
            'total_customers' => count($data),
            'total_revenue' => round(collect($data)->sum('total_revenue'), 2),
            'average_clv' => count($data) > 0 ? round(collect($data)->avg('customer_lifetime_value'), 2) : 0,
            'highest_clv' => count($data) > 0 ? round(collect($data)->max('customer_lifetime_value'), 2) : 0,
            'average_orders_per_customer' => count($data) > 0 ? round(collect($data)->avg('total_orders'), 2) : 0,
        ];

        return [
            'title' => 'Customer Lifetime Value',
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'data' => $data,
            'summary' => $summary,
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Analyze purchase patterns
     */
    public function getPurchasePatterns(ReportFilter $filter): array
    {
        if (!$this->tableExists('invoices')) {
            return [
                'title' => 'Purchase Patterns',
                'data' => [],
                'message' => 'Invoices table not available',
            ];
        }

        $startDate = $filter->startDate ?? Carbon::now()->subYear();
        $endDate = $filter->endDate ?? Carbon::now();

        // Analyze by day of week
        $byDayOfWeek = DB::table('invoices')
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->whereIn('status', ['paid', 'partial'])
            ->select([
                DB::raw('EXTRACT(DOW FROM invoice_date) as day_of_week'),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(total) as total_revenue'),
                DB::raw('AVG(total) as average_order_value'),
            ])
            ->groupBy('day_of_week')
            ->orderBy('day_of_week')
            ->get()
            ->map(fn($item) => [
                'day' => $this->getDayName((int) $item->day_of_week),
                'order_count' => (int) $item->order_count,
                'revenue' => round($item->total_revenue, 2),
                'average_order_value' => round($item->average_order_value, 2),
            ]);

        // Analyze by hour of day
        $byHourOfDay = DB::table('invoices')
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->whereIn('status', ['paid', 'partial'])
            ->select([
                DB::raw('EXTRACT(HOUR FROM invoice_date) as hour'),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(total) as total_revenue'),
            ])
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->map(fn($item) => [
                'hour' => (int) $item->hour,
                'order_count' => (int) $item->order_count,
                'revenue' => round($item->total_revenue, 2),
            ]);

        // Analyze by month
        $byMonth = DB::table('invoices')
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->whereIn('status', ['paid', 'partial'])
            ->select([
                DB::raw("TO_CHAR(invoice_date, 'YYYY-MM') as month"),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(total) as total_revenue'),
            ])
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn($item) => [
                'month' => $item->month,
                'order_count' => (int) $item->order_count,
                'revenue' => round($item->total_revenue, 2),
            ]);

        return [
            'title' => 'Purchase Patterns',
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'by_day_of_week' => $byDayOfWeek->toArray(),
            'by_hour_of_day' => $byHourOfDay->toArray(),
            'by_month' => $byMonth->toArray(),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Calculate customer retention rate
     */
    public function getCustomerRetention(ReportFilter $filter): array
    {
        if (!$this->tableExists('invoices') || !$this->tableExists('customers')) {
            return [
                'title' => 'Customer Retention',
                'data' => [],
                'message' => 'Required tables not available',
            ];
        }

        $startDate = $filter->startDate ?? Carbon::now()->subYear();
        $endDate = $filter->endDate ?? Carbon::now();

        // Get customers who made purchases in the period
        $customersInPeriod = DB::table('invoices')
            ->whereBetween('invoice_date', [$startDate, $endDate])
            ->whereIn('status', ['paid', 'partial'])
            ->distinct()
            ->pluck('customer_id');

        // Get customers who made purchases before the period
        $customersBefore = DB::table('invoices')
            ->where('invoice_date', '<', $startDate)
            ->whereIn('status', ['paid', 'partial'])
            ->distinct()
            ->pluck('customer_id');

        // Calculate retention
        $retainedCustomers = $customersInPeriod->intersect($customersBefore);
        $newCustomers = $customersInPeriod->diff($customersBefore);
        $churnedCustomers = $customersBefore->diff($customersInPeriod);

        $retentionRate = $customersBefore->count() > 0 
            ? ($retainedCustomers->count() / $customersBefore->count()) * 100
            : 0;

        $churnRate = $customersBefore->count() > 0
            ? ($churnedCustomers->count() / $customersBefore->count()) * 100
            : 0;

        // Cohort analysis by month
        $cohorts = DB::table('customers as c')
            ->join('invoices as i', 'i.customer_id', '=', 'c.id')
            ->whereBetween('i.invoice_date', [$startDate, $endDate])
            ->whereIn('i.status', ['paid', 'partial'])
            ->select([
                DB::raw("TO_CHAR(c.created_at, 'YYYY-MM') as cohort_month"),
                DB::raw('COUNT(DISTINCT c.id) as customer_count'),
                DB::raw('SUM(i.total) as total_revenue'),
            ])
            ->groupBy('cohort_month')
            ->orderBy('cohort_month')
            ->get();

        return [
            'title' => 'Customer Retention',
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'metrics' => [
                'total_customers_in_period' => $customersInPeriod->count(),
                'existing_customers' => $customersBefore->count(),
                'retained_customers' => $retainedCustomers->count(),
                'new_customers' => $newCustomers->count(),
                'churned_customers' => $churnedCustomers->count(),
                'retention_rate' => round($retentionRate, 2),
                'churn_rate' => round($churnRate, 2),
            ],
            'cohorts' => $cohorts->map(fn($item) => [
                'cohort_month' => $item->cohort_month,
                'customer_count' => (int) $item->customer_count,
                'total_revenue' => round($item->total_revenue, 2),
            ])->toArray(),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * RFM (Recency, Frequency, Monetary) Segmentation
     */
    public function getRFMSegmentation(ReportFilter $filter): array
    {
        if (!$this->tableExists('invoices') || !$this->tableExists('customers')) {
            return [
                'title' => 'RFM Segmentation',
                'data' => [],
                'message' => 'Required tables not available',
            ];
        }

        $endDate = $filter->endDate ?? Carbon::now();
        $startDate = $filter->startDate ?? Carbon::now()->subYear();

        // Calculate RFM scores
        $customers = DB::table('customers as c')
            ->join('invoices as i', 'i.customer_id', '=', 'c.id')
            ->whereBetween('i.invoice_date', [$startDate, $endDate])
            ->whereIn('i.status', ['paid', 'partial'])
            ->select([
                'c.id',
                'c.name',
                'c.email',
                DB::raw('MAX(i.invoice_date) as last_purchase_date'),
                DB::raw('COUNT(DISTINCT i.id) as frequency'),
                DB::raw('SUM(i.total) as monetary'),
            ])
            ->groupBy('c.id', 'c.name', 'c.email')
            ->get();

        // Calculate recency in days
        $data = $customers->map(function($customer) use ($endDate) {
            $recency = Carbon::parse($customer->last_purchase_date)->diffInDays($endDate);
            
            // Score recency (lower is better)
            $recencyScore = $recency <= 30 ? 5 : 
                           ($recency <= 60 ? 4 : 
                           ($recency <= 90 ? 3 : 
                           ($recency <= 180 ? 2 : 1)));
            
            // Score frequency (higher is better)
            $frequencyScore = $customer->frequency >= 10 ? 5 :
                             ($customer->frequency >= 5 ? 4 :
                             ($customer->frequency >= 3 ? 3 :
                             ($customer->frequency >= 2 ? 2 : 1)));
            
            // Score monetary (higher is better)
            $monetaryScore = $customer->monetary >= 10000 ? 5 :
                            ($customer->monetary >= 5000 ? 4 :
                            ($customer->monetary >= 1000 ? 3 :
                            ($customer->monetary >= 500 ? 2 : 1)));
            
            $rfmScore = $recencyScore + $frequencyScore + $monetaryScore;
            $segment = $this->getRFMSegment($recencyScore, $frequencyScore, $monetaryScore);

            return [
                'customer_id' => $customer->id,
                'customer_name' => $customer->name,
                'customer_email' => $customer->email,
                'recency_days' => $recency,
                'frequency' => (int) $customer->frequency,
                'monetary' => round($customer->monetary, 2),
                'recency_score' => $recencyScore,
                'frequency_score' => $frequencyScore,
                'monetary_score' => $monetaryScore,
                'rfm_score' => $rfmScore,
                'segment' => $segment,
            ];
        })->sortByDesc('rfm_score')->values()->toArray();

        // Segment summary
        $segments = collect($data)->groupBy('segment')->map(function($group, $segment) {
            return [
                'segment' => $segment,
                'customer_count' => $group->count(),
                'total_revenue' => round($group->sum('monetary'), 2),
                'average_revenue' => round($group->avg('monetary'), 2),
            ];
        })->values()->toArray();

        return [
            'title' => 'RFM Segmentation',
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'data' => $data,
            'segments' => $segments,
            'summary' => [
                'total_customers' => count($data),
                'average_rfm_score' => count($data) > 0 ? round(collect($data)->avg('rfm_score'), 2) : 0,
            ],
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Get RFM segment name
     */
    protected function getRFMSegment(int $r, int $f, int $m): string
    {
        // Champions: High R, F, M
        if ($r >= 4 && $f >= 4 && $m >= 4) {
            return 'Champions';
        }
        
        // Loyal Customers: High F, M
        if ($f >= 4 && $m >= 4) {
            return 'Loyal Customers';
        }
        
        // Potential Loyalists: High R, low F
        if ($r >= 4 && $f <= 2) {
            return 'Potential Loyalists';
        }
        
        // At Risk: Low R, high F, M
        if ($r <= 2 && $f >= 3 && $m >= 3) {
            return 'At Risk';
        }
        
        // Can't Lose Them: Very low R, high F, M
        if ($r == 1 && $f >= 4 && $m >= 4) {
            return "Can't Lose Them";
        }
        
        // Hibernating: Low R, F, M
        if ($r <= 2 && $f <= 2 && $m <= 2) {
            return 'Hibernating';
        }
        
        // New Customers: High R, low F, M
        if ($r >= 4 && $f == 1) {
            return 'New Customers';
        }
        
        return 'Promising';
    }

    /**
     * Get day name from day of week number
     */
    protected function getDayName(int $dow): string
    {
        return match($dow) {
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            default => 'Unknown',
        };
    }

    /**
     * Check if table exists
     */
    protected function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Exception $e) {
            return false;
        }
    }
}

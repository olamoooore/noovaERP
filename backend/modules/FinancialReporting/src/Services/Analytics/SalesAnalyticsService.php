<?php

namespace Modules\FinancialReporting\Services\Analytics;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\FinancialReporting\DTOs\ReportFilter;

class SalesAnalyticsService
{
    /**
     * Get sales by product
     */
    public function getSalesByProduct(ReportFilter $filter): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        try {
            $sales = DB::table('pos_order_items as poi')
                ->join('pos_orders as po', 'po.id', '=', 'poi.pos_order_id')
                ->join('products as p', 'p.id', '=', 'poi.product_id')
                ->whereBetween('po.created_at', [$startDate, $endDate->endOfDay()])
                ->where('po.status', 'paid')
                ->where('po.is_return', false)
                ->select([
                    'p.id',
                    'p.name',
                    'p.sku',
                    'p.cost',
                    'p.price',
                    DB::raw('SUM(poi.quantity) as total_quantity'),
                    DB::raw('SUM(poi.line_total) as total_revenue'),
                    DB::raw('SUM(poi.quantity * COALESCE(p.cost, 0)) as total_cost'),
                    DB::raw('SUM(poi.line_total - (poi.quantity * COALESCE(p.cost, 0))) as total_profit'),
                    DB::raw('CASE WHEN SUM(poi.line_total) > 0 THEN (SUM(poi.line_total - (poi.quantity * COALESCE(p.cost, 0))) / SUM(poi.line_total) * 100) ELSE 0 END as profit_margin'),
                    DB::raw('COUNT(DISTINCT po.id) as order_count'),
                ])
                ->groupBy('p.id', 'p.name', 'p.sku', 'p.cost', 'p.price')
                ->orderByDesc('total_revenue')
                ->get();
        } catch (\Exception $e) {
            \Log::error('SalesAnalyticsService getSalesByProduct error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [
                'title' => 'Sales by Product',
                'data' => [],
                'message' => 'Error retrieving sales data: ' . $e->getMessage(),
            ];
        }

        $summary = [
            'total_products' => $sales->count(),
            'total_revenue' => round($sales->sum('total_revenue'), 2),
            'total_cost' => round($sales->sum('total_cost'), 2),
            'total_profit' => round($sales->sum('total_profit'), 2),
            'average_profit_margin' => $sales->count() > 0 ? round($sales->avg('profit_margin'), 2) : 0,
            'total_quantity_sold' => (int) $sales->sum('total_quantity'),
        ];

        return [
            'title' => 'Sales by Product',
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'data' => $sales->map(fn($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'product_name' => $item->name,
                'sku' => $item->sku,
                'total_quantity' => (int) $item->total_quantity,
                'quantity_sold' => (int) $item->total_quantity,
                'total_revenue' => round($item->total_revenue, 2),
                'total_sales' => round($item->total_revenue, 2),
                'total_cost' => round($item->total_cost, 2),
                'total_profit' => round($item->total_profit, 2),
                'profit_margin' => round($item->profit_margin, 2),
            ])->toArray(),
            'summary' => $summary,
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Get sales by customer
     */
    public function getSalesByCustomer(ReportFilter $filter): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        try {
            $sales = DB::table('pos_orders as po')
                ->leftJoin('customers as c', 'c.id', '=', 'po.customer_id')
                ->whereBetween('po.created_at', [$startDate, $endDate->endOfDay()])
                ->where('po.status', 'paid')
                ->where('po.is_return', false)
                ->select([
                    'po.customer_id',
                    'c.name as customer_name',
                    'c.email as customer_email',
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('SUM(po.total) as total_revenue'),
                    DB::raw('AVG(po.total) as average_order_value'),
                    DB::raw('MAX(po.paid_at) as last_purchase_date'),
                ])
                ->groupBy('po.customer_id', 'c.name', 'c.email')
                ->orderByDesc('total_revenue')
                ->get();
        } catch (\Exception $e) {
            \Log::error('SalesAnalyticsService getSalesByCustomer error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [
                'title' => 'Sales by Customer',
                'data' => [],
                'message' => 'Error retrieving sales data: ' . $e->getMessage(),
            ];
        }

        $summary = [
            'total_customers' => $sales->where('customer_id', '!=', null)->count(),
            'walk_in_sales' => round($sales->where('customer_id', null)->sum('total_revenue'), 2),
            'total_revenue' => round($sales->sum('total_revenue'), 2),
            'average_revenue_per_customer' => $sales->count() > 0 ? round($sales->avg('total_revenue'), 2) : 0,
            'average_order_value' => $sales->count() > 0 ? round($sales->avg('average_order_value'), 2) : 0,
            'total_orders' => (int) $sales->sum('order_count'),
        ];

        return [
            'title' => 'Sales by Customer',
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'data' => $sales->map(fn($item) => [
                'id' => $item->customer_id,
                'name' => $item->customer_name ?? 'Walk-in Customer',
                'customer_name' => $item->customer_name ?? 'Walk-in Customer',
                'total_orders' => (int) $item->order_count,
                'total_revenue' => round($item->total_revenue, 2),
                'total_sales' => round($item->total_revenue, 2),
                'average_order_value' => round($item->average_order_value, 2),
                'last_purchase_date' => $item->last_purchase_date,
            ])->toArray(),
            'summary' => $summary,
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Get sales trends
     */
    public function getSalesTrends(ReportFilter $filter, string $interval = 'day'): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        // Determine date format based on interval
        $dateFormat = match($interval) {
            'day' => 'YYYY-MM-DD',
            'week' => 'IYYY-IW',
            'month' => 'YYYY-MM',
            'year' => 'YYYY',
            default => 'YYYY-MM-DD',
        };

        try {
            $trends = DB::table('pos_orders')
                ->whereBetween('created_at', [$startDate, $endDate->endOfDay()])
                ->where('status', 'paid')
                ->where('is_return', false)
                ->select([
                    DB::raw("TO_CHAR(created_at, '{$dateFormat}') as period"),
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('SUM(total) as total_revenue'),
                    DB::raw('AVG(total) as average_order_value'),
                ])
                ->groupBy('period')
                ->orderBy('period')
                ->get();
        } catch (\Exception $e) {
            \Log::error('SalesAnalyticsService getSalesTrends error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [
                'title' => 'Sales Trends',
                'data' => [],
                'message' => 'Error retrieving sales data: ' . $e->getMessage(),
            ];
        }

        // Calculate growth rates
        $data = [];
        $previousRevenue = null;

        foreach ($trends as $index => $trend) {
            $growthRate = null;
            if ($previousRevenue !== null && $previousRevenue > 0) {
                $growthRate = (($trend->total_revenue - $previousRevenue) / $previousRevenue) * 100;
            }

            $data[] = [
                'period' => $trend->period,
                'order_count' => (int) $trend->order_count,
                'total_sales' => round($trend->total_revenue, 2),
                'revenue' => round($trend->total_revenue, 2),
                'average_order_value' => round($trend->average_order_value, 2),
                'growth_rate' => $growthRate !== null ? round($growthRate, 2) : null,
            ];

            $previousRevenue = $trend->total_revenue;
        }

        $summary = [
            'total_periods' => count($data),
            'total_revenue' => round($trends->sum('total_revenue'), 2),
            'average_revenue_per_period' => count($data) > 0 ? round($trends->avg('total_revenue'), 2) : 0,
            'highest_revenue_period' => $trends->sortByDesc('total_revenue')->first()?->period,
            'lowest_revenue_period' => $trends->sortBy('total_revenue')->first()?->period,
            'total_orders' => (int) $trends->sum('order_count'),
        ];

        return [
            'title' => 'Sales Trends',
            'interval' => $interval,
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
     * Get sales by category
     */
    public function getSalesByCategory(ReportFilter $filter): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        try {
            $sales = DB::table('pos_order_items as poi')
                ->join('pos_orders as po', 'po.id', '=', 'poi.pos_order_id')
                ->join('products as p', 'p.id', '=', 'poi.product_id')
                ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->whereBetween('po.created_at', [$startDate, $endDate->endOfDay()])
                ->where('po.status', 'paid')
                ->where('po.is_return', false)
                ->select([
                    'c.id as category_id',
                    'c.name as category_name',
                    DB::raw('COUNT(DISTINCT p.id) as product_count'),
                    DB::raw('SUM(poi.quantity) as total_quantity'),
                    DB::raw('SUM(poi.line_total) as total_revenue'),
                    DB::raw('SUM(poi.quantity * COALESCE(p.cost, 0)) as total_cost'),
                    DB::raw('SUM(poi.line_total - (poi.quantity * COALESCE(p.cost, 0))) as total_profit'),
                    DB::raw('CASE WHEN SUM(poi.line_total) > 0 THEN (SUM(poi.line_total - (poi.quantity * COALESCE(p.cost, 0))) / SUM(poi.line_total) * 100) ELSE 0 END as profit_margin'),
                ])
                ->groupBy('c.id', 'c.name')
                ->orderByDesc('total_revenue')
                ->get();
        } catch (\Exception $e) {
            \Log::error('SalesAnalyticsService getSalesByCategory error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return [
                'title' => 'Sales by Category',
                'data' => [],
                'message' => 'Error retrieving category data: ' . $e->getMessage(),
            ];
        }

        $totalRevenue = $sales->sum('total_revenue');

        $summary = [
            'total_categories' => $sales->count(),
            'total_revenue' => round($totalRevenue, 2),
            'total_profit' => round($sales->sum('total_profit'), 2),
            'average_profit_margin' => $sales->count() > 0 ? round($sales->avg('profit_margin'), 2) : 0,
        ];

        return [
            'title' => 'Sales by Category',
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'data' => $sales->map(fn($item) => [
                'category_id' => $item->category_id,
                'category_name' => $item->category_name ?? 'Uncategorized',
                'product_count' => (int) $item->product_count,
                'quantity_sold' => (int) $item->total_quantity,
                'revenue' => round($item->total_revenue, 2),
                'total_sales' => round($item->total_revenue, 2),
                'cost' => round($item->total_cost, 2),
                'profit' => round($item->total_profit, 2),
                'profit_margin' => round($item->profit_margin, 2),
                'revenue_percentage' => $totalRevenue > 0 ? round(($item->total_revenue / $totalRevenue) * 100, 2) : 0,
            ])->toArray(),
            'summary' => $summary,
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Get top selling products
     */
    public function getTopSellingProducts(ReportFilter $filter, int $limit = 10): array
    {
        $report = $this->getSalesByProduct($filter);
        
        if (isset($report['data'])) {
            $report['data'] = array_slice($report['data'], 0, $limit);
            $report['title'] = "Top {$limit} Selling Products";
        }
        
        return $report;
    }

    /**
     * Get profit margins analysis
     */
    public function getProfitMargins(ReportFilter $filter): array
    {
        $report = $this->getSalesByProduct($filter);
        
        if (isset($report['data'])) {
            // Sort by profit margin
            usort($report['data'], fn($a, $b) => $b['profit_margin'] <=> $a['profit_margin']);
            
            $report['title'] = 'Profit Margin Analysis';
            
            // Add margin categories
            $highMargin = array_filter($report['data'], fn($item) => $item['profit_margin'] >= 30);
            $mediumMargin = array_filter($report['data'], fn($item) => $item['profit_margin'] >= 15 && $item['profit_margin'] < 30);
            $lowMargin = array_filter($report['data'], fn($item) => $item['profit_margin'] < 15);
            
            $report['summary']['high_margin_products'] = count($highMargin);
            $report['summary']['medium_margin_products'] = count($mediumMargin);
            $report['summary']['low_margin_products'] = count($lowMargin);
        }
        
        return $report;
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

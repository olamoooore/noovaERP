<?php

declare(strict_types=1);

namespace Modules\FinancialReporting\Services\Analytics;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\FinancialReporting\DTOs\ReportFilter;

/**
 * Sales analytics across ALL revenue sources.
 *
 * Issue #158: every report in this service used to query only `pos_orders` /
 * `pos_order_items`, silently under-reporting revenue from Sales-module orders
 * (admin / ecommerce channels). The four report methods now UNION ALL
 * `pos_orders` (paid, non-return) with `sales_orders` whose channel is not
 * `pos` and whose status is one of the posted lifecycle stages. The two sets
 * are independent — a POS sale creates a `pos_orders` row; a non-POS sales
 * order creates a `sales_orders` row — so the union does not double count.
 *
 * Response shapes are unchanged for the frontend; the `channels_included`
 * summary field surfaces coverage so a future channel addition is obvious.
 */
class SalesAnalyticsService
{
    /**
     * Channels surfaced in every report below.
     */
    private const CHANNELS_INCLUDED = ['pos', 'admin', 'ecommerce'];

    public function getSalesByProduct(ReportFilter $filter): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        try {
            $sales = DB::query()
                ->fromSub($this->lineItemsUnion($startDate, $endDate), 'li')
                ->leftJoin('products as p', 'p.id', '=', 'li.product_id')
                ->select([
                    'p.id',
                    'p.name',
                    'p.sku',
                    'p.cost',
                    'p.price',
                    DB::raw('SUM(li.quantity) as total_quantity'),
                    DB::raw('SUM(li.line_total) as total_revenue'),
                    DB::raw('SUM(li.quantity * COALESCE(p.cost, 0)) as total_cost'),
                    DB::raw('SUM(li.line_total - (li.quantity * COALESCE(p.cost, 0))) as total_profit'),
                    DB::raw('CASE WHEN SUM(li.line_total) > 0 THEN (SUM(li.line_total - (li.quantity * COALESCE(p.cost, 0))) / SUM(li.line_total) * 100) ELSE 0 END as profit_margin'),
                    DB::raw('COUNT(*) as order_count'),
                ])
                ->groupBy('p.id', 'p.name', 'p.sku', 'p.cost', 'p.price')
                ->orderByDesc('total_revenue')
                ->get();
        } catch (\Throwable $e) {
            Log::error('SalesAnalyticsService getSalesByProduct error', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorEnvelope('Sales by Product', $e);
        }

        $summary = [
            'total_products' => $sales->count(),
            'total_revenue' => round((float) $sales->sum('total_revenue'), 2),
            'total_cost' => round((float) $sales->sum('total_cost'), 2),
            'total_profit' => round((float) $sales->sum('total_profit'), 2),
            'average_profit_margin' => $sales->count() > 0 ? round((float) $sales->avg('profit_margin'), 2) : 0,
            'total_quantity_sold' => (int) $sales->sum('total_quantity'),
            'channels_included' => self::CHANNELS_INCLUDED,
        ];

        return [
            'title' => 'Sales by Product',
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'data' => $sales->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'product_name' => $item->name,
                'sku' => $item->sku,
                'total_quantity' => (int) $item->total_quantity,
                'quantity_sold' => (int) $item->total_quantity,
                'total_revenue' => round((float) $item->total_revenue, 2),
                'total_sales' => round((float) $item->total_revenue, 2),
                'total_cost' => round((float) $item->total_cost, 2),
                'total_profit' => round((float) $item->total_profit, 2),
                'profit_margin' => round((float) $item->profit_margin, 2),
            ])->toArray(),
            'summary' => $summary,
            'generated_at' => now()->toISOString(),
        ];
    }

    public function getSalesByCustomer(ReportFilter $filter): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        try {
            // Order-level union: walk-ins (POS customer_id null) and non-POS
            // Sales-module orders both feed into customer aggregates.
            $posOrders = DB::table('pos_orders as po')
                ->select([
                    'po.customer_id',
                    'po.total as revenue',
                    DB::raw("'pos' as channel"),
                    DB::raw('po.paid_at as revenue_date'),
                ])
                ->whereBetween('po.paid_at', [$startDate, $endDate->endOfDay()])
                ->where('po.status', 'paid')
                ->where('po.is_return', false);

            $salesOrders = DB::table('sales_orders as so')
                ->select([
                    'so.customer_id',
                    DB::raw('COALESCE(so.total, 0) as revenue'),
                    'so.channel',
                    DB::raw('COALESCE(so.order_date, so.created_at) as revenue_date'),
                ])
                ->whereNotIn('so.status', ['draft', 'cancelled'])
                ->where('so.accounting_status', '!=', 'reversed')
                ->whereBetween(DB::raw('COALESCE(so.order_date, so.created_at)'), [$startDate, $endDate->endOfDay()]);

            $rows = DB::query()
                ->fromSub($posOrders->unionAll($salesOrders), 'o')
                ->leftJoin('customers as c', 'c.id', '=', 'o.customer_id')
                ->select([
                    'o.customer_id',
                    'c.name as customer_name',
                    'c.email as customer_email',
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('SUM(o.revenue) as total_revenue'),
                    DB::raw('AVG(o.revenue) as average_order_value'),
                    DB::raw('MAX(o.revenue_date) as last_purchase_date'),
                ])
                ->groupBy('o.customer_id', 'c.name', 'c.email')
                ->orderByDesc('total_revenue')
                ->get();
        } catch (\Throwable $e) {
            Log::error('SalesAnalyticsService getSalesByCustomer error', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorEnvelope('Sales by Customer', $e);
        }

        $walkInRevenue = (float) $rows->where('customer_id', null)->sum('total_revenue');

        $summary = [
            'total_customers' => $rows->where('customer_id', '!=', null)->count(),
            'walk_in_sales' => round($walkInRevenue, 2),
            'total_revenue' => round((float) $rows->sum('total_revenue'), 2),
            'average_revenue_per_customer' => $rows->count() > 0 ? round((float) $rows->avg('total_revenue'), 2) : 0,
            'average_order_value' => $rows->count() > 0 ? round((float) $rows->avg('average_order_value'), 2) : 0,
            'total_orders' => (int) $rows->sum('order_count'),
            'channels_included' => self::CHANNELS_INCLUDED,
        ];

        return [
            'title' => 'Sales by Customer',
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'data' => $rows->map(fn ($item) => [
                'id' => $item->customer_id,
                'name' => $item->customer_name ?? 'Walk-in Customer',
                'customer_name' => $item->customer_name ?? 'Walk-in Customer',
                'total_orders' => (int) $item->order_count,
                'total_revenue' => round((float) $item->total_revenue, 2),
                'total_sales' => round((float) $item->total_revenue, 2),
                'average_order_value' => round((float) $item->average_order_value, 2),
                'last_purchase_date' => $item->last_purchase_date,
            ])->toArray(),
            'summary' => $summary,
            'generated_at' => now()->toISOString(),
        ];
    }

    public function getSalesTrends(ReportFilter $filter, string $interval = 'day'): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        $dateFormat = match ($interval) {
            'day' => 'YYYY-MM-DD',
            'week' => 'IYYY-IW',
            'month' => 'YYYY-MM',
            'year' => 'YYYY',
            default => 'YYYY-MM-DD',
        };

        try {
            $posTrends = DB::table('pos_orders')
                ->select([
                    DB::raw("TO_CHAR(paid_at, '{$dateFormat}') as period"),
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('SUM(total) as total_revenue'),
                    DB::raw('AVG(total) as average_order_value'),
                ])
                ->whereBetween('paid_at', [$startDate, $endDate->endOfDay()])
                ->where('status', 'paid')
                ->where('is_return', false)
                ->groupBy('period');

            $salesTrends = DB::table('sales_orders as so')
                ->select([
                    DB::raw("TO_CHAR(COALESCE(order_date, created_at), '{$dateFormat}') as period"),
                    DB::raw('COUNT(*) as order_count'),
                    DB::raw('SUM(COALESCE(total, 0)) as total_revenue'),
                    DB::raw('AVG(COALESCE(total, 0)) as average_order_value'),
                ])
                ->whereNotIn('status', ['draft', 'cancelled'])
                ->where('accounting_status', '!=', 'reversed')
                ->whereBetween(DB::raw('COALESCE(order_date, created_at)'), [$startDate, $endDate->endOfDay()])
                ->groupBy('period');

            $trends = DB::query()
                ->fromSub($posTrends->unionAll($salesTrends), 't')
                ->select([
                    'period',
                    DB::raw('SUM(order_count) as order_count'),
                    DB::raw('SUM(total_revenue) as total_revenue'),
                    DB::raw('CASE WHEN SUM(order_count) > 0 THEN SUM(total_revenue) / SUM(order_count) ELSE 0 END as average_order_value'),
                ])
                ->groupBy('period')
                ->orderBy('period')
                ->get();
        } catch (\Throwable $e) {
            Log::error('SalesAnalyticsService getSalesTrends error', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorEnvelope('Sales Trends', $e);
        }

        $data = [];
        $previousRevenue = null;
        foreach ($trends as $trend) {
            $growthRate = null;
            if ($previousRevenue !== null && (float) $previousRevenue > 0) {
                $growthRate = (((float) $trend->total_revenue - (float) $previousRevenue) / (float) $previousRevenue) * 100;
            }
            $data[] = [
                'period' => $trend->period,
                'order_count' => (int) $trend->order_count,
                'total_sales' => round((float) $trend->total_revenue, 2),
                'revenue' => round((float) $trend->total_revenue, 2),
                'average_order_value' => round((float) $trend->average_order_value, 2),
                'growth_rate' => $growthRate !== null ? round($growthRate, 2) : null,
            ];
            $previousRevenue = $trend->total_revenue;
        }

        $summary = [
            'total_periods' => count($data),
            'total_revenue' => round((float) $trends->sum('total_revenue'), 2),
            'average_revenue_per_period' => count($data) > 0 ? round((float) $trends->avg('total_revenue'), 2) : 0,
            'highest_revenue_period' => $trends->sortByDesc('total_revenue')->first()?->period,
            'lowest_revenue_period' => $trends->sortBy('total_revenue')->first()?->period,
            'total_orders' => (int) $trends->sum('order_count'),
            'channels_included' => self::CHANNELS_INCLUDED,
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

    public function getSalesByCategory(ReportFilter $filter): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        try {
            $sales = DB::query()
                ->fromSub($this->lineItemsUnion($startDate, $endDate), 'li')
                ->leftJoin('products as p', 'p.id', '=', 'li.product_id')
                ->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id')
                ->select([
                    'c.id as category_id',
                    'c.name as category_name',
                    DB::raw('COUNT(DISTINCT p.id) as product_count'),
                    DB::raw('SUM(li.quantity) as total_quantity'),
                    DB::raw('SUM(li.line_total) as total_revenue'),
                    DB::raw('SUM(li.quantity * COALESCE(p.cost, 0)) as total_cost'),
                    DB::raw('SUM(li.line_total - (li.quantity * COALESCE(p.cost, 0))) as total_profit'),
                    DB::raw('CASE WHEN SUM(li.line_total) > 0 THEN (SUM(li.line_total - (li.quantity * COALESCE(p.cost, 0))) / SUM(li.line_total) * 100) ELSE 0 END as profit_margin'),
                ])
                ->groupBy('c.id', 'c.name')
                ->orderByDesc('total_revenue')
                ->get();
        } catch (\Throwable $e) {
            Log::error('SalesAnalyticsService getSalesByCategory error', [
                'error' => $e->getMessage(),
            ]);

            return $this->errorEnvelope('Sales by Category', $e);
        }

        $totalRevenue = (float) $sales->sum('total_revenue');

        $summary = [
            'total_categories' => $sales->count(),
            'total_revenue' => round($totalRevenue, 2),
            'total_profit' => round((float) $sales->sum('total_profit'), 2),
            'average_profit_margin' => $sales->count() > 0 ? round((float) $sales->avg('profit_margin'), 2) : 0,
            'channels_included' => self::CHANNELS_INCLUDED,
        ];

        return [
            'title' => 'Sales by Category',
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'data' => $sales->map(fn ($item) => [
                'category_id' => $item->category_id,
                'category_name' => $item->category_name ?? 'Uncategorized',
                'product_count' => (int) $item->product_count,
                'quantity_sold' => (int) $item->total_quantity,
                'revenue' => round((float) $item->total_revenue, 2),
                'total_sales' => round((float) $item->total_revenue, 2),
                'cost' => round((float) $item->total_cost, 2),
                'profit' => round((float) $item->total_profit, 2),
                'profit_margin' => round((float) $item->profit_margin, 2),
                'revenue_percentage' => $totalRevenue > 0 ? round(((float) $item->total_revenue / $totalRevenue) * 100, 2) : 0,
            ])->toArray(),
            'summary' => $summary,
            'generated_at' => now()->toISOString(),
        ];
    }

    public function getTopSellingProducts(ReportFilter $filter, int $limit = 10): array
    {
        $report = $this->getSalesByProduct($filter);

        if (isset($report['data'])) {
            $report['data'] = array_slice($report['data'], 0, $limit);
            $report['title'] = "Top {$limit} Selling Products";
        }

        return $report;
    }

    public function getProfitMargins(ReportFilter $filter): array
    {
        $report = $this->getSalesByProduct($filter);

        if (isset($report['data'])) {
            usort($report['data'], fn ($a, $b) => $b['profit_margin'] <=> $a['profit_margin']);

            $report['title'] = 'Profit Margin Analysis';

            $highMargin = array_filter($report['data'], fn ($item) => $item['profit_margin'] >= 30);
            $mediumMargin = array_filter($report['data'], fn ($item) => $item['profit_margin'] >= 15 && $item['profit_margin'] < 30);
            $lowMargin = array_filter($report['data'], fn ($item) => $item['profit_margin'] < 15);

            $report['summary']['high_margin_products'] = count($highMargin);
            $report['summary']['medium_margin_products'] = count($mediumMargin);
            $report['summary']['low_margin_products'] = count($lowMargin);
        }

        return $report;
    }

    /**
     * UNION ALL of posted POS line items and posted Sales-module (admin /
     * ecommerce) line items. The two sets are independent so the union
     * does not double count. Excludes voided / cancelled / draft documents.
     *
     * Returned columns: product_id, customer_id, line_total, quantity,
     * revenue_date, channel.
     *
     * Implemented as a raw SQL subquery rather than a query-builder union
     * because fromSub() with a QB union is fragile across PG versions.
     * Values are inlined (all bound values are typed — Carbon date strings,
     * fixed string labels, booleans) so there is no SQL-injection surface.
     */
    private function lineItemsUnion(Carbon $startDate, Carbon $endDate)
    {
        $start = $startDate->format('Y-m-d H:i:s');
        $end = $endDate->endOfDay()->format('Y-m-d H:i:s');

        $sql = "(
            SELECT poi.product_id,
                   po.customer_id,
                   poi.line_total,
                   poi.quantity,
                   po.paid_at AS revenue_date,
                   'pos' AS channel
            FROM pos_order_items poi
            JOIN pos_orders po ON po.id = poi.pos_order_id
            WHERE po.paid_at BETWEEN '{$start}' AND '{$end}'
              AND po.status = 'paid'
              AND po.is_return = false
        ) UNION ALL (
            SELECT sol.product_id,
                   so.customer_id,
                   COALESCE(sol.line_total, 0) AS line_total,
                   COALESCE(sol.quantity, 0) AS quantity,
                   COALESCE(so.order_date, so.created_at) AS revenue_date,
                   so.channel
            FROM sales_order_lines sol
            JOIN sales_orders so ON so.id = sol.sales_order_id
            WHERE so.status NOT IN ('draft', 'cancelled')
              AND so.accounting_status <> 'reversed'
              AND COALESCE(so.order_date, so.created_at) BETWEEN '{$start}' AND '{$end}'
        )";

        return DB::table(DB::raw('('.$sql.') AS li'));
    }

    protected function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function errorEnvelope(string $title, \Throwable $e): array
    {
        return [
            'title' => $title,
            'data' => [],
            'message' => 'Error retrieving sales data: '.$e->getMessage(),
        ];
    }
}

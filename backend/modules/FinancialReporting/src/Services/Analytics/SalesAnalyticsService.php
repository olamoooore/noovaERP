<?php

declare(strict_types=1);

namespace Modules\FinancialReporting\Services\Analytics;

use App\Services\DocumentCurrencyResolver;
use App\Services\Reporting\ComparesReportPeriods;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\FinancialReporting\DTOs\ReportFilter;

/**
 * Sales analytics across ALL revenue sources.
 *
 * Issue #158: every report in this service used to query only `pos_orders` /
 * `pos_order_items`, silently under-reporting revenue from Sales-module orders
 * (admin / ecommerce channels). Issue #590 (RP1, with #355/#395): the union
 * also missed every invoice that has no backing Sales Order (direct /
 * accounting-origin invoices, quote→invoice, DO→invoice, imports).
 *
 * ── THE REVENUE DEFINITION (#355) ────────────────────────────────────────
 * Every report in this class measures exactly one thing, and it is the same
 * thing the ledger measures:
 *
 *   REVENUE (tax-excluded, in the tenant BASE currency, dated by DOCUMENT date)
 *     = posted invoices      (any origin)      − header/document discount
 *     − posted customer credit notes
 *     + POS sales            (returns netted, not excluded)
 *     + un-invoiced bookings (sales orders with no posted invoice)
 *
 *   - TAX EXCLUDED: line revenue is `line_total − tax_amount`. The invoice,
 *     POS and credit-note postings credit `sales_revenue` with the pre-tax
 *     amount and book the output tax on `sales_tax_payable`, so a tax-inclusive
 *     base can never reconcile with the GL.
 *   - DISCOUNTS: the document (header) discount is subtracted pro-rata over the
 *     document's own lines, matching the posting (revenue = subtotal −
 *     discount_amount).
 *   - BASE CURRENCY: every arm carries its document snapshot rate, and all
 *     widgets except the by-currency view sum the BASE amount. Only the
 *     by-currency view reports document-currency amounts, next to the base
 *     equivalent it converts them at.
 *   - POS RETURNS are NETTED. A return order's lines are already written as
 *     negatives (PosOrder::createReturn()), so including returns nets them.
 *   - BOOKINGS are shown separately (`bookings_revenue`) because they are not
 *     yet revenue: an invoiced sales order is dropped from the union so it
 *     counts exactly once — through its invoice (#590).
 *   - POS-origin invoices are excluded: their revenue is already carried by
 *     `pos_orders`, and a pos-channel sales order is skipped for the same
 *     reason (#236).
 *   - Shipping is NOT included: it posts to its own `shipping_revenue` account
 *     and belongs to no product, so it cannot be attributed to any row here.
 *
 * A period's revenue therefore equals the net movement of the mapped
 * `sales_revenue` account for the same documents, apart from bookings (not yet
 * posted) and per-line rounding of a document discount spread over many lines
 * (bounded by 0.005 per line) — both documented in
 * tests/Feature/HR355SalesRevenueBaseTest.php.
 *
 * Response shapes are unchanged for the frontend; `channels_included`,
 * `revenue_basis`, `base_currency`, `bookings_revenue` and
 * `invoiced_revenue` are additive summary fields so a consumer can always see
 * what is being measured.
 */
class SalesAnalyticsService
{
    use ComparesReportPeriods;

    /**
     * Channels surfaced in every report below.
     */
    private const CHANNELS_INCLUDED = ['pos', 'admin', 'ecommerce'];

    /**
     * Issue #595: numeric row fields each report compares when a comparison
     * window is requested. Names are the response's own field names, so the
     * frontend can render `{metric}_delta` / `{metric}_delta_percent`.
     */
    private const PRODUCT_COMPARISON_METRICS = [
        'total_quantity', 'total_revenue', 'total_cost', 'total_profit', 'profit_margin',
    ];

    private const CUSTOMER_COMPARISON_METRICS = [
        'total_orders', 'total_revenue', 'average_order_value',
    ];

    private const CURRENCY_COMPARISON_METRICS = [
        'total_revenue', 'base_revenue', 'total_quantity', 'order_count',
    ];

    private const TRENDS_COMPARISON_METRICS = [
        'order_count', 'total_sales', 'revenue', 'bookings_revenue', 'invoiced_revenue', 'average_order_value',
    ];

    private const CATEGORY_COMPARISON_METRICS = [
        'product_count', 'quantity_sold', 'revenue', 'cost', 'profit', 'profit_margin', 'revenue_percentage',
    ];

    /**
     * Issue #601: the only row/column group-bys the pivot endpoint accepts.
     * Every one maps to a fixed SQL expression in pivotDimensionSql(); raw
     * caller input never reaches the query.
     */
    private const PIVOT_DIMENSIONS = [
        'salesperson', 'team', 'customer', 'product', 'category', 'country', 'month',
    ];

    /**
     * Issue #601: the only measures the pivot endpoint accepts, each mapped to
     * a fixed aggregate over the #355 read model in pivotMeasureSql().
     *
     * Issue #602 (SALES-ODOO) adds the Odoo Sales Analysis lifecycle measures
     * (ordered / delivered / invoiced / to-invoice, quantity and amount), all
     * computed from the same union's new per-line columns — see
     * pivotMeasureSql() and lineItemsUnion().
     */
    private const PIVOT_MEASURES = [
        'paid_qty', 'foc_qty', 'net_revenue', 'cost', 'margin',
        'qty_ordered', 'qty_delivered', 'qty_invoiced', 'qty_to_invoice',
        'amount_ordered', 'amount_delivered', 'amount_invoiced', 'amount_to_invoice',
    ];

    /**
     * Dimensions a pivot request may group by; the controller validates
     * against this list (a class constant cannot be implode()d in a rule).
     *
     * @return list<string>
     */
    public static function pivotDimensions(): array
    {
        return self::PIVOT_DIMENSIONS;
    }

    /**
     * Measures a pivot request may ask for.
     *
     * @return list<string>
     */
    public static function pivotMeasures(): array
    {
        return self::PIVOT_MEASURES;
    }

    /**
     * The human-readable definition of every "revenue" figure in this service.
     * Surfaced in each summary so no caller has to guess (#355).
     */
    /**
     * Issue #360: the by-product row that carries revenue from lines with no
     * product (service / description lines), named explicitly instead of
     * collapsing into an unnamed group the dashboard renders as "Unknown".
     */
    public const UNLINKED_PRODUCT_LABEL = 'Services / unlinked lines';

    /**
     * Issue #532: how the credit-note analysis may group credit notes.
     */
    private const CREDIT_NOTE_GROUPINGS = ['reason', 'product', 'customer', 'salesperson'];

    /**
     * Issue #532: a credit note's reason bucket. Reasons are free text until
     * reason codes land (#523), so surrounding whitespace is ignored and a
     * blank reason is its own bucket. Used as both the row key and the label
     * so a reason row and its drill-down bucket identically.
     */
    private const CREDIT_NOTE_REASON_SQL = "COALESCE(NULLIF(TRIM(cnr.reason), ''), 'No reason')";

    /**
     * Issue #360: layout-only line types (no quantity, no amount) that are not
     * sales and must not count towards a product's orders.
     */
    private const NON_SALE_LINE_TYPES = ['note', 'section'];

    /**
     * Issue #596: the trend period key per interval — the same TO_CHAR formats
     * buildSalesTrends() buckets by, so a period row drills to its own lines.
     */
    private const DRILLDOWN_PERIOD_FORMATS = [
        'day' => 'YYYY-MM-DD',
        'week' => 'IYYY-IW',
        'month' => 'YYYY-MM',
        'year' => 'YYYY',
    ];

    private const REVENUE_BASIS = 'Net revenue, tax excluded, document discounts subtracted, '
        .'reported in the tenant base currency: posted invoices less posted customer '
        .'credit notes, plus POS sales net of returns, plus un-invoiced bookings. '
        .'Dated by document date; excludes shipping (posted to its own revenue account).';

    public function getSalesByProduct(ReportFilter $filter): array
    {
        return $this->withPeriodComparison(
            $filter,
            $this->buildSalesByProduct($filter),
            fn (ReportFilter $rangeFilter): array => $this->buildSalesByProduct($rangeFilter),
            self::PRODUCT_COMPARISON_METRICS,
            'id',
        );
    }

    private function buildSalesByProduct(ReportFilter $filter): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        [$salesOrderIds, $invoiceIds] = $this->documentIdsForTag($filter->tag ?? null);

        try {
            $sales = DB::query()
                ->fromSub($this->lineItemsUnion($filter, $salesOrderIds, $invoiceIds), 'li')
                ->leftJoin('products as p', 'p.id', '=', 'li.product_id')
                ->select([
                    'p.id',
                    'p.name',
                    'p.short_description',
                    'p.sku',
                    'p.cost',
                    'p.price',
                    // Issue #600: quantity sold, cost, profit and margin are
                    // on PAID units; free (FOC) units are reported apart.
                    DB::raw('SUM(li.quantity - li.foc_quantity) as total_quantity'),
                    DB::raw('SUM(li.foc_quantity) as foc_quantity'),
                    DB::raw('SUM(li.base_foc_list_value) as foc_list_value'),
                    DB::raw('SUM(li.base_revenue) as total_revenue'),
                    DB::raw('SUM(CASE WHEN li.channel = \'booking\' THEN li.base_revenue ELSE 0 END) as bookings_revenue'),
                    DB::raw('SUM(CASE WHEN li.channel <> \'booking\' THEN li.base_revenue ELSE 0 END) as invoiced_revenue'),
                    // Issue #359: cost is the COGS posted at the time of sale
                    // (li.base_cost), never today's products.cost; the #600
                    // paid-unit share of it is what the margin is measured on.
                    DB::raw('SUM(CASE WHEN li.quantity <> 0 THEN li.base_cost * (li.quantity - li.foc_quantity) / li.quantity ELSE 0 END) as total_cost'),
                    DB::raw('SUM(li.base_revenue - (CASE WHEN li.quantity <> 0 THEN li.base_cost * (li.quantity - li.foc_quantity) / li.quantity ELSE 0 END)) as total_profit'),
                    DB::raw('CASE WHEN SUM(li.base_revenue) > 0 THEN (SUM(li.base_revenue - (CASE WHEN li.quantity <> 0 THEN li.base_cost * (li.quantity - li.foc_quantity) / li.quantity ELSE 0 END)) / SUM(li.base_revenue) * 100) ELSE 0 END as profit_margin'),
                    // Issue #360: orders, not line rows — a product on two
                    // lines of one order is one order.
                    DB::raw('COUNT(DISTINCT li.order_ref) as order_count'),
                ])
                // Issue #360: note / section lines are layout, not sales.
                ->where(function ($query) {
                    $query->whereNull('li.line_type')
                        ->orWhereNotIn('li.line_type', self::NON_SALE_LINE_TYPES);
                })
                ->groupBy('p.id', 'p.name', 'p.short_description', 'p.sku', 'p.cost', 'p.price')
                ->orderByDesc('total_revenue')
                ->get();
        } catch (\Throwable $e) {
            Log::error('SalesAnalyticsService getSalesByProduct error', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $summary = $this->baseSummary([
            'total_products' => $sales->count(),
            'total_revenue' => round((float) $sales->sum('total_revenue'), 2),
            'bookings_revenue' => round((float) $sales->sum('bookings_revenue'), 2),
            'invoiced_revenue' => round((float) $sales->sum('invoiced_revenue'), 2),
            'total_cost' => round((float) $sales->sum('total_cost'), 2),
            'total_profit' => round((float) $sales->sum('total_profit'), 2),
            // Issue #359: weighted by revenue, not a simple average of rows.
            'average_profit_margin' => $this->revenueWeightedMargin($sales->sum('total_profit'), $sales->sum('total_revenue')),
            'total_quantity_sold' => $this->roundQuantity($sales->sum('total_quantity')),
            'total_foc_quantity' => $this->roundQuantity($sales->sum('foc_quantity')),
            'total_foc_list_value' => round((float) $sales->sum('foc_list_value'), 2),
            'channels_included' => self::CHANNELS_INCLUDED,
        ]);

        return [
            'title' => 'Sales by Product',
            'pos_excluded_by_tag' => $filter->tag !== null,
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'data' => $sales->map(fn ($item) => [
                'id' => $item->id,
                // Issue #360: product-less revenue is labelled, never "Unknown".
                'name' => $item->id === null ? self::UNLINKED_PRODUCT_LABEL : $item->name,
                'product_name' => $item->id === null ? self::UNLINKED_PRODUCT_LABEL : $item->name,
                'short_description' => $item->id === null ? '' : ($item->short_description ?? ''),
                'sku' => $item->sku,
                'order_count' => (int) $item->order_count,
                'total_quantity' => $this->roundQuantity($item->total_quantity),
                'quantity_sold' => $this->roundQuantity($item->total_quantity),
                'paid_quantity' => $this->roundQuantity($item->total_quantity),
                'foc_quantity' => $this->roundQuantity($item->foc_quantity),
                'foc_list_value' => round((float) $item->foc_list_value, 2),
                'average_price' => (float) $item->total_quantity != 0.0
                    ? round((float) $item->total_revenue / (float) $item->total_quantity, 2)
                    : 0.0,
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
        return $this->withPeriodComparison(
            $filter,
            $this->buildSalesByCustomer($filter),
            fn (ReportFilter $rangeFilter): array => $this->buildSalesByCustomer($rangeFilter),
            self::CUSTOMER_COMPARISON_METRICS,
            'id',
        );
    }

    private function buildSalesByCustomer(ReportFilter $filter): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        [$salesOrderIds, $invoiceIds] = $this->documentIdsForTag($filter->tag ?? null);

        try {
            // Identical line-item revenue base as every other widget (#355/#590):
            // posted invoices, posted credit notes, POS sales net of returns and
            // un-invoiced bookings, all tax-excluded, discount-net and in the base
            // currency, with walk-ins (POS customer_id null) in the same aggregate.
            $rows = DB::query()
                ->fromSub($this->lineItemsUnion($filter, $salesOrderIds, $invoiceIds), 'li')
                ->leftJoin('customers as c', 'c.id', '=', 'li.customer_id')
                ->select([
                    'li.customer_id',
                    'c.name as customer_name',
                    'c.email as customer_email',
                    DB::raw('COUNT(DISTINCT li.order_ref) as order_count'),
                    // Issue #600: paid vs free (FOC) units, and the list value
                    // of the free goods given to the customer.
                    DB::raw('SUM(li.quantity - li.foc_quantity) as paid_quantity'),
                    DB::raw('SUM(li.foc_quantity) as foc_quantity'),
                    DB::raw('SUM(li.base_foc_list_value) as foc_list_value'),
                    DB::raw('SUM(li.base_revenue) as total_revenue'),
                    DB::raw('SUM(CASE WHEN li.channel = \'booking\' THEN li.base_revenue ELSE 0 END) as bookings_revenue'),
                    DB::raw('SUM(CASE WHEN li.channel <> \'booking\' THEN li.base_revenue ELSE 0 END) as invoiced_revenue'),
                    DB::raw('CASE WHEN COUNT(DISTINCT li.order_ref) > 0 THEN SUM(li.base_revenue) / COUNT(DISTINCT li.order_ref) ELSE 0 END as average_order_value'),
                    DB::raw('MAX(li.revenue_date) as last_purchase_date'),
                ])
                ->groupBy('li.customer_id', 'c.name', 'c.email')
                ->orderByDesc('total_revenue')
                ->get();
        } catch (\Throwable $e) {
            Log::error('SalesAnalyticsService getSalesByCustomer error', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $walkInRevenue = (float) $rows->where('customer_id', null)->sum('total_revenue');

        $summary = $this->baseSummary([
            'total_customers' => $rows->where('customer_id', '!=', null)->count(),
            'walk_in_sales' => round($walkInRevenue, 2),
            'total_revenue' => round((float) $rows->sum('total_revenue'), 2),
            'bookings_revenue' => round((float) $rows->sum('bookings_revenue'), 2),
            'invoiced_revenue' => round((float) $rows->sum('invoiced_revenue'), 2),
            'average_revenue_per_customer' => $rows->count() > 0 ? round((float) $rows->avg('total_revenue'), 2) : 0,
            'average_order_value' => $rows->count() > 0 ? round((float) $rows->avg('average_order_value'), 2) : 0,
            'total_orders' => (int) $rows->sum('order_count'),
            'channels_included' => self::CHANNELS_INCLUDED,
        ]);

        return [
            'title' => 'Sales by Customer',
            'pos_excluded_by_tag' => $filter->tag !== null,
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
                'paid_quantity' => $this->roundQuantity($item->paid_quantity),
                'foc_quantity' => $this->roundQuantity($item->foc_quantity),
                'foc_list_value' => round((float) $item->foc_list_value, 2),
                'last_purchase_date' => $item->last_purchase_date,
            ])->toArray(),
            'summary' => $summary,
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * MRD pricelist-multicurrency FR-8.1 — revenue split by document currency,
     * with the snapshot-rate base equivalent per bucket. This is the ONLY view
     * that reports document-currency amounts; `total_revenue` here is
     * tax-excluded and discount-net like every other view (#355), and
     * `base_revenue` is the figure the other widgets total.
     */
    public function getSalesByCurrency(ReportFilter $filter): array
    {
        return $this->withPeriodComparison(
            $filter,
            $this->buildSalesByCurrency($filter),
            fn (ReportFilter $rangeFilter): array => $this->buildSalesByCurrency($rangeFilter),
            self::CURRENCY_COMPARISON_METRICS,
            'currency_code',
        );
    }

    private function buildSalesByCurrency(ReportFilter $filter): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        [$salesOrderIds, $invoiceIds] = $this->documentIdsForTag($filter->tag ?? null);

        try {
            $rows = DB::query()
                ->fromSub($this->lineItemsUnion($filter, $salesOrderIds, $invoiceIds), 'li')
                ->select([
                    'li.currency_code',
                    DB::raw('SUM(li.document_revenue) as total_revenue'),
                    DB::raw('SUM(li.base_revenue) as base_revenue'),
                    DB::raw('SUM(li.quantity) as total_quantity'),
                    DB::raw('COUNT(DISTINCT li.order_ref) as order_count'),
                ])
                ->groupBy('li.currency_code')
                ->orderByDesc('base_revenue')
                ->get();
        } catch (\Throwable $e) {
            Log::error('SalesAnalyticsService getSalesByCurrency error', ['error' => $e->getMessage()]);

            throw $e;
        }

        return [
            'title' => 'Sales by Currency',
            'pos_excluded_by_tag' => $filter->tag !== null,
            'data' => $rows->map(fn ($row) => [
                'currency_code' => $row->currency_code,
                'total_revenue' => round((float) $row->total_revenue, 2),
                'base_revenue' => round((float) $row->base_revenue, 2),
                'total_quantity' => (float) $row->total_quantity,
                'order_count' => (int) $row->order_count,
            ])->all(),
            'summary' => $this->baseSummary([
                'total_document_revenue' => round((float) $rows->sum('total_revenue'), 2),
                'total_base_revenue' => round((float) $rows->sum('base_revenue'), 2),
            ]),
        ];
    }

    public function getSalesTrends(ReportFilter $filter, string $interval = 'day'): array
    {
        // Issue #595: a timeline compares positionally — primary day 1 against
        // comparison day 1 — because the period labels themselves differ by
        // construction; both series are zero-filled by buildSalesTrends (#594)
        // so the positions always exist.
        return $this->withPeriodComparison(
            $filter,
            $this->buildSalesTrends($filter, $interval),
            fn (ReportFilter $rangeFilter): array => $this->buildSalesTrends($rangeFilter, $interval),
            self::TRENDS_COMPARISON_METRICS,
        );
    }

    private function buildSalesTrends(ReportFilter $filter, string $interval = 'day'): array
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

        [$salesOrderIds, $invoiceIds] = $this->documentIdsForTag($filter->tag ?? null);

        try {
            // Issue #594: generate every period in the range and LEFT JOIN the
            // aggregate onto it, so an empty period is a real zero row. The
            // chart no longer joins non-adjacent days, growth_rate compares
            // adjacent rows only, and the lowest period can be a zero day.
            // The aggregate keeps the #355 line-item base, so the trend series
            // still reconciles with the category and product tables.
            $seriesSql = $this->periodSeriesSql($interval, $startDate, $endDate, $dateFormat);

            $aggregateSql = DB::query()
                ->fromSub($this->lineItemsUnion($filter, $salesOrderIds, $invoiceIds), 'li')
                ->selectRaw("TO_CHAR(li.revenue_date, '{$dateFormat}') as period")
                ->selectRaw('COUNT(DISTINCT li.order_ref) as order_count')
                ->selectRaw('SUM(li.base_revenue) as total_revenue')
                ->selectRaw("SUM(CASE WHEN li.channel = 'booking' THEN li.base_revenue ELSE 0 END) as bookings_revenue")
                ->selectRaw("SUM(CASE WHEN li.channel <> 'booking' THEN li.base_revenue ELSE 0 END) as invoiced_revenue")
                ->groupBy('period')
                ->toSql();

            $trends = collect(DB::select(
                "SELECT p.period,
                        COALESCE(t.order_count, 0) AS order_count,
                        COALESCE(t.total_revenue, 0) AS total_revenue,
                        COALESCE(t.bookings_revenue, 0) AS bookings_revenue,
                        COALESCE(t.invoiced_revenue, 0) AS invoiced_revenue,
                        CASE WHEN COALESCE(t.order_count, 0) > 0
                             THEN t.total_revenue / t.order_count
                             ELSE 0 END AS average_order_value
                 FROM ({$seriesSql}) AS p
                 LEFT JOIN ({$aggregateSql}) AS t ON t.period = p.period
                 ORDER BY p.period"
            ));
        } catch (\Throwable $e) {
            Log::error('SalesAnalyticsService getSalesTrends error', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $data = [];
        $previousRevenue = null;
        foreach ($trends as $trend) {
            $revenue = (float) $trend->total_revenue;
            $growthRate = null;
            // Adjacent rows only. A zero previous period makes the rate
            // undefined (division by zero guarded) instead of silently
            // comparing across the empty period.
            if ($previousRevenue !== null && $previousRevenue > 0) {
                $growthRate = (($revenue - $previousRevenue) / $previousRevenue) * 100;
            }
            $data[] = [
                'period' => $trend->period,
                'order_count' => (int) $trend->order_count,
                'total_sales' => round($revenue, 2),
                'revenue' => round($revenue, 2),
                'bookings_revenue' => round((float) $trend->bookings_revenue, 2),
                'invoiced_revenue' => round((float) $trend->invoiced_revenue, 2),
                'average_order_value' => round((float) $trend->average_order_value, 2),
                'growth_rate' => $growthRate !== null ? round($growthRate, 2) : null,
            ];
            $previousRevenue = $revenue;
        }

        $summary = $this->baseSummary([
            'total_periods' => count($data),
            'total_revenue' => round((float) $trends->sum('total_revenue'), 2),
            'bookings_revenue' => round((float) $trends->sum('bookings_revenue'), 2),
            'invoiced_revenue' => round((float) $trends->sum('invoiced_revenue'), 2),
            'average_revenue_per_period' => count($data) > 0 ? round((float) $trends->avg('total_revenue'), 2) : 0,
            'highest_revenue_period' => $trends->sortByDesc('total_revenue')->first()?->period,
            'lowest_revenue_period' => $trends->sortBy('total_revenue')->first()?->period,
            'total_orders' => (int) $trends->sum('order_count'),
            'channels_included' => self::CHANNELS_INCLUDED,
        ]);

        return [
            'title' => 'Sales Trends',
            'pos_excluded_by_tag' => $filter->tag !== null,
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
     * Issue #594: every period in the requested range as one `generate_series`
     * row, aligned to the tenant's calendar — `day`, ISO `week` (Monday start,
     * matching Postgres' DATE_TRUNC('week') and the `IYYY-IW` key), `month` and
     * `year` — and formatted exactly like the aggregate's period key so the
     * LEFT JOIN always lands.
     */
    private function periodSeriesSql(
        string $interval,
        Carbon $startDate,
        Carbon $endDate,
        string $dateFormat,
    ): string {
        [$trunc, $step, $start, $end] = match ($interval) {
            'week' => ['week', '1 week', $startDate->copy()->startOfWeek(Carbon::MONDAY), $endDate->copy()->startOfWeek(Carbon::MONDAY)],
            'month' => ['month', '1 month', $startDate->copy()->startOfMonth(), $endDate->copy()->startOfMonth()],
            'year' => ['year', '1 year', $startDate->copy()->startOfYear(), $endDate->copy()->startOfYear()],
            default => ['day', '1 day', $startDate->copy()->startOfDay(), $endDate->copy()->startOfDay()],
        };

        $start = $start->format('Y-m-d H:i:s');
        $end = $end->format('Y-m-d H:i:s');

        return "SELECT TO_CHAR(gs, '{$dateFormat}') AS period
                FROM generate_series(
                    DATE_TRUNC('{$trunc}', '{$start}'::timestamp),
                    DATE_TRUNC('{$trunc}', '{$end}'::timestamp),
                    INTERVAL '{$step}'
                ) AS gs";
    }

    public function getSalesByCategory(ReportFilter $filter): array
    {
        return $this->withPeriodComparison(
            $filter,
            $this->buildSalesByCategory($filter),
            fn (ReportFilter $rangeFilter): array => $this->buildSalesByCategory($rangeFilter),
            self::CATEGORY_COMPARISON_METRICS,
            'category_id',
        );
    }

    private function buildSalesByCategory(ReportFilter $filter): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        [$salesOrderIds, $invoiceIds] = $this->documentIdsForTag($filter->tag ?? null);

        try {
            $sales = DB::query()
                ->fromSub($this->lineItemsUnion($filter, $salesOrderIds, $invoiceIds), 'li')
                ->leftJoin('products as p', 'p.id', '=', 'li.product_id')
                ->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id')
                ->select([
                    'c.id as category_id',
                    'c.name as category_name',
                    DB::raw('COUNT(DISTINCT p.id) as product_count'),
                    DB::raw('SUM(li.quantity) as total_quantity'),
                    DB::raw('SUM(li.base_revenue) as total_revenue'),
                    DB::raw('SUM(CASE WHEN li.channel = \'booking\' THEN li.base_revenue ELSE 0 END) as bookings_revenue'),
                    DB::raw('SUM(CASE WHEN li.channel <> \'booking\' THEN li.base_revenue ELSE 0 END) as invoiced_revenue'),
                    // Issue #359: the COGS posted at the time of sale.
                    DB::raw('SUM(li.base_cost) as total_cost'),
                    DB::raw('SUM(li.base_revenue - li.base_cost) as total_profit'),
                    DB::raw('CASE WHEN SUM(li.base_revenue) > 0 THEN (SUM(li.base_revenue - li.base_cost) / SUM(li.base_revenue) * 100) ELSE 0 END as profit_margin'),
                ])
                ->groupBy('c.id', 'c.name')
                ->orderByDesc('total_revenue')
                ->get();
        } catch (\Throwable $e) {
            Log::error('SalesAnalyticsService getSalesByCategory error', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $totalRevenue = (float) $sales->sum('total_revenue');

        $summary = $this->baseSummary([
            'total_categories' => $sales->count(),
            'total_revenue' => round($totalRevenue, 2),
            'bookings_revenue' => round((float) $sales->sum('bookings_revenue'), 2),
            'invoiced_revenue' => round((float) $sales->sum('invoiced_revenue'), 2),
            'total_profit' => round((float) $sales->sum('total_profit'), 2),
            'average_profit_margin' => $this->revenueWeightedMargin($sales->sum('total_profit'), $totalRevenue),
            'channels_included' => self::CHANNELS_INCLUDED,
        ]);

        return [
            'title' => 'Sales by Category',
            'pos_excluded_by_tag' => $filter->tag !== null,
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'data' => $sales->map(fn ($item) => [
                'category_id' => $item->category_id,
                'category_name' => $item->category_name ?? 'Uncategorized',
                'product_count' => (int) $item->product_count,
                'quantity_sold' => $this->roundQuantity($item->total_quantity),
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

    /**
     * Issue #601: pivot-style sales analysis over the SAME #355 read model as
     * every fixed tab, so the pivot's net revenue reconciles with them.
     *
     * The caller supplies whitelisted dimensions and measures; this method
     * maps each to a fixed SQL expression, runs ONE grouped query, then folds
     * the flat result into a row hierarchy (parents aggregate their children)
     * for the frontend's expand/collapse. Measures:
     *
     *   - paid_qty    SUM(quantity) on lines whose document revenue is non-zero
     *   - foc_qty     SUM(quantity) on zero-net lines (free-of-charge lines,
     *                 e.g. #560's list-price/100%-discount lines); FOC cost
     *                 still lands in `cost` so free goods are costed
     *   - net_revenue SUM(base_revenue) — the #355 definition
     *   - cost        SUM(base_cost): the COGS posted at the time of sale
     *                 (#359), the same source the product/category reports use
     *   - margin      net_revenue − cost
     *
     * @param  list<string>  $rowDims  row group-bys, outermost first
     * @param  list<string>  $colDims  column group-bys ([] → one Total column)
     * @param  list<string>  $measures
     * @return array<string, mixed>
     */
    public function getSalesPivot(
        ReportFilter $filter,
        array $rowDims = ['product'],
        array $colDims = [],
        array $measures = [
            'paid_qty', 'foc_qty', 'net_revenue', 'cost', 'margin',
        ],
    ): array {
        $rowDims = $this->validatedPivotDimensions($rowDims, 'row_dims');
        $colDims = $this->validatedPivotDimensions($colDims, 'col_dims');
        $measures = $this->validatedPivotMeasures($measures);

        if ($rowDims === []) {
            $rowDims = ['product'];
        }

        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        [$salesOrderIds, $invoiceIds] = $this->documentIdsForTag($filter->tag ?? null);

        try {
            $select = [];
            foreach ($rowDims as $index => $dimension) {
                $select[] = DB::raw($this->pivotDimensionSql($dimension)." AS row_dim_{$index}");
            }
            foreach ($colDims as $index => $dimension) {
                $select[] = DB::raw($this->pivotDimensionSql($dimension)." AS col_dim_{$index}");
            }
            foreach ($measures as $measure) {
                $select[] = DB::raw($this->pivotMeasureSql($measure)." AS m_{$measure}");
            }

            // GROUP BY position: the selected expressions are fixed whitelist
            // SQL, and grouping by position keeps the query builder from
            // re-quoting a raw expression.
            $dimensionCount = count($rowDims) + count($colDims);
            $groupBy = implode(', ', range(1, $dimensionCount));

            $cells = DB::query()
                ->fromSub($this->lineItemsUnion($filter, $salesOrderIds, $invoiceIds), 'li')
                ->leftJoin('customers as c', 'c.id', '=', 'li.customer_id')
                ->leftJoin('users as sp', 'sp.id', '=', 'c.salesperson_id')
                ->leftJoin('products as p', 'p.id', '=', 'li.product_id')
                ->leftJoin('product_categories as pc', 'pc.id', '=', 'p.category_id')
                ->select($select)
                ->groupByRaw($groupBy)
                ->get();
        } catch (\Throwable $e) {
            Log::error('SalesAnalyticsService getSalesPivot error', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $this->buildSalesPivotPayload(
            $filter,
            $startDate,
            $endDate,
            $rowDims,
            $colDims,
            $measures,
            $cells,
        );
    }

    /**
     * Fold the flat grouped cells into the pivot response: ordered columns,
     * one row hierarchy per row dimension, per-cell measures, row and grand
     * totals.
     *
     * @param  list<string>  $rowDims
     * @param  list<string>  $colDims
     * @param  list<string>  $measures
     * @param  \Illuminate\Support\Collection<int, object>  $cells
     * @return array<string, mixed>
     */
    private function buildSalesPivotPayload(
        ReportFilter $filter,
        Carbon $startDate,
        Carbon $endDate,
        array $rowDims,
        array $colDims,
        array $measures,
        $cells,
    ): array {
        $columns = [];
        $tree = [];
        $grandTotals = array_fill_keys($measures, 0.0);
        $leafCount = 0;

        foreach ($cells as $cell) {
            $rowValues = [];
            foreach (array_keys($rowDims) as $index) {
                $rowValues[] = (string) ($cell->{'row_dim_'.$index} ?? '');
            }

            $colValues = [];
            foreach (array_keys($colDims) as $index) {
                $colValues[] = (string) ($cell->{'col_dim_'.$index} ?? '');
            }

            $columnKey = $this->pivotKey($colValues);
            if (! isset($columns[$columnKey])) {
                $columns[$columnKey] = [
                    'key' => $colValues,
                    'label' => $colValues === []
                        ? 'Total'
                        : implode(' / ', array_map(
                            fn (string $dimension, string $value): string => $this->pivotValueLabel($dimension, $value),
                            $colDims,
                            $colValues,
                        )),
                ];
            }

            $measuresByKey = [];
            foreach ($measures as $measure) {
                $measuresByKey[$measure] = (float) ($cell->{'m_'.$measure} ?? 0);
            }

            $this->insertPivotNode($tree, 0, $rowValues, $columnKey, $measuresByKey, $measures);

            foreach ($measures as $measure) {
                $grandTotals[$measure] += $measuresByKey[$measure];
            }
            $leafCount++;
        }

        // An empty result still renders a single labelled column, so the
        // frontend always has a header to show.
        if ($columns === []) {
            $columns[$this->pivotKey([])] = ['key' => [], 'label' => 'Total'];
        }

        ksort($columns);
        $this->sortPivotNodes($tree, $measures);

        $columnKeys = array_keys($columns);
        $segment = function (array $node) use (&$segment, $columnKeys, $measures): array {
            $values = [];
            foreach ($columnKeys as $columnKey) {
                $values[] = $this->roundPivotMeasures($measures, $node['values'][$columnKey] ?? []);
            }

            return [
                'key' => $node['key'],
                'label' => $node['label'],
                'depth' => $node['depth'],
                'values' => $values,
                'totals' => $this->roundPivotMeasures($measures, $node['totals']),
                'children' => array_map($segment, array_values($node['children'])),
            ];
        };

        return [
            'title' => 'Sales Analysis',
            'row_dims' => $rowDims,
            'col_dims' => $colDims,
            'measures' => $measures,
            'columns' => array_values($columns),
            'rows' => array_map($segment, array_values($tree)),
            'totals' => $this->roundPivotMeasures($measures, $grandTotals),
            'summary' => $this->baseSummary([
                'row_count' => $leafCount,
                'column_count' => count($columns),
                'channels_included' => self::CHANNELS_INCLUDED,
            ]),
            'pos_excluded_by_tag' => $filter->tag !== null,
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Insert one grouped cell into the row hierarchy, accumulating it into
     * every ancestor's per-column values and totals.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  list<string>  $rowValues
     * @param  array<string, float>  $cell
     * @param  list<string>  $measures
     */
    private function insertPivotNode(
        array &$nodes,
        int $depth,
        array $rowValues,
        string $columnKey,
        array $cell,
        array $measures,
    ): void {
        $value = $rowValues[$depth] ?? '';

        if (! isset($nodes[$value])) {
            $nodes[$value] = [
                'key' => array_slice($rowValues, 0, $depth + 1),
                'label' => $value,
                'depth' => $depth,
                'values' => [],
                'totals' => array_fill_keys($measures, 0.0),
                'children' => [],
            ];
        }

        $current = $nodes[$value]['values'][$columnKey] ?? array_fill_keys($measures, 0.0);
        foreach ($measures as $measure) {
            $current[$measure] += $cell[$measure] ?? 0.0;
            $nodes[$value]['totals'][$measure] += $cell[$measure] ?? 0.0;
        }
        $nodes[$value]['values'][$columnKey] = $current;

        if ($depth + 1 < count($rowValues)) {
            $this->insertPivotNode($nodes[$value]['children'], $depth + 1, $rowValues, $columnKey, $cell, $measures);
        }
    }

    /**
     * Order siblings by the first requested measure (descending), then label,
     * recursively — so the pivot opens on the biggest group first.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  list<string>  $measures
     */
    private function sortPivotNodes(array &$nodes, array $measures): void
    {
        $primary = $measures[0] ?? 'net_revenue';

        uasort($nodes, function (array $a, array $b) use ($primary): int {
            $comparison = ($b['totals'][$primary] ?? 0.0) <=> ($a['totals'][$primary] ?? 0.0);

            return $comparison !== 0 ? $comparison : ($a['label'] <=> $b['label']);
        });

        foreach ($nodes as &$node) {
            if ($node['children'] !== []) {
                $this->sortPivotNodes($node['children'], $measures);
            }
        }
        unset($node);
    }

    /**
     * Round one measure collection for output: quantities at the report's
     * 4-decimal scale (#592), money at 2. Issue #602's lifecycle amounts are
     * money too.
     *
     * @param  list<string>  $measures
     * @param  array<string, float>  $values
     * @return array<string, float>
     */
    private function roundPivotMeasures(array $measures, array $values): array
    {
        $moneyMeasures = [
            'net_revenue', 'cost', 'margin',
            'amount_ordered', 'amount_delivered', 'amount_invoiced', 'amount_to_invoice',
        ];

        $rounded = [];
        foreach ($measures as $measure) {
            $value = (float) ($values[$measure] ?? 0.0);
            $rounded[$measure] = in_array($measure, $moneyMeasures, true)
                ? round($value, 2)
                : $this->roundQuantity($value);
        }

        return $rounded;
    }

    /**
     * Human label for one column value (month keys become `Aug 2026`).
     */
    private function pivotValueLabel(string $dimension, string $value): string
    {
        if ($dimension === 'month' && preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            return Carbon::createFromFormat('Y-m', $value)->format('M Y');
        }

        return $value;
    }

    /**
     * @param  list<string>  $values
     */
    private function pivotKey(array $values): string
    {
        return implode("\x1F", $values);
    }

    /**
     * The fixed SQL expression for each whitelisted pivot dimension. No input
     * is interpolated — an unknown dimension is a programming error, not a
     * user error (the controller's Rule::in already 422s it).
     */
    private function pivotDimensionSql(string $dimension): string
    {
        return match ($dimension) {
            'salesperson' => "COALESCE(NULLIF(li.salesperson, ''), sp.name, 'Unassigned')",
            'team' => "COALESCE(NULLIF(li.sales_team, ''), 'Unassigned')",
            'customer' => "COALESCE(c.name, 'Walk-in Customer')",
            'product' => "COALESCE(p.name, 'Unknown product')",
            'category' => "COALESCE(pc.name, 'Uncategorized')",
            'country' => 'COALESCE((SELECT ca.country FROM customer_addresses ca'
                .' WHERE ca.customer_id = li.customer_id'
                ." AND ca.country IS NOT NULL AND ca.country <> ''"
                ." ORDER BY ca.is_default DESC, ca.id LIMIT 1), 'Unknown')",
            'month' => "TO_CHAR(li.revenue_date, 'YYYY-MM')",
            default => throw new \InvalidArgumentException("Unsupported pivot dimension [{$dimension}]."),
        };
    }

    /**
     * The fixed aggregate for each whitelisted pivot measure. Paid/FOC split
     * on the line's own document revenue, so #560's zero-net FOC line is
     * counted as FOC while its units still cost margin.
     *
     * Issue #602 lifecycle measures (per line, over the union's columns):
     *   - qty_ordered      SUM(ordered_quantity) — SO line `quantity −
     *                      quantity_cancelled`; POS / direct invoices fall
     *                      back to their quantity (#590 immediate sale)
     *   - qty_delivered    SUM(delivered_quantity) — mirrored by delivery
     *                      completion; falls back to the invoice quantity
     *   - qty_invoiced     SUM(invoiced_quantity) — invoice lines less
     *                      credit-note lines
     *   - qty_to_invoice   SUM(delivered − invoiced), NOT clamped: a negative
     *                      value means more was invoiced than delivered
     *                      (pre/over-invoicing), which must stay visible
     *   - amount_*         base-currency, tax-excluded, header-discount-net
     *                      values of the matching quantity, at the owning
     *                      document's snapshot rate (#355); amount_to_invoice
     *                      is delivered − invoiced under the same rule
     */
    private function pivotMeasureSql(string $measure): string
    {
        return match ($measure) {
            'paid_qty' => 'SUM(CASE WHEN li.document_revenue <> 0 THEN li.quantity ELSE 0 END)',
            'foc_qty' => 'SUM(CASE WHEN li.document_revenue = 0 THEN li.quantity ELSE 0 END)',
            'net_revenue' => 'SUM(li.base_revenue)',
            'cost' => 'SUM(li.base_cost)',
            'margin' => 'SUM(li.base_revenue - li.base_cost)',
            'qty_ordered' => 'SUM(li.ordered_quantity)',
            'qty_delivered' => 'SUM(li.delivered_quantity)',
            'qty_invoiced' => 'SUM(li.invoiced_quantity)',
            'qty_to_invoice' => 'SUM(li.delivered_quantity - li.invoiced_quantity)',
            'amount_ordered' => 'SUM(li.base_ordered_amount)',
            'amount_delivered' => 'SUM(li.base_delivered_amount)',
            'amount_invoiced' => 'SUM(li.base_invoiced_amount)',
            'amount_to_invoice' => 'SUM(li.base_delivered_amount - li.base_invoiced_amount)',
            default => throw new \InvalidArgumentException("Unsupported pivot measure [{$measure}]."),
        };
    }

    /**
     * @param  list<string>  $dimensions
     * @return list<string>
     */
    private function validatedPivotDimensions(array $dimensions, string $field): array
    {
        $validated = [];
        foreach ($dimensions as $dimension) {
            if (! is_string($dimension) || ! in_array($dimension, self::PIVOT_DIMENSIONS, true)) {
                throw new \InvalidArgumentException("Unsupported pivot {$field} [".(is_string($dimension) ? $dimension : gettype($dimension)).'].');
            }
            if (! in_array($dimension, $validated, true)) {
                $validated[] = $dimension;
            }
        }

        return $validated;
    }

    /**
     * @param  list<string>  $measures
     * @return list<string>
     */
    private function validatedPivotMeasures(array $measures): array
    {
        $validated = [];
        foreach ($measures as $measure) {
            if (! is_string($measure) || ! in_array($measure, self::PIVOT_MEASURES, true)) {
                throw new \InvalidArgumentException('Unsupported pivot measure ['.(is_string($measure) ? $measure : gettype($measure)).'].');
            }
            if (! in_array($measure, $validated, true)) {
                $validated[] = $measure;
            }
        }

        return $validated === [] ? self::PIVOT_MEASURES : $validated;
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
     * Issue #604: the same net revenue, split by revenue stream
     * (invoice / pos / credit_note / booking) for the reconciliation footer's
     * breakdown. Sums to the report's total_revenue for the same filters.
     *
     * @return array{invoice: float, pos: float, credit_note: float, booking: float}
     */
    public function getSalesChannelBreakdown(ReportFilter $filter): array
    {
        [$salesOrderIds, $invoiceIds] = $this->documentIdsForTag($filter->tag ?? null);

        $breakdown = ['invoice' => 0.0, 'pos' => 0.0, 'credit_note' => 0.0, 'booking' => 0.0];

        $rows = DB::query()
            ->fromSub($this->lineItemsUnion($filter, $salesOrderIds, $invoiceIds), 'li')
            ->selectRaw('li.channel, SUM(li.base_revenue) as total_revenue')
            ->groupBy('li.channel')
            ->get();

        foreach ($rows as $row) {
            if (array_key_exists((string) $row->channel, $breakdown)) {
                $breakdown[(string) $row->channel] = round((float) $row->total_revenue, 2);
            }
        }

        return $breakdown;
    }

    /**
     * Issue #602: the "unbilled deliveries" view — completed delivery-order
     * lines whose delivered quantity still exceeds what has been invoiced.
     *
     * This is the DO-side companion of the pivot's `qty_to_invoice` measure:
     * every row here has `quantity_delivered > quantity_invoiced`, so the two
     * views agree on what is still awaiting an invoice. Only completed
     * deliveries count (status `done`); a draft/confirmed DO has not shipped
     * yet even though its line already carries a planned delivered quantity.
     *
     * The same #593 filter contract as every other sales report scopes the
     * list: period on the delivery date, customer, product, category,
     * salesperson (the customer's), legal entity, channel (the linked sales
     * order's) and currency. A tag filter restricts to delivery orders whose
     * sales order carries the tag — standalone delivery orders cannot carry a
     * tag and are therefore excluded while one is active.
     *
     * Amounts are tax-excluded, in the tenant BASE currency:
     * (delivered − invoiced) × the line's unit price × the document snapshot
     * rate. The price falls back to the sales-order line for delivery lines
     * created before the DO carried its own price.
     *
     * @return array<string, mixed>
     */
    public function getUnbilledDeliveries(ReportFilter $filter): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        [$salesOrderIds] = $this->documentIdsForTag($filter->tag ?? null);

        // Timestamps are UTC; delivery_date is already a tenant-local calendar
        // date. The fallback keeps a completed DO without a delivery date in
        // its completion day (#594's timezone rule).
        $timezone = $this->tenantTimezone();
        $deliveryDate = "COALESCE(d.delivery_date, (d.completed_at AT TIME ZONE 'UTC' AT TIME ZONE '{$timezone}')::date,"
            ." (d.created_at AT TIME ZONE 'UTC' AT TIME ZONE '{$timezone}')::date)";

        try {
            $query = DB::table('delivery_order_lines as dol')
                ->join('delivery_orders as d', 'd.id', '=', 'dol.delivery_order_id')
                ->leftJoin('sales_order_lines as sol', 'sol.id', '=', 'dol.sales_order_line_id')
                ->leftJoin('customers as c', 'c.id', '=', 'd.customer_id')
                ->leftJoin('products as p', 'p.id', '=', 'dol.product_id')
                ->whereNull('d.deleted_at')
                ->where('d.status', 'done')
                ->where(function ($line) {
                    $line->whereNull('dol.line_type')->orWhere('dol.line_type', 'product');
                })
                ->whereRaw('dol.quantity_delivered > dol.quantity_invoiced')
                ->whereRaw("{$deliveryDate} BETWEEN ? AND ?", [
                    $startDate->toDateString(),
                    $endDate->toDateString(),
                ]);

            if ($filter->customerId !== null) {
                $query->where('d.customer_id', (int) $filter->customerId);
            }

            if ($filter->productId !== null) {
                $query->where('dol.product_id', (int) $filter->productId);
            }

            if ($filter->categoryId !== null) {
                $categoryId = (int) $filter->categoryId;
                $query->whereIn('dol.product_id', function ($sub) use ($categoryId) {
                    $sub->select('id')->from('products')->where('category_id', $categoryId);
                });
            }

            if ($filter->salespersonId !== null) {
                $salespersonId = (int) $filter->salespersonId;
                $query->whereIn('d.customer_id', function ($sub) use ($salespersonId) {
                    $sub->select('id')->from('customers')->where('salesperson_id', $salespersonId);
                });
            }

            if ($filter->legalEntityId !== null) {
                $query->where('d.legal_entity_id', (int) $filter->legalEntityId);
            }

            if ($filter->channel !== null && $filter->channel !== '') {
                $channel = strtolower($filter->channel);
                if (! in_array($channel, ['pos', 'admin', 'ecommerce', 'storefront'], true) || $channel === 'pos') {
                    // Delivery orders only exist for order-backed channels.
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('d.sales_order_id', function ($sub) use ($channel) {
                        $sub->select('id')->from('sales_orders')->where('channel', $channel);
                    });
                }
            }

            if ($filter->currencyCode !== null && $filter->currencyCode !== '') {
                $safe = preg_replace('/[^A-Za-z]/', '', $filter->currencyCode);
                $query->where('d.currency_code', $safe);
            }

            if ($filter->tag !== null) {
                $soIds = array_map('intval', (array) $salesOrderIds);
                if ($soIds === []) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereIn('d.sales_order_id', $soIds);
                }
            }

            $rows = $query
                ->select([
                    'd.id as delivery_order_id',
                    'd.delivery_order_number',
                    'd.delivery_date',
                    'd.sales_order_id',
                    'd.customer_id',
                    'c.name as customer_name',
                    'dol.id as delivery_order_line_id',
                    'dol.product_id',
                    'p.name as product_name',
                    'p.short_description as product_short_description',
                    'p.sku',
                    'dol.quantity_ordered',
                    'dol.quantity_delivered',
                    'dol.quantity_invoiced',
                    'd.currency_code',
                    DB::raw('(dol.quantity_delivered - dol.quantity_invoiced) as quantity_to_invoice'),
                    DB::raw('COALESCE(dol.unit_price, sol.unit_price, 0) as unit_price'),
                    DB::raw('ROUND((dol.quantity_delivered - dol.quantity_invoiced)'
                        .' * COALESCE(dol.unit_price, sol.unit_price, 0)'
                        .' * COALESCE(d.exchange_rate, 1.0), 2) as amount_to_invoice'),
                ])
                ->orderBy('d.delivery_date')
                ->orderBy('dol.id')
                ->get();
        } catch (\Throwable $e) {
            Log::error('SalesAnalyticsService getUnbilledDeliveries error', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $deliveries = $rows->map(fn ($row) => [
            'delivery_order_id' => (int) $row->delivery_order_id,
            'delivery_order_number' => $row->delivery_order_number,
            'delivery_date' => $row->delivery_date,
            'sales_order_id' => $row->sales_order_id !== null ? (int) $row->sales_order_id : null,
            'customer_id' => $row->customer_id !== null ? (int) $row->customer_id : null,
            'customer_name' => $row->customer_name ?? 'Walk-in Customer',
            'product_id' => $row->product_id !== null ? (int) $row->product_id : null,
            'product_name' => $row->product_name ?? 'Unknown product',
            'short_description' => $row->product_short_description ?? '',
            'sku' => $row->sku,
            'quantity_ordered' => $this->roundQuantity($row->quantity_ordered),
            'quantity_delivered' => $this->roundQuantity($row->quantity_delivered),
            'quantity_invoiced' => $this->roundQuantity($row->quantity_invoiced),
            'quantity_to_invoice' => $this->roundQuantity($row->quantity_to_invoice),
            'unit_price' => round((float) $row->unit_price, 2),
            'amount_to_invoice' => round((float) $row->amount_to_invoice, 2),
            'currency_code' => $row->currency_code,
        ])->all();

        return [
            'title' => 'Unbilled Deliveries',
            'pos_excluded_by_tag' => $filter->tag !== null,
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'deliveries' => $deliveries,
            'summary' => $this->baseSummary([
                'line_count' => count($deliveries),
                'delivery_order_count' => $rows->pluck('delivery_order_id')->unique()->count(),
                'total_quantity_delivered' => $this->roundQuantity($rows->sum('quantity_delivered')),
                'total_quantity_invoiced' => $this->roundQuantity($rows->sum('quantity_invoiced')),
                'total_quantity_to_invoice' => $this->roundQuantity(
                    $rows->sum(fn ($row) => (float) $row->quantity_delivered - (float) $row->quantity_invoiced)
                ),
                'total_amount_to_invoice' => round((float) $rows->sum('amount_to_invoice'), 2),
                'channels_included' => self::CHANNELS_INCLUDED,
            ]),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Issue #596 (RPT-UX1): the source documents behind one report row.
     *
     * A row is identified by its tab's dimension and key — `customer`,
     * `product` or `category` with an id (`null` for the walk-in /
     * unlinked-lines / Uncategorized bucket), or `period` with the trend
     * period key for `$interval`. The documents are read from the SAME
     * lineItemsUnion() as the row, with the same filter (and therefore the
     * same #593 dimensions, tag and currency), narrowed to the row exactly the
     * way the row's own GROUP BY buckets it. Each document's net amount is
     * the sum of its base-currency line revenue, so the documents add up to
     * the row value to the cent (both sum the same rounded line amounts).
     *
     * @return array<string, mixed>
     */
    public function getSalesDrilldown(
        ReportFilter $filter,
        string $dimension,
        ?string $key,
        string $interval = 'day',
        ?string $documentType = null,
    ): array {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        [$salesOrderIds, $invoiceIds] = $this->documentIdsForTag($filter->tag ?? null);

        $query = DB::query()
            ->fromSub($this->lineItemsUnion($filter, $salesOrderIds, $invoiceIds), 'li')
            ->leftJoin('customers as cust', 'cust.id', '=', 'li.customer_id');

        // Issue #532: a credit-note analysis row drills to its credit notes
        // only — the same union arm the row's credited amount is summed from.
        if ($documentType === 'credit_note') {
            $query->where('li.channel', 'credit_note');
        } elseif ($documentType !== null) {
            throw new \InvalidArgumentException("Unknown drill-down document type: {$documentType}");
        }

        $id = $key === null ? null : (int) $key;

        switch ($dimension) {
            case 'salesperson':
                // Issue #532: the customer's assigned salesperson, as the #593
                // salesperson filter and getCreditNoteAnalysis() bucket it.
                $id === null ? $query->whereNull('cust.salesperson_id') : $query->where('cust.salesperson_id', $id);
                break;

            case 'reason':
                // Issue #532: a credit note's reason, bucketed exactly as
                // getCreditNoteAnalysis() labels it (the key is that label).
                if ($documentType !== 'credit_note' || $key === null) {
                    throw new \InvalidArgumentException('A reason drill-down needs document_type=credit_note and a reason key.');
                }
                $this->joinCreditNoteReason($query);
                $query->whereRaw(self::CREDIT_NOTE_REASON_SQL.' = ?', [$key]);
                break;

            case 'customer':
                // buildSalesByCustomer groups by li.customer_id.
                $id === null ? $query->whereNull('li.customer_id') : $query->where('li.customer_id', $id);
                break;

            case 'product':
                // buildSalesByProduct groups by p.id over a LEFT JOIN, and
                // drops layout (note / section) lines.
                $query->leftJoin('products as p', 'p.id', '=', 'li.product_id')
                    ->where(function ($q) {
                        $q->whereNull('li.line_type')->orWhereNotIn('li.line_type', self::NON_SALE_LINE_TYPES);
                    });
                $id === null ? $query->whereNull('p.id') : $query->where('p.id', $id);
                break;

            case 'category':
                // buildSalesByCategory groups by c.id over two LEFT JOINs.
                $query->leftJoin('products as p', 'p.id', '=', 'li.product_id')
                    ->leftJoin('product_categories as c', 'c.id', '=', 'p.category_id');
                $id === null ? $query->whereNull('c.id') : $query->where('c.id', $id);
                break;

            case 'period':
                // buildSalesTrends buckets by TO_CHAR(revenue_date, format).
                $format = self::DRILLDOWN_PERIOD_FORMATS[$interval] ?? null;
                if ($format === null || $key === null) {
                    throw new \InvalidArgumentException('A period drill-down needs a period key and a valid interval.');
                }
                $query->whereRaw("TO_CHAR(li.revenue_date, '{$format}') = ?", [$key]);
                break;

            default:
                throw new \InvalidArgumentException("Unknown drill-down dimension: {$dimension}");
        }

        try {
            $rows = $query
                ->select([
                    'li.order_ref',
                    'li.channel',
                    'li.customer_id',
                    'cust.name as customer_name',
                    DB::raw('MIN(li.revenue_date) as document_date'),
                    DB::raw('MAX(li.currency_code) as currency_code'),
                    DB::raw('SUM(li.document_revenue) as document_amount'),
                    DB::raw('SUM(li.base_revenue) as net_amount'),
                ])
                ->groupBy('li.order_ref', 'li.channel', 'li.customer_id', 'cust.name')
                ->orderByDesc('document_date')
                ->orderBy('li.order_ref')
                ->get();
        } catch (\Throwable $e) {
            Log::error('SalesAnalyticsService getSalesDrilldown error', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // order_ref is '<prefix>:<id>' (lineItemsUnion); resolve each document's
        // own number in one query per document type.
        $types = [
            'inv' => ['invoice', 'invoices', 'invoice_number'],
            'cn' => ['credit_note', 'customer_credit_notes', 'credit_note_number'],
            'pos' => ['pos_order', 'pos_orders', 'order_number'],
            'so' => ['sales_order', 'sales_orders', 'sales_order_number'],
        ];

        $idsByPrefix = [];
        foreach ($rows as $row) {
            [$prefix, $docId] = explode(':', (string) $row->order_ref, 2);
            $idsByPrefix[$prefix][] = (int) $docId;
        }

        $numbers = [];
        foreach ($idsByPrefix as $prefix => $ids) {
            [, $table, $column] = $types[$prefix];
            $numbers[$prefix] = DB::table($table)->whereIn('id', array_unique($ids))->pluck($column, 'id')->all();
        }

        $documents = $rows->map(function ($row) use ($types, $numbers) {
            [$prefix, $docId] = explode(':', (string) $row->order_ref, 2);
            $docId = (int) $docId;

            return [
                'document_type' => $types[$prefix][0],
                'document_id' => $docId,
                'document_number' => $numbers[$prefix][$docId] ?? ('#'.$docId),
                'document_date' => $row->document_date !== null
                    ? Carbon::parse($row->document_date)->format('Y-m-d')
                    : null,
                'channel' => $row->channel,
                'customer_id' => $row->customer_id !== null ? (int) $row->customer_id : null,
                'customer_name' => $row->customer_name ?? 'Walk-in Customer',
                'currency_code' => $row->currency_code,
                'document_amount' => round((float) $row->document_amount, 2),
                'net_amount' => round((float) $row->net_amount, 2),
            ];
        })->values()->all();

        return [
            'title' => 'Sales Drill-down',
            'dimension' => $dimension,
            'key' => $key,
            'interval' => $dimension === 'period' ? $interval : null,
            'pos_excluded_by_tag' => $filter->tag !== null,
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'documents' => $documents,
            'summary' => $this->baseSummary([
                'document_count' => count($documents),
                'total_net_amount' => round((float) $rows->sum('net_amount'), 2),
            ]),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Issue #532 (SALES-ODOO): credit note analysis — credit-rate KPIs
     * (credited ÷ invoiced) grouped by reason, product, customer or salesperson.
     *
     * Read from the SAME lineItemsUnion() as every sales report, with the same
     * #593 filters (legal entity included) and tag rule, so:
     *   - credited = posted, non-voided credit notes' net line amounts
     *     (tax excluded, header discount off), in the BASE currency at the
     *     note's own snapshot rate (#355/#363), reported as a positive figure;
     *   - invoiced = posted invoices' net line amounts on the same basis
     *     (the invoice arm only: POS sales and un-invoiced bookings are revenue
     *     but not invoicing, so they are not a credit-rate denominator).
     *
     * A row exists for every bucket holding at least one credit note. Invoices
     * carry no reason, so a reason row's invoiced figure is all invoicing in
     * scope. Salesperson is the customer's assigned salesperson
     * (customers.salesperson_id), the same bucket the #593 salesperson filter
     * and the drill-down use. Each row carries the applied / refunded state of
     * its notes from CustomerCreditNoteOutcomeService (#525/#528).
     *
     * @return array<string, mixed>
     */
    public function getCreditNoteAnalysis(ReportFilter $filter, string $groupBy): array
    {
        if (! in_array($groupBy, self::CREDIT_NOTE_GROUPINGS, true)) {
            throw new \InvalidArgumentException("Unsupported credit-note grouping [{$groupBy}].");
        }

        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        [$salesOrderIds, $invoiceIds] = $this->documentIdsForTag($filter->tag ?? null);

        $base = fn () => DB::query()
            ->fromSub($this->lineItemsUnion($filter, $salesOrderIds, $invoiceIds), 'li')
            ->whereIn('li.channel', ['invoice', 'credit_note']);

        $query = $base()->leftJoin('customers as cust', 'cust.id', '=', 'li.customer_id');

        switch ($groupBy) {
            case 'reason':
                $this->joinCreditNoteReason($query);
                $query->where('li.channel', 'credit_note');
                $keySql = self::CREDIT_NOTE_REASON_SQL;
                $labelSql = self::CREDIT_NOTE_REASON_SQL;
                break;

            case 'product':
                // As buildSalesByProduct (and the product drill-down).
                $query->leftJoin('products as p', 'p.id', '=', 'li.product_id')
                    ->where(function ($q) {
                        $q->whereNull('li.line_type')->orWhereNotIn('li.line_type', self::NON_SALE_LINE_TYPES);
                    });
                $keySql = 'p.id';
                $labelSql = "COALESCE(p.name, '".self::UNLINKED_PRODUCT_LABEL."')";
                break;

            case 'customer':
                $keySql = 'li.customer_id';
                $labelSql = "COALESCE(cust.name, 'Walk-in Customer')";
                break;

            default: // salesperson
                $query->leftJoin('users as sp', 'sp.id', '=', 'cust.salesperson_id');
                $keySql = 'cust.salesperson_id';
                $labelSql = "COALESCE(sp.name, 'Unassigned')";
                break;
        }

        try {
            $rows = $query
                ->selectRaw("{$keySql} AS group_key")
                ->selectRaw("{$labelSql} AS group_label")
                ->selectRaw("SUM(CASE WHEN li.channel = 'credit_note' THEN -li.base_revenue ELSE 0 END) AS credited_amount")
                ->selectRaw("SUM(CASE WHEN li.channel = 'invoice' THEN li.base_revenue ELSE 0 END) AS invoiced_amount")
                ->selectRaw("STRING_AGG(DISTINCT CASE WHEN li.channel = 'credit_note' THEN SPLIT_PART(li.order_ref, ':', 2) END, ',') AS credit_note_ids")
                ->groupByRaw('1, 2')
                ->havingRaw("COUNT(CASE WHEN li.channel = 'credit_note' THEN 1 END) > 0")
                ->orderByDesc('credited_amount')
                ->orderBy('group_label')
                ->get();

            $totalInvoiced = round((float) $base()->where('li.channel', 'invoice')->sum('li.base_revenue'), 2);
        } catch (\Throwable $e) {
            Log::error('SalesAnalyticsService getCreditNoteAnalysis error', ['error' => $e->getMessage()]);

            throw $e;
        }

        $noteIdsByRow = $rows->map(fn ($row) => array_values(array_unique(array_map(
            'intval',
            array_filter(explode(',', (string) $row->credit_note_ids), fn ($id) => $id !== ''),
        ))));
        $outcomes = $this->creditNoteOutcomes($noteIdsByRow->flatten()->unique()->values()->all());

        $data = $rows->values()->map(function ($row, int $index) use ($groupBy, $totalInvoiced, $noteIdsByRow, $outcomes) {
            $credited = round((float) $row->credited_amount, 2);
            $invoiced = $groupBy === 'reason' ? $totalInvoiced : round((float) $row->invoiced_amount, 2);
            $noteIds = $noteIdsByRow[$index];

            $counts = ['applied' => 0, 'partially_applied' => 0, 'customer_credit' => 0];
            foreach ($noteIds as $noteId) {
                $state = $outcomes[$noteId]['state'] ?? null;
                if ($state !== null && isset($counts[$state])) {
                    $counts[$state]++;
                }
            }

            return [
                'key' => $row->group_key === null
                    ? null
                    : ($groupBy === 'reason' ? (string) $row->group_key : (int) $row->group_key),
                'label' => (string) $row->group_label,
                'credit_note_count' => count($noteIds),
                'credited_amount' => $credited,
                'invoiced_amount' => $invoiced,
                'credit_rate' => $this->creditRate($credited, $invoiced),
                'outcome' => $counts,
            ];
        })->all();

        $totalCredited = round((float) $rows->sum('credited_amount'), 2);

        $summaryOutcome = ['applied' => 0, 'partially_applied' => 0, 'customer_credit' => 0];
        $appliedBase = $refundedBase = $remainingBase = 0.0;
        foreach ($outcomes as $outcome) {
            if (isset($summaryOutcome[$outcome['state']])) {
                $summaryOutcome[$outcome['state']]++;
            }
            $appliedBase += $outcome['applied_amount_base'];
            $refundedBase += $outcome['refunded_amount_base'];
            $remainingBase += $outcome['remaining_credit_base'];
        }

        return [
            'title' => 'Credit Note Analysis',
            'group_by' => $groupBy,
            'pos_excluded_by_tag' => $filter->tag !== null,
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'rows' => $data,
            'summary' => $this->baseSummary([
                'credit_note_count' => count($outcomes),
                'total_credited' => $totalCredited,
                'total_invoiced' => $totalInvoiced,
                'net_invoiced' => round($totalInvoiced - $totalCredited, 2),
                'credit_rate' => $this->creditRate($totalCredited, $totalInvoiced),
                'outcome' => $summaryOutcome + [
                    // The notes' own (tax-inclusive) credit, converted at each
                    // note's snapshot rate: what was applied to invoices,
                    // refunded to the customer, or is still held as credit.
                    'applied_amount' => round($appliedBase, 2),
                    'refunded_amount' => round($refundedBase, 2),
                    'remaining_credit' => round($remainingBase, 2),
                    'basis' => 'Credit note totals including tax, in the base currency.',
                ],
                'credit_rate_basis' => 'Credited ÷ invoiced: posted credit notes over posted invoices, '
                    .'both net of tax and document discounts, in the base currency. '
                    .'Drafts and voided credit notes are excluded.',
            ]),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Groupings the credit-note analysis accepts; the controller validates
     * against this list.
     *
     * @return list<string>
     */
    public static function creditNoteGroupings(): array
    {
        return self::CREDIT_NOTE_GROUPINGS;
    }

    private function creditRate(float $credited, float $invoiced): ?float
    {
        return $invoiced > 0 ? round($credited / $invoiced * 100, 2) : null;
    }

    /**
     * Applied / refunded / remaining state per posted credit note, from
     * CustomerCreditNoteOutcomeService (#525/#528), with its amounts converted
     * to the base currency at the note's snapshot rate.
     *
     * @param  list<int>  $ids
     * @return array<int, array{state: string, applied_amount_base: float, refunded_amount_base: float, remaining_credit_base: float}>
     */
    private function creditNoteOutcomes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $notes = \App\Models\CustomerCreditNote::with('invoice')->whereIn('id', $ids)->get();
        app(\App\Services\CustomerCreditNoteOutcomeService::class)->annotate($notes);

        $result = [];
        foreach ($notes as $note) {
            $outcome = $note->getAttribute('outcome');
            if (! is_array($outcome)) {
                continue;
            }
            $rate = (float) ($note->exchange_rate ?: 1.0);
            $result[(int) $note->id] = [
                'state' => (string) $outcome['state'],
                'applied_amount_base' => round((float) $outcome['applied_amount'] * $rate, 2),
                'refunded_amount_base' => round((float) $outcome['refunded_amount'] * $rate, 2),
                'remaining_credit_base' => round((float) $outcome['remaining_credit'] * $rate, 2),
            ];
        }

        return $result;
    }

    /**
     * Join each credit-note line of the union to its credit note, for the
     * reason bucket (CREDIT_NOTE_REASON_SQL). Non-credit-note rows join nothing.
     */
    private function joinCreditNoteReason(\Illuminate\Database\Query\Builder $query): void
    {
        $query->leftJoin(
            'customer_credit_notes as cnr',
            DB::raw('cnr.id'),
            '=',
            DB::raw("(CASE WHEN li.channel = 'credit_note' THEN SPLIT_PART(li.order_ref, ':', 2)::bigint END)"),
        );
    }

    /**
     * Document ids carrying the requested tag: [sales-order ids, invoice ids].
     *
     * Both are null when no tag filter is active. An empty array means "the tag
     * exists but nothing carries it" and must produce an empty report, never an
     * unfiltered one. Credit notes and POS orders cannot carry tags, so an
     * active filter excludes them.
     *
     * @return array{0: ?array<int>, 1: ?array<int>}
     */
    private function documentIdsForTag(?string $tag): array
    {
        if (! $tag) {
            return [null, null];
        }

        $tagService = app(\App\Services\TagService::class);

        return [
            $tagService->subjectIdsForTagSlugs([$tag], 'sales_order'),
            $tagService->subjectIdsForTagSlugs([$tag], 'invoice'),
        ];
    }

    /**
     * Issue #594: the tenant timezone from company settings, defaulting to the
     * application timezone and falling back to UTC when unset or unknown.
     *
     * The value is interpolated into SQL (`AT TIME ZONE`, `generate_series`),
     * so it is only ever returned after passing PHP's own timezone identifier
     * list — a tenant-stored string can never reach the query raw.
     */
    private function tenantTimezone(): string
    {
        try {
            $timezone = $this->tableExists('company_settings')
                ? DB::table('company_settings')->orderBy('id')->value('timezone')
                : null;
        } catch (\Throwable) {
            $timezone = null;
        }

        if (! is_string($timezone) || $timezone === '') {
            $timezone = (string) config('app.timezone', 'UTC');
        }

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'UTC';
    }

    /**
     * UNION ALL of the four revenue streams, all on ONE definition (#355):
     *   - posted POS sales, NET of POS returns. A return order's lines are
     *     already written as negatives (PosOrder::createReturn()), so including
     *     returns nets them instead of dropping them;
     *   - un-invoiced non-POS sales-order lines (bookings; an SO that has a
     *     posted invoice - directly or through a delivery order - is dropped
     *     here so it counts exactly once, through its invoice);
     *   - posted invoice lines (POS-origin invoices excluded to avoid double
     *     counting with `pos_orders`);
     *   - posted customer-credit-note lines, negated.
     *
     * Every arm reports tax-excluded, header-discount-net amounts in its
     * DOCUMENT currency (`document_revenue`); the outer wrapper converts to the
     * tenant base currency at the document's own snapshot rate (`base_revenue`),
     * which is the column every widget sums (#355).
     *
     * Returned columns: product_id, customer_id, quantity, ordered_quantity,
     * delivered_quantity, invoiced_quantity, revenue_date, channel ('pos' |
     * 'booking' | 'invoice' | 'credit_note'), order_ref, line_type (#360:
     * the document line's type; POS and credit-note lines are 'product'), currency_code,
     * exchange_rate, document_revenue, base_revenue, base_ordered_amount,
     * base_delivered_amount, base_invoiced_amount (#602), foc_quantity and
     * base_foc_list_value (#600: free units within `quantity`, and their
     * value at the line's list price).
     *
     * Implemented as a raw SQL subquery rather than a query-builder union
     * because fromSub() with a QB union is fragile across PG versions.
     * Values are inlined (all bound values are typed - Carbon date strings,
     * intval'ed id lists, fixed string labels, booleans) so there is no
     * SQL-injection surface.
     */
    private function lineItemsUnion(
        ReportFilter $filter,
        ?array $salesOrderIds = null,
        ?array $invoiceIds = null,
    ) {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();
        $currencyCode = $filter->currencyCode;

        $start = $startDate->format('Y-m-d H:i:s');
        $end = $endDate->copy()->endOfDay()->format('Y-m-d H:i:s');
        $baseCurrency = '(SELECT base_currency FROM company_settings ORDER BY id LIMIT 1)';

        // Issue #594: timestamps are stored in UTC while document dates are
        // already tenant-local calendar dates. Convert the timestamp arms to
        // the tenant timezone so a period is bucketed (and range-filtered) by
        // the tenant's calendar, not by UTC. Date arms are untouched.
        $timezone = $this->tenantTimezone();
        $posRevenueDate = "(COALESCE(po.paid_at, po.created_at) AT TIME ZONE 'UTC' AT TIME ZONE '{$timezone}')";
        $soRevenueDate = "COALESCE(so.order_date, (so.created_at AT TIME ZONE 'UTC' AT TIME ZONE '{$timezone}'))";

        // Issue #593: customer / product / category / salesperson / channel /
        // legal-entity filters, applied per arm so the read model is narrowed
        // by them instead of ignoring them.
        $dim = $this->dimensionClauses($filter);

        // MRD FR-8.1: currency dimension. Every arm carries a document currency
        // (POS orders snapshot theirs too), so an active filter keeps the
        // documents denominated in it and drops the rest.
        $soCurrencyFilter = '';
        $invoiceCurrencyFilter = '';
        $creditNoteCurrencyFilter = '';
        $posCurrencyFilter = '';
        if ($currencyCode !== null && $currencyCode !== '') {
            $safe = preg_replace('/[^A-Za-z]/', '', $currencyCode);
            $soCurrencyFilter = " AND so.currency_code = '{$safe}'";
            $invoiceCurrencyFilter = " AND i.currency_code = '{$safe}'";
            $creditNoteCurrencyFilter = " AND ccn.currency_code = '{$safe}'";
            $posCurrencyFilter = " AND COALESCE(po.currency, {$baseCurrency}, 'USD') = '{$safe}'";
        }

        // A tag filter only applies to documents that can carry tags. Credit
        // notes and POS orders cannot, so an active tag filter excludes them
        // rather than silently including untagged revenue. Invoices are kept
        // when the invoice itself is tagged or when it invoices a tagged SO.
        $posTagClause = $salesOrderIds === null ? '' : ' AND 1 = 0';
        $creditNoteTagClause = $salesOrderIds === null ? '' : ' AND 1 = 0';
        $soTagClause = '';
        $invoiceTagClause = '';
        if ($salesOrderIds !== null) {
            $soIds = array_map('intval', $salesOrderIds);
            $soTagClause = $soIds === [] ? ' AND 1 = 0' : ' AND so.id IN ('.implode(',', $soIds).')';

            $invIds = array_map('intval', (array) $invoiceIds);
            $invoiceArms = [];
            if ($invIds !== []) {
                $invoiceArms[] = 'i.id IN ('.implode(',', $invIds).')';
            }
            if ($soIds !== []) {
                $invoiceArms[] = 'i.sales_order_id IN ('.implode(',', $soIds).')';
            }
            $invoiceTagClause = $invoiceArms === [] ? ' AND 1 = 0' : ' AND ('.implode(' OR ', $invoiceArms).')';
        }

        // POS: line_total is tax-INCLUSIVE (PosPostingService reads it as
        // gross), so the net is line_total - tax_amount; the order discount and
        // loyalty discount are document discounts like any other.
        $posNet = $this->netLineRevenueSql(
            '(COALESCE(poi.line_total, 0) - COALESCE(poi.tax_amount, 0))',
            '(COALESCE(po.discount_amount, 0) + COALESCE(po.loyalty_discount, 0))',
            'po.id'
        );

        // Sales order: line_total is tax-INCLUSIVE too (SalesOrderLine's
        // calculateLineTotal stores TaxEngineService's gross), the header
        // carries a document discount, and the order snapshots its own rate.
        $soNet = $this->netLineRevenueSql(
            '(COALESCE(sol.line_total, 0) - COALESCE(sol.tax_amount, 0))',
            'COALESCE(so.discount_amount, 0)',
            'so.id'
        );

        // Invoice: line_total is tax-INCLUSIVE and already net of any LINE
        // discount; the HEADER discount still has to come off, spread pro-rata
        // (revenue = subtotal - discount_amount, exactly as InvoiceService posts).
        $invoiceNet = $this->netLineRevenueSql(
            '(COALESCE(il.line_total, 0) - COALESCE(il.tax_amount, 0))',
            'COALESCE(i.discount_amount, 0)',
            'i.id'
        );

        // Issue #600: free-of-charge (bonus scheme) units. The FOC flag lives on
        // the medical case line (`medical_case_items.is_foc`); a live case's
        // FOC units are mapped to the invoice line that bills them, or — before
        // invoicing — to the sales order line they were allocated from. They
        // are capped at the document line's quantity and valued at that line's
        // unit (list) price. Revenue is untouched: FOC units are already in the
        // line's net amount at zero. POS and credit-note lines carry no FOC.
        $focBySalesOrderLine = $this->focQuantitiesSql('sales_order_item_id', 'allocated_qty');
        $focByInvoiceLine = $this->focQuantitiesSql('invoice_item_id', 'invoiced_qty');

        // Credit note: since #512 CustomerCreditNoteLine computes its totals with
        // the invoice line arithmetic (InvoiceLine::computeLineTotals), so
        // line_total is tax-INCLUSIVE and net of the line discount, exactly
        // like an invoice line. Issue #532: the arm used to read line_total as
        // tax-exclusive, so a taxed credit note reduced revenue by its gross.
        // The header discount (pro-rated from the source invoice) comes off
        // pro-rata, matching the posting (revenue = subtotal - discount_amount).
        $creditNoteNet = $this->netLineRevenueSql(
            '(COALESCE(cnl.line_total, 0) - COALESCE(cnl.tax_amount, 0))',
            'COALESCE(ccn.discount_amount, 0)',
            'ccn.id'
        );

        // Issue #601: the pivot groups by the document's own salesperson/team.
        // POS orders carry neither, so their arm leaves them NULL and the pivot
        // falls back to the customer's assigned salesperson; invoice and credit
        // note arms inherit them from the sales order they document.
        $arms = "(
            SELECT poi.product_id,
                   po.customer_id,
                   {$posNet} AS document_revenue,
                   COALESCE(poi.quantity, 0) AS quantity,
                   -- #602: an immediate sale is ordered, delivered and
                   -- invoiced in the same instant.
                   COALESCE(poi.quantity, 0) AS ordered_quantity,
                   COALESCE(poi.quantity, 0) AS delivered_quantity,
                   COALESCE(poi.quantity, 0) AS invoiced_quantity,
                   0 AS foc_quantity,
                   0 AS foc_list_value,
                   {$posRevenueDate} AS revenue_date,
                   'pos' AS channel,
                   'pos:' || po.id AS order_ref,
                   'product'::varchar(30) AS line_type,
                   COALESCE(po.currency, {$baseCurrency}, 'USD') AS currency_code,
                   COALESCE(po.exchange_rate, 1.0) AS exchange_rate,
                   NULL::varchar(255) AS salesperson,
                   NULL::varchar(255) AS sales_team,
                   -- #359: a POS return is costed through the posting of the
                   -- order it returns (its COGS reversal is linked there).
                   'pos_order'::varchar(30) AS cost_doc_type,
                   COALESCE(po.original_order_id, po.id) AS cost_doc_id
            FROM pos_order_items poi
            JOIN pos_orders po ON po.id = poi.pos_order_id
            WHERE {$posRevenueDate} BETWEEN '{$start}' AND '{$end}'
              AND po.status = 'paid'
              AND po.deleted_at IS NULL{$posTagClause}{$posCurrencyFilter}{$dim['pos']}
        ) UNION ALL (
            SELECT sol.product_id,
                   so.customer_id,
                   {$soNet} AS document_revenue,
                   COALESCE(sol.quantity, 0) AS quantity,
                   -- #602: an un-invoiced booking's ordered quantity is what
                   -- remains after cancellation; delivered is mirrored onto
                   -- the line by delivery completion; nothing is invoiced yet
                   -- (this arm only holds orders with no posted invoice).
                   GREATEST(COALESCE(sol.quantity, 0) - COALESCE(sol.quantity_cancelled, 0), 0) AS ordered_quantity,
                   COALESCE(sol.quantity_delivered, 0) AS delivered_quantity,
                   0 AS invoiced_quantity,
                   LEAST(COALESCE(sol.quantity, 0), COALESCE(foc_so.qty, 0)) AS foc_quantity,
                   LEAST(COALESCE(sol.quantity, 0), COALESCE(foc_so.qty, 0)) * COALESCE(sol.unit_price, 0) AS foc_list_value,
                   {$soRevenueDate} AS revenue_date,
                   'booking' AS channel,
                   'so:' || so.id AS order_ref,
                   sol.line_type::varchar(30) AS line_type,
                   COALESCE(so.currency_code, {$baseCurrency}, 'USD') AS currency_code,
                   COALESCE(so.exchange_rate, 1.0) AS exchange_rate,
                   so.salesperson,
                   so.sales_team,
                   'sales_order'::varchar(30) AS cost_doc_type,
                   so.id AS cost_doc_id
            FROM sales_order_lines sol
            JOIN sales_orders so ON so.id = sol.sales_order_id
            LEFT JOIN {$focBySalesOrderLine} foc_so ON foc_so.line_id = sol.id
            WHERE so.status NOT IN ('draft', 'cancelled')
              AND so.accounting_status <> 'reversed'
              AND so.channel <> 'pos'
              AND so.deleted_at IS NULL
              AND {$soRevenueDate} BETWEEN '{$start}' AND '{$end}'
              AND NOT EXISTS (
                  SELECT 1
                  FROM invoices si
                  WHERE si.deleted_at IS NULL
                    AND si.status <> 'cancelled'
                    AND si.lifecycle_status IN ('posted', 'credited')
                    AND (
                        si.sales_order_id = so.id
                        OR EXISTS (
                            SELECT 1
                            FROM invoice_delivery_orders ido
                            JOIN delivery_orders d ON d.id = ido.delivery_order_id
                            WHERE ido.invoice_id = si.id
                              AND d.sales_order_id = so.id
                        )
                    )
              ){$soTagClause}{$soCurrencyFilter}{$dim['so']}
        ) UNION ALL (
            SELECT il.product_id,
                   i.customer_id,
                   {$invoiceNet} AS document_revenue,
                   COALESCE(il.quantity, 0) AS quantity,
                   -- #602: a line billed from a sales order inherits that
                   -- line's ordered/delivered quantities (the order itself is
                   -- dropped from the booking arm, so it still counts once);
                   -- a direct invoice has no order/delivery to inherit, so it
                   -- falls back to the #590 immediate-sale rule (ordered =
                   -- delivered = invoiced = its quantity).
                   CASE WHEN sol.id IS NULL
                        THEN COALESCE(il.quantity, 0)
                        ELSE GREATEST(COALESCE(sol.quantity, 0) - COALESCE(sol.quantity_cancelled, 0), 0)
                   END AS ordered_quantity,
                   CASE WHEN sol.id IS NULL
                        THEN COALESCE(il.quantity, 0)
                        ELSE COALESCE(sol.quantity_delivered, 0)
                   END AS delivered_quantity,
                   COALESCE(il.quantity, 0) AS invoiced_quantity,
                   LEAST(COALESCE(il.quantity, 0), COALESCE(foc_il.qty, 0)) AS foc_quantity,
                   LEAST(COALESCE(il.quantity, 0), COALESCE(foc_il.qty, 0)) * COALESCE(il.unit_price, 0) AS foc_list_value,
                   i.invoice_date AS revenue_date,
                   'invoice' AS channel,
                   'inv:' || i.id AS order_ref,
                   il.line_type::varchar(30) AS line_type,
                   COALESCE(i.currency_code, {$baseCurrency}, 'USD') AS currency_code,
                   COALESCE(i.exchange_rate, 1.0) AS exchange_rate,
                   (SELECT so.salesperson FROM sales_orders so WHERE so.id = i.sales_order_id) AS salesperson,
                   (SELECT so.sales_team FROM sales_orders so WHERE so.id = i.sales_order_id) AS sales_team,
                   'invoice'::varchar(30) AS cost_doc_type,
                   i.id AS cost_doc_id
            FROM invoice_lines il
            JOIN invoices i ON i.id = il.invoice_id
            LEFT JOIN sales_order_lines sol ON sol.id = il.sales_order_line_id
            LEFT JOIN {$focByInvoiceLine} foc_il ON foc_il.line_id = il.id
            WHERE i.lifecycle_status IN ('posted', 'credited')
              AND i.status <> 'cancelled'
              AND i.deleted_at IS NULL
              AND i.invoice_date BETWEEN '{$start}' AND '{$end}'
              AND i.pos_order_id IS NULL
              AND (
                  i.sales_order_id IS NULL
                  OR NOT EXISTS (
                      SELECT 1 FROM sales_orders poso
                      WHERE poso.id = i.sales_order_id AND poso.channel = 'pos'
                  )
              ){$invoiceTagClause}{$invoiceCurrencyFilter}{$dim['invoice']}
        ) UNION ALL (
            SELECT cnl.product_id,
                   ccn.customer_id,
                   (-1 * {$creditNoteNet}) AS document_revenue,
                   (-1 * COALESCE(cnl.quantity, 0)) AS quantity,
                   -- #602: a credit note reverses invoiced quantity (and
                   -- amount); it does not change what was ordered/delivered,
                   -- so those stay 0 on this row.
                   0 AS ordered_quantity,
                   0 AS delivered_quantity,
                   (-1 * COALESCE(cnl.quantity, 0)) AS invoiced_quantity,
                   0 AS foc_quantity,
                   0 AS foc_list_value,
                   ccn.credit_note_date AS revenue_date,
                   'credit_note' AS channel,
                   'cn:' || ccn.id AS order_ref,
                   'product'::varchar(30) AS line_type,
                   COALESCE(ccn.currency_code, {$baseCurrency}, 'USD') AS currency_code,
                   COALESCE(ccn.exchange_rate, 1.0) AS exchange_rate,
                   (SELECT so.salesperson FROM invoices i JOIN sales_orders so ON so.id = i.sales_order_id WHERE i.id = ccn.invoice_id) AS salesperson,
                   (SELECT so.sales_team FROM invoices i JOIN sales_orders so ON so.id = i.sales_order_id WHERE i.id = ccn.invoice_id) AS sales_team,
                   'credit_note'::varchar(30) AS cost_doc_type,
                   ccn.id AS cost_doc_id
            FROM customer_credit_note_lines cnl
            JOIN customer_credit_notes ccn ON ccn.id = cnl.credit_note_id
            WHERE ccn.status = 'posted'
              AND ccn.voided_at IS NULL
              AND ccn.credit_note_date BETWEEN '{$start}' AND '{$end}'{$creditNoteTagClause}{$creditNoteCurrencyFilter}{$dim['credit_note']}
        )";

        // Outer wrapper: convert each document-currency amount to the base
        // currency at the document's own snapshot rate. Every widget except the
        // by-currency view sums this column (#355).
        //
        // Issue #602: the lifecycle amounts are the same net line value
        // apportioned to the ordered/delivered/invoiced quantity, so a partial
        // delivery or invoice contributes its own share of the line's value
        // at the same snapshot rate. A zero quantity contributes nothing
        // (NULLIF), never a division error.
        //
        // Issue #359: `base_cost` is the line's cost of goods sold AT THE TIME
        // OF SALE — see historicalCostSql(). Every cost / profit / margin
        // figure sums it instead of `quantity × products.cost` (today's cost).
        $costSql = $this->historicalCostSql();

        $sql = "WITH li_doc AS ({$arms}),
                {$costSql['ctes']}
                SELECT li_doc.product_id,
                       li_doc.customer_id,
                       li_doc.quantity,
                       li_doc.ordered_quantity,
                       li_doc.delivered_quantity,
                       li_doc.invoiced_quantity,
                       li_doc.foc_quantity,
                       li_doc.revenue_date,
                       li_doc.channel,
                       li_doc.order_ref,
                       li_doc.line_type,
                       li_doc.currency_code,
                       li_doc.exchange_rate,
                       li_doc.salesperson,
                       li_doc.sales_team,
                       li_doc.document_revenue,
                       ROUND(li_doc.document_revenue * li_doc.exchange_rate, 2) AS base_revenue,
                       ROUND(li_doc.foc_list_value * li_doc.exchange_rate, 2) AS base_foc_list_value,
                       ROUND(li_doc.document_revenue * li_doc.exchange_rate * li_doc.ordered_quantity / NULLIF(li_doc.quantity, 0), 2) AS base_ordered_amount,
                       ROUND(li_doc.document_revenue * li_doc.exchange_rate * li_doc.delivered_quantity / NULLIF(li_doc.quantity, 0), 2) AS base_delivered_amount,
                       ROUND(li_doc.document_revenue * li_doc.exchange_rate * li_doc.invoiced_quantity / NULLIF(li_doc.quantity, 0), 2) AS base_invoiced_amount,
                       {$costSql['column']} AS base_cost
                FROM li_doc
                {$costSql['joins']}";

        return DB::table(DB::raw('('.$sql.') AS li'));
    }

    /**
     * Issue #359: the line's cost of goods sold at the time of sale, in the
     * tenant BASE currency (stock valuation never converts — FR-12 §12).
     *
     * The source is the COGS the PostingEngine actually posted, read from the
     * journal: every posted, non-deleted entry linked to the line's costing
     * document (journal_entry_links), net debit on the mapped `cogs`
     * account(s). Reversals and reposts link to the same document, so a reset
     * → repost or a POS return nets itself. The costing document is:
     *   - an invoice line → the invoice, plus the delivery orders it bills
     *     (#682: a DO that shipped first carries the COGS and the invoice
     *     posts none). A DO billed by several posted invoices is shared
     *     equally between them, so its COGS is never counted twice;
     *   - a booking (un-invoiced SO) line → the SO's delivery orders;
     *   - a POS line → the original POS order (a return's reversal is linked
     *     to the order it returns);
     *   - a credit-note line → the credit note (a financial credit posts no
     *     COGS, so it costs nothing).
     *
     * The ledger posts one COGS amount per document, not per line, so the
     * document's COGS is spread over its product lines pro-rata to
     * `quantity × products.cost` (quantity alone when every cost is zero).
     * Per-document totals are exactly the posted COGS whatever the cost does
     * later; only the split between different products on the SAME document
     * follows their relative cost. Weights are taken over ALL the document's
     * lines (not only the ones a product/category filter keeps), so a filtered
     * report never piles the whole document's COGS onto the kept lines.
     *
     * A document with no posting at all (an un-invoiced booking that shipped
     * nothing, or data written without a journal entry) has no historical
     * cost to read, so it falls back to `quantity × products.cost`.
     *
     * @return array{ctes: string, joins: string, column: string}
     */
    private function historicalCostSql(): array
    {
        $cogsAccountIds = $this->cogsAccountIds();
        $cogsAccounts = $cogsAccountIds === [] ? 'NULL' : implode(',', $cogsAccountIds);
        $postedRelations = "'post', 'posting', 'repost', 'reversal', 'reversal_of', 'void'";
        $invoiceBillsDelivery = '(
                (i.sales_order_id IS NOT NULL AND d.sales_order_id = i.sales_order_id)
                OR EXISTS (
                    SELECT 1 FROM invoice_delivery_orders ido
                    WHERE ido.invoice_id = i.id AND ido.delivery_order_id = d.id
                )
            )';

        $ctes = "cost_refs AS (
                    SELECT DISTINCT cost_doc_type, cost_doc_id FROM li_doc
                ),
                cost_dos AS (
                    SELECT r.cost_doc_type, r.cost_doc_id, d.id AS delivery_order_id
                    FROM cost_refs r
                    JOIN invoices i ON r.cost_doc_type = 'invoice' AND i.id = r.cost_doc_id
                    JOIN delivery_orders d ON d.deleted_at IS NULL AND {$invoiceBillsDelivery}
                    UNION
                    SELECT r.cost_doc_type, r.cost_doc_id, d.id
                    FROM cost_refs r
                    JOIN delivery_orders d ON r.cost_doc_type = 'sales_order'
                     AND d.sales_order_id = r.cost_doc_id
                     AND d.deleted_at IS NULL
                ),
                cost_do_claims AS (
                    SELECT d.id AS delivery_order_id, COUNT(DISTINCT i.id) AS claimants
                    FROM delivery_orders d
                    JOIN invoices i ON i.deleted_at IS NULL
                     AND i.status <> 'cancelled'
                     AND i.lifecycle_status IN ('posted', 'credited')
                     AND {$invoiceBillsDelivery}
                    WHERE d.id IN (SELECT delivery_order_id FROM cost_dos)
                    GROUP BY d.id
                ),
                cost_entries AS (
                    SELECT l.doc_type, l.doc_id,
                           BOOL_OR(l.relation_type IN ('post', 'posting', 'repost')) AS posted,
                           SUM(COALESCE(c.cogs, 0)) AS cogs
                    FROM (
                        SELECT DISTINCT jl.journal_entry_id,
                               jl.source_document_type AS doc_type,
                               jl.source_document_id AS doc_id,
                               jl.relation_type
                        FROM journal_entry_links jl
                        JOIN (
                            SELECT cost_doc_type AS doc_type, cost_doc_id AS doc_id
                            FROM cost_refs WHERE cost_doc_type <> 'sales_order'
                            UNION
                            SELECT 'delivery_order', delivery_order_id FROM cost_dos
                        ) wanted ON wanted.doc_type = jl.source_document_type
                                AND wanted.doc_id = jl.source_document_id
                        WHERE jl.relation_type IN ({$postedRelations})
                    ) l
                    JOIN journal_entries je ON je.id = l.journal_entry_id
                     AND je.status = 'posted'
                     AND je.deleted_at IS NULL
                    LEFT JOIN LATERAL (
                        SELECT SUM(jel.debit - jel.credit) AS cogs
                        FROM journal_entry_lines jel
                        WHERE jel.journal_entry_id = je.id
                          AND jel.account_id IN ({$cogsAccounts})
                    ) c ON TRUE
                    GROUP BY l.doc_type, l.doc_id
                ),
                cost_docs AS (
                    SELECT r.cost_doc_type, r.cost_doc_id,
                           (COALESCE(own.posted, FALSE) OR COALESCE(dlv.posted, FALSE)) AS posted,
                           COALESCE(own.cogs, 0) + COALESCE(dlv.cogs, 0) AS cogs
                    FROM cost_refs r
                    LEFT JOIN cost_entries own ON r.cost_doc_type <> 'sales_order'
                     AND own.doc_type = r.cost_doc_type
                     AND own.doc_id = r.cost_doc_id
                    LEFT JOIN (
                        SELECT cd.cost_doc_type, cd.cost_doc_id,
                               BOOL_OR(ce.posted) AS posted,
                               SUM(ce.cogs / GREATEST(COALESCE(cl.claimants, 1), 1)) AS cogs
                        FROM cost_dos cd
                        JOIN cost_entries ce ON ce.doc_type = 'delivery_order'
                         AND ce.doc_id = cd.delivery_order_id
                        LEFT JOIN cost_do_claims cl ON cl.delivery_order_id = cd.delivery_order_id
                        GROUP BY cd.cost_doc_type, cd.cost_doc_id
                    ) dlv ON dlv.cost_doc_type = r.cost_doc_type AND dlv.cost_doc_id = r.cost_doc_id
                ),
                cost_weights AS (
                    SELECT 'invoice'::varchar(30) AS cost_doc_type, il.invoice_id AS cost_doc_id,
                           SUM(COALESCE(il.quantity, 0) * COALESCE(p.cost, 0)) AS weight,
                           SUM(CASE WHEN il.product_id IS NOT NULL THEN COALESCE(il.quantity, 0) ELSE 0 END) AS qty
                    FROM invoice_lines il
                    LEFT JOIN products p ON p.id = il.product_id
                    WHERE COALESCE(il.line_type, 'product') = 'product'
                      AND il.invoice_id IN (SELECT cost_doc_id FROM cost_refs WHERE cost_doc_type = 'invoice')
                    GROUP BY il.invoice_id
                    UNION ALL
                    SELECT 'sales_order', sol.sales_order_id,
                           SUM(COALESCE(sol.quantity, 0) * COALESCE(p.cost, 0)),
                           SUM(CASE WHEN sol.product_id IS NOT NULL THEN COALESCE(sol.quantity, 0) ELSE 0 END)
                    FROM sales_order_lines sol
                    LEFT JOIN products p ON p.id = sol.product_id
                    WHERE COALESCE(sol.line_type, 'product') = 'product'
                      AND sol.sales_order_id IN (SELECT cost_doc_id FROM cost_refs WHERE cost_doc_type = 'sales_order')
                    GROUP BY sol.sales_order_id
                    UNION ALL
                    SELECT 'pos_order', COALESCE(po.original_order_id, po.id),
                           SUM(COALESCE(poi.quantity, 0) * COALESCE(p.cost, 0)),
                           SUM(CASE WHEN poi.product_id IS NOT NULL THEN COALESCE(poi.quantity, 0) ELSE 0 END)
                    FROM pos_order_items poi
                    JOIN pos_orders po ON po.id = poi.pos_order_id
                    LEFT JOIN products p ON p.id = poi.product_id
                    WHERE po.status = 'paid'
                      AND po.deleted_at IS NULL
                      AND COALESCE(po.original_order_id, po.id) IN (SELECT cost_doc_id FROM cost_refs WHERE cost_doc_type = 'pos_order')
                    GROUP BY COALESCE(po.original_order_id, po.id)
                    UNION ALL
                    SELECT 'credit_note', cnl.credit_note_id,
                           SUM(-1 * COALESCE(cnl.quantity, 0) * COALESCE(p.cost, 0)),
                           SUM(CASE WHEN cnl.product_id IS NOT NULL THEN -1 * COALESCE(cnl.quantity, 0) ELSE 0 END)
                    FROM customer_credit_note_lines cnl
                    LEFT JOIN products p ON p.id = cnl.product_id
                    WHERE cnl.credit_note_id IN (SELECT cost_doc_id FROM cost_refs WHERE cost_doc_type = 'credit_note')
                    GROUP BY cnl.credit_note_id
                )";

        $joins = 'LEFT JOIN products cost_p ON cost_p.id = li_doc.product_id
                LEFT JOIN cost_docs ON cost_docs.cost_doc_type = li_doc.cost_doc_type
                     AND cost_docs.cost_doc_id = li_doc.cost_doc_id
                LEFT JOIN cost_weights ON cost_weights.cost_doc_type = li_doc.cost_doc_type
                     AND cost_weights.cost_doc_id = li_doc.cost_doc_id';

        $isCostedLine = "(COALESCE(li_doc.line_type, 'product') = 'product')";
        $column = "CASE WHEN COALESCE(cost_docs.posted, FALSE) THEN
                        CASE WHEN NOT {$isCostedLine} THEN 0
                             WHEN COALESCE(cost_weights.weight, 0) <> 0
                                 THEN cost_docs.cogs * li_doc.quantity * COALESCE(cost_p.cost, 0) / cost_weights.weight
                             WHEN COALESCE(cost_weights.qty, 0) <> 0 AND li_doc.product_id IS NOT NULL
                                 THEN cost_docs.cogs * li_doc.quantity / cost_weights.qty
                             ELSE 0
                        END
                        ELSE li_doc.quantity * COALESCE(cost_p.cost, 0)
                   END";

        return ['ctes' => $ctes, 'joins' => $joins, 'column' => $column];
    }

    /**
     * Issue #359: the mapped COGS account(s). Document-type-specific mappings
     * are honoured, like the revenue side of the #604 reconciliation. A tenant
     * with no mapping has posted no COGS, so the list is empty.
     *
     * @return list<int>
     */
    private function cogsAccountIds(): array
    {
        $ids = [];
        try {
            if ($this->tableExists('account_mappings')) {
                $ids = DB::table('account_mappings')
                    ->where('mapping_key', 'cogs')
                    ->where('is_active', true)
                    ->pluck('account_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }

            if ($ids === []) {
                $ids = [(int) app(\App\Accounting\Services\AccountMappingService::class)->resolveId('cogs')];
            }
        } catch (\Throwable $e) {
            // No COGS account ⇒ nothing was ever posted to one; posted
            // documents then carry zero COGS, which is what the ledger says.
            Log::info('SalesAnalyticsService: no COGS account mapped', ['error' => $e->getMessage()]);
        }

        return array_values(array_unique(array_filter($ids, fn (int $id) => $id > 0)));
    }

    /**
     * Issue #600: per-document-line FOC quantity from live medical case lines
     * flagged `is_foc`, as a derived table `(line_id, qty)` keyed by the
     * medical case column that points at the document line. Cancelled and
     * deleted cases gave nothing away, so they are skipped.
     */
    private function focQuantitiesSql(string $lineColumn, string $quantityColumn): string
    {
        if (! $this->tableExists('medical_case_items') || ! $this->tableExists('medical_cases')) {
            return '(SELECT NULL::bigint AS line_id, 0::numeric AS qty WHERE FALSE)';
        }

        return "(SELECT mci.{$lineColumn} AS line_id, SUM(mci.{$quantityColumn}) AS qty
                 FROM medical_case_items mci
                 JOIN medical_cases mc ON mc.id = mci.medical_case_id
                 WHERE mci.is_foc = TRUE
                   AND mci.{$lineColumn} IS NOT NULL
                   AND mc.status <> 'cancelled'
                   AND mc.deleted_at IS NULL
                 GROUP BY mci.{$lineColumn})";
    }

    /**
     * WHERE fragments for the #593 dimension filters — customer, product,
     * category, salesperson, channel and legal entity — one per union arm, so
     * a filter narrows the read model itself instead of being accepted and
     * then dropped.
     *
     * Each fragment starts with ' AND ' so it can be concatenated after the
     * arm's existing conditions. Aliases match lineItemsUnion: po/poi (POS),
     * so/sol (sales order), i/il (invoice), ccn/cnl (credit note).
     *
     * Channel per arm, from the document's real origin:
     *   - POS orders are the only thing that can be 'pos' — the sales-order
     *     arm already excludes pos-channel orders, and the invoice arm
     *     excludes POS-origin invoices, so both are suppressed outright;
     *   - a sales order carries channel directly (admin | ecommerce | storefront);
     *   - an invoice inherits its sales order's channel, else its POS/web
     *     origin, else 'admin' (a manual invoice);
     *   - a credit note reverses an invoice, so it carries that invoice's
     *     channel — a POS return stays 'pos'.
     *
     * Every interpolated value is an int-cast id or a channel literal from the
     * controller's in:pos,admin,ecommerce,storefront rule, so nothing
     * user-supplied is spliced in raw.
     *
     * @return array{pos: string, so: string, invoice: string, credit_note: string}
     */
    private function dimensionClauses(ReportFilter $filter): array
    {
        $clauses = ['pos' => '', 'so' => '', 'invoice' => '', 'credit_note' => ''];

        if ($filter->customerId !== null) {
            $id = (int) $filter->customerId;
            $clauses['pos'] .= " AND po.customer_id = {$id}";
            $clauses['so'] .= " AND so.customer_id = {$id}";
            $clauses['invoice'] .= " AND i.customer_id = {$id}";
            $clauses['credit_note'] .= " AND ccn.customer_id = {$id}";
        }

        if ($filter->productId !== null) {
            $id = (int) $filter->productId;
            $clauses['pos'] .= " AND poi.product_id = {$id}";
            $clauses['so'] .= " AND sol.product_id = {$id}";
            $clauses['invoice'] .= " AND il.product_id = {$id}";
            $clauses['credit_note'] .= " AND cnl.product_id = {$id}";
        }

        if ($filter->categoryId !== null) {
            $id = (int) $filter->categoryId;
            $in = " IN (SELECT id FROM products WHERE category_id = {$id})";
            $clauses['pos'] .= " AND poi.product_id{$in}";
            $clauses['so'] .= " AND sol.product_id{$in}";
            $clauses['invoice'] .= " AND il.product_id{$in}";
            $clauses['credit_note'] .= " AND cnl.product_id{$in}";
        }

        if ($filter->salespersonId !== null) {
            $id = (int) $filter->salespersonId;
            $owns = " IN (SELECT id FROM customers WHERE salesperson_id = {$id})";
            $clauses['pos'] .= " AND po.customer_id{$owns}";
            $clauses['so'] .= " AND so.customer_id{$owns}";
            $clauses['invoice'] .= " AND i.customer_id{$owns}";
            $clauses['credit_note'] .= " AND ccn.customer_id{$owns}";
        }

        if ($filter->legalEntityId !== null) {
            $id = (int) $filter->legalEntityId;
            $clauses['pos'] .= " AND po.legal_entity_id = {$id}";
            $clauses['so'] .= " AND so.legal_entity_id = {$id}";
            $clauses['invoice'] .= " AND i.legal_entity_id = {$id}";
            $clauses['credit_note'] .= " AND ccn.legal_entity_id = {$id}";
        }

        $channel = $filter->channel !== null && $filter->channel !== ''
            ? strtolower($filter->channel)
            : null;
        if ($channel !== null) {
            if (! in_array($channel, ['pos', 'admin', 'ecommerce', 'storefront'], true)) {
                return array_map(fn () => ' AND 1 = 0', $clauses);
            }

            $clauses['pos'] .= $channel === 'pos' ? '' : ' AND 1 = 0';
            $clauses['so'] .= $channel === 'pos'
                ? ' AND 1 = 0'
                : " AND so.channel = '{$channel}'";

            $invoiceExpr = $this->invoiceChannelSql('i');
            $clauses['invoice'] .= " AND ({$invoiceExpr}) = '{$channel}'";
            $clauses['credit_note'] .= " AND (SELECT {$invoiceExpr}"
                .' FROM invoices i WHERE i.id = ccn.invoice_id)'
                ." = '{$channel}'";
        }

        return $clauses;
    }

    /**
     * The channel an invoice belongs to: its sales order's channel, else its
     * POS/web origin, else 'admin'. A correlated subquery in SQL because the
     * invoice arm and the credit-note arm both need it against a different
     * invoice alias (`i` in each case — the credit-note arm correlates it to
     * the invoice being reversed).
     */
    private function invoiceChannelSql(string $alias): string
    {
        return "COALESCE((SELECT so.channel FROM sales_orders so WHERE so.id = {$alias}.sales_order_id),"
            ." CASE WHEN {$alias}.pos_order_id IS NOT NULL THEN 'pos'"
            ." WHEN {$alias}.web_order_id IS NOT NULL THEN 'ecommerce'"
            ." ELSE 'admin' END)";
    }

    /**
     * Tax-excluded, header-discount-net line revenue for one arm.
     *
     * `$lineNet` is the arm's per-line pre-tax amount and `$docDiscount` the
     * document's header discount, spread pro-rata over the document's own lines
     * (a window sum partitioned by the document) so the parts add back up to the
     * header amount - the same base the postings use (revenue = subtotal -
     * discount_amount). A zero-net document allocates nothing (NULLIF).
     */
    private function netLineRevenueSql(string $lineNet, string $docDiscount, string $docKey): string
    {
        return "ROUND({$lineNet} - COALESCE({$docDiscount}, 0) * {$lineNet}"
            ." / NULLIF(SUM({$lineNet}) OVER (PARTITION BY {$docKey}), 0), 2)";
    }

    /**
     * Base-unit quantity scale used across the stock path: #250 widened
     * stock_levels / stock_movements / products.stock_quantity to numeric(15,4),
     * sales_order_lines is decimal(15,4) (cast decimal:4) and the pipeline
     * analytics already round to 4. Issue #592: report quantities at that scale
     * instead of (int)-truncating them, so the Qty Sold column reconciles with
     * the revenue those quantities earned.
     */
    /**
     * Issue #359: margin % weighted by revenue — total profit over total
     * revenue — so a small high-margin row cannot outweigh a large one.
     */
    private function revenueWeightedMargin(mixed $profit, mixed $revenue): float
    {
        $revenue = (float) $revenue;

        return $revenue > 0 ? round((float) $profit / $revenue * 100, 2) : 0.0;
    }

    private function roundQuantity(mixed $quantity): float
    {
        return round((float) $quantity, 4);
    }

    /**
     * Every summary carries what it measured and in which currency (#355), so a
     * dashboard can label the figure instead of guessing.
     */
    private function baseSummary(array $summary): array
    {
        try {
            $baseCurrency = DocumentCurrencyResolver::baseFor();
        } catch (\Throwable) {
            // A tenant whose company_settings table is not provisioned yet must
            // still get a report, not an exception out of the summary builder.
            $baseCurrency = 'USD';
        }

        return array_merge($summary, [
            'revenue_basis' => self::REVENUE_BASIS,
            'base_currency' => $baseCurrency,
            'includes_tax' => false,
        ]);
    }

    protected function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Exception $e) {
            return false;
        }
    }
}

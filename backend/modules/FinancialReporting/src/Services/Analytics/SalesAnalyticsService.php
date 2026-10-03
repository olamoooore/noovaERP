<?php

declare(strict_types=1);

namespace Modules\FinancialReporting\Services\Analytics;

use App\Services\DocumentCurrencyResolver;
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
    /**
     * Channels surfaced in every report below.
     */
    private const CHANNELS_INCLUDED = ['pos', 'admin', 'ecommerce'];

    /**
     * The human-readable definition of every "revenue" figure in this service.
     * Surfaced in each summary so no caller has to guess (#355).
     */
    private const REVENUE_BASIS = 'Net revenue, tax excluded, document discounts subtracted, '
        .'reported in the tenant base currency: posted invoices less posted customer '
        .'credit notes, plus POS sales net of returns, plus un-invoiced bookings. '
        .'Dated by document date; excludes shipping (posted to its own revenue account).';

    public function getSalesByProduct(ReportFilter $filter): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        [$salesOrderIds, $invoiceIds] = $this->documentIdsForTag($filter->tag ?? null);

        try {
            $sales = DB::query()
                ->fromSub($this->lineItemsUnion($startDate, $endDate, $salesOrderIds, $filter->currencyCode, $invoiceIds), 'li')
                ->leftJoin('products as p', 'p.id', '=', 'li.product_id')
                ->select([
                    'p.id',
                    'p.name',
                    'p.sku',
                    'p.cost',
                    'p.price',
                    DB::raw('SUM(li.quantity) as total_quantity'),
                    DB::raw('SUM(li.base_revenue) as total_revenue'),
                    DB::raw('SUM(CASE WHEN li.channel = \'booking\' THEN li.base_revenue ELSE 0 END) as bookings_revenue'),
                    DB::raw('SUM(CASE WHEN li.channel <> \'booking\' THEN li.base_revenue ELSE 0 END) as invoiced_revenue'),
                    DB::raw('SUM(li.quantity * COALESCE(p.cost, 0)) as total_cost'),
                    DB::raw('SUM(li.base_revenue - (li.quantity * COALESCE(p.cost, 0))) as total_profit'),
                    DB::raw('CASE WHEN SUM(li.base_revenue) > 0 THEN (SUM(li.base_revenue - (li.quantity * COALESCE(p.cost, 0))) / SUM(li.base_revenue) * 100) ELSE 0 END as profit_margin'),
                    DB::raw('COUNT(*) as order_count'),
                ])
                ->groupBy('p.id', 'p.name', 'p.sku', 'p.cost', 'p.price')
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
            'average_profit_margin' => $sales->count() > 0 ? round((float) $sales->avg('profit_margin'), 2) : 0,
            'total_quantity_sold' => $this->roundQuantity($sales->sum('total_quantity')),
            'channels_included' => self::CHANNELS_INCLUDED,
        ]);

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
                'total_quantity' => $this->roundQuantity($item->total_quantity),
                'quantity_sold' => $this->roundQuantity($item->total_quantity),
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

        [$salesOrderIds, $invoiceIds] = $this->documentIdsForTag($filter->tag ?? null);

        try {
            // Identical line-item revenue base as every other widget (#355/#590):
            // posted invoices, posted credit notes, POS sales net of returns and
            // un-invoiced bookings, all tax-excluded, discount-net and in the base
            // currency, with walk-ins (POS customer_id null) in the same aggregate.
            $rows = DB::query()
                ->fromSub($this->lineItemsUnion($startDate, $endDate, $salesOrderIds, $filter->currencyCode, $invoiceIds), 'li')
                ->leftJoin('customers as c', 'c.id', '=', 'li.customer_id')
                ->select([
                    'li.customer_id',
                    'c.name as customer_name',
                    'c.email as customer_email',
                    DB::raw('COUNT(DISTINCT li.order_ref) as order_count'),
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

    /**
     * MRD pricelist-multicurrency FR-8.1 — revenue split by document currency,
     * with the snapshot-rate base equivalent per bucket. This is the ONLY view
     * that reports document-currency amounts; `total_revenue` here is
     * tax-excluded and discount-net like every other view (#355), and
     * `base_revenue` is the figure the other widgets total.
     */
    public function getSalesByCurrency(ReportFilter $filter): array
    {
        $startDate = $filter->startDate ?? Carbon::now()->startOfMonth();
        $endDate = $filter->endDate ?? Carbon::now()->endOfMonth();

        [$salesOrderIds, $invoiceIds] = $this->documentIdsForTag($filter->tag ?? null);

        try {
            $rows = DB::query()
                ->fromSub($this->lineItemsUnion($startDate, $endDate, $salesOrderIds, $filter->currencyCode, $invoiceIds), 'li')
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
            // Same line-item base as getSalesByCategory() / getSalesByProduct(),
            // so the trend series, the category split and the product table all
            // reconcile for the selected period (#355).
            $trends = DB::query()
                ->fromSub($this->lineItemsUnion($startDate, $endDate, $salesOrderIds, $filter->currencyCode, $invoiceIds), 'li')
                ->select([
                    DB::raw("TO_CHAR(li.revenue_date, '{$dateFormat}') as period"),
                    DB::raw('COUNT(DISTINCT li.order_ref) as order_count'),
                    DB::raw('SUM(li.base_revenue) as total_revenue'),
                    DB::raw('SUM(CASE WHEN li.channel = \'booking\' THEN li.base_revenue ELSE 0 END) as bookings_revenue'),
                    DB::raw('SUM(CASE WHEN li.channel <> \'booking\' THEN li.base_revenue ELSE 0 END) as invoiced_revenue'),
                    DB::raw('CASE WHEN COUNT(DISTINCT li.order_ref) > 0 THEN SUM(li.base_revenue) / COUNT(DISTINCT li.order_ref) ELSE 0 END as average_order_value'),
                ])
                ->groupBy('period')
                ->orderBy('period')
                ->get();
        } catch (\Throwable $e) {
            Log::error('SalesAnalyticsService getSalesTrends error', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
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
                'bookings_revenue' => round((float) $trend->bookings_revenue, 2),
                'invoiced_revenue' => round((float) $trend->invoiced_revenue, 2),
                'average_order_value' => round((float) $trend->average_order_value, 2),
                'growth_rate' => $growthRate !== null ? round($growthRate, 2) : null,
            ];
            $previousRevenue = $trend->total_revenue;
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

        [$salesOrderIds, $invoiceIds] = $this->documentIdsForTag($filter->tag ?? null);

        try {
            $sales = DB::query()
                ->fromSub($this->lineItemsUnion($startDate, $endDate, $salesOrderIds, $filter->currencyCode, $invoiceIds), 'li')
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
                    DB::raw('SUM(li.quantity * COALESCE(p.cost, 0)) as total_cost'),
                    DB::raw('SUM(li.base_revenue - (li.quantity * COALESCE(p.cost, 0))) as total_profit'),
                    DB::raw('CASE WHEN SUM(li.base_revenue) > 0 THEN (SUM(li.base_revenue - (li.quantity * COALESCE(p.cost, 0))) / SUM(li.base_revenue) * 100) ELSE 0 END as profit_margin'),
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
            'average_profit_margin' => $sales->count() > 0 ? round((float) $sales->avg('profit_margin'), 2) : 0,
            'channels_included' => self::CHANNELS_INCLUDED,
        ]);

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
     * Returned columns: product_id, customer_id, quantity, revenue_date,
     * channel ('pos' | 'booking' | 'invoice' | 'credit_note'), order_ref,
     * currency_code, exchange_rate, document_revenue, base_revenue.
     *
     * Implemented as a raw SQL subquery rather than a query-builder union
     * because fromSub() with a QB union is fragile across PG versions.
     * Values are inlined (all bound values are typed - Carbon date strings,
     * intval'ed id lists, fixed string labels, booleans) so there is no
     * SQL-injection surface.
     */
    private function lineItemsUnion(
        Carbon $startDate,
        Carbon $endDate,
        ?array $salesOrderIds = null,
        ?string $currencyCode = null,
        ?array $invoiceIds = null,
    ) {
        $start = $startDate->format('Y-m-d H:i:s');
        $end = $endDate->endOfDay()->format('Y-m-d H:i:s');
        $baseCurrency = '(SELECT base_currency FROM company_settings ORDER BY id LIMIT 1)';

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

        // Credit note: line_total is already tax-exclusive (CustomerCreditNoteLine
        // computes quantity x unit_price) and a credit note carries no discount.
        $arms = "(
            SELECT poi.product_id,
                   po.customer_id,
                   {$posNet} AS document_revenue,
                   COALESCE(poi.quantity, 0) AS quantity,
                   COALESCE(po.paid_at, po.created_at) AS revenue_date,
                   'pos' AS channel,
                   'pos:' || po.id AS order_ref,
                   COALESCE(po.currency, {$baseCurrency}, 'USD') AS currency_code,
                   COALESCE(po.exchange_rate, 1.0) AS exchange_rate
            FROM pos_order_items poi
            JOIN pos_orders po ON po.id = poi.pos_order_id
            WHERE COALESCE(po.paid_at, po.created_at) BETWEEN '{$start}' AND '{$end}'
              AND po.status = 'paid'
              AND po.deleted_at IS NULL{$posTagClause}{$posCurrencyFilter}
        ) UNION ALL (
            SELECT sol.product_id,
                   so.customer_id,
                   {$soNet} AS document_revenue,
                   COALESCE(sol.quantity, 0) AS quantity,
                   COALESCE(so.order_date, so.created_at) AS revenue_date,
                   'booking' AS channel,
                   'so:' || so.id AS order_ref,
                   COALESCE(so.currency_code, {$baseCurrency}, 'USD') AS currency_code,
                   COALESCE(so.exchange_rate, 1.0) AS exchange_rate
            FROM sales_order_lines sol
            JOIN sales_orders so ON so.id = sol.sales_order_id
            WHERE so.status NOT IN ('draft', 'cancelled')
              AND so.accounting_status <> 'reversed'
              AND so.channel <> 'pos'
              AND so.deleted_at IS NULL
              AND COALESCE(so.order_date, so.created_at) BETWEEN '{$start}' AND '{$end}'
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
              ){$soTagClause}{$soCurrencyFilter}
        ) UNION ALL (
            SELECT il.product_id,
                   i.customer_id,
                   {$invoiceNet} AS document_revenue,
                   COALESCE(il.quantity, 0) AS quantity,
                   i.invoice_date AS revenue_date,
                   'invoice' AS channel,
                   'inv:' || i.id AS order_ref,
                   COALESCE(i.currency_code, {$baseCurrency}, 'USD') AS currency_code,
                   COALESCE(i.exchange_rate, 1.0) AS exchange_rate
            FROM invoice_lines il
            JOIN invoices i ON i.id = il.invoice_id
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
              ){$invoiceTagClause}{$invoiceCurrencyFilter}
        ) UNION ALL (
            SELECT cnl.product_id,
                   ccn.customer_id,
                   (-1 * COALESCE(cnl.line_total, 0)) AS document_revenue,
                   (-1 * COALESCE(cnl.quantity, 0)) AS quantity,
                   ccn.credit_note_date AS revenue_date,
                   'credit_note' AS channel,
                   'cn:' || ccn.id AS order_ref,
                   COALESCE(ccn.currency_code, {$baseCurrency}, 'USD') AS currency_code,
                   COALESCE(ccn.exchange_rate, 1.0) AS exchange_rate
            FROM customer_credit_note_lines cnl
            JOIN customer_credit_notes ccn ON ccn.id = cnl.credit_note_id
            WHERE ccn.status = 'posted'
              AND ccn.voided_at IS NULL
              AND ccn.credit_note_date BETWEEN '{$start}' AND '{$end}'{$creditNoteTagClause}{$creditNoteCurrencyFilter}
        )";

        // Outer wrapper: convert each document-currency amount to the base
        // currency at the document's own snapshot rate. Every widget except the
        // by-currency view sums this column (#355).
        $sql = "SELECT product_id,
                       customer_id,
                       quantity,
                       revenue_date,
                       channel,
                       order_ref,
                       currency_code,
                       exchange_rate,
                       document_revenue,
                       ROUND(document_revenue * exchange_rate, 2) AS base_revenue
                FROM ({$arms}) AS li_doc";

        return DB::table(DB::raw('('.$sql.') AS li'));
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

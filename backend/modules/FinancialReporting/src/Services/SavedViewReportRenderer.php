<?php

declare(strict_types=1);

namespace Modules\FinancialReporting\Services;

use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Models\ReportSavedView;
use Modules\FinancialReporting\Services\Analytics\SalesAnalyticsService;
use Modules\FinancialReporting\Services\Export\CsvExportService;
use Modules\FinancialReporting\Services\Export\PdfExportService;

/**
 * Issue #598 (RPT-UX3) — render a saved sales view server-side.
 *
 * The scheduled command must not call HTTP endpoints, so this service calls
 * the same SalesAnalyticsService methods the report routes call and turns the
 * result into the export payload CsvExportService / PdfExportService expect:
 * ['title' => …, 'period' => ['start_date' => …, 'end_date' => …],
 *  'data' => [['Column label' => value, …], …]].
 *
 * The pivot is flattened into one CSV/PDF row per hierarchy node, with one
 * column per (column group × measure) and a total column per measure.
 */
class SavedViewReportRenderer
{
    /**
     * Column whitelist per tab: response key => human CSV header, in order.
     * Keeps ids/aliases out of the exported file.
     */
    private const TAB_COLUMNS = [
        'by-product' => [
            'name' => 'Product',
            'sku' => 'SKU',
            'total_quantity' => 'Qty Sold',
            'total_revenue' => 'Revenue',
            'total_cost' => 'Cost',
            'total_profit' => 'Profit',
            'profit_margin' => 'Margin %',
        ],
        'by-customer' => [
            'name' => 'Customer',
            'total_orders' => 'Orders',
            'total_revenue' => 'Revenue',
            'average_order_value' => 'Avg Order Value',
            'last_purchase_date' => 'Last Purchase',
        ],
        'trends' => [
            'period' => 'Period',
            'order_count' => 'Orders',
            'revenue' => 'Revenue',
            'average_order_value' => 'Avg Order Value',
        ],
        'by-category' => [
            'category_name' => 'Category',
            'product_count' => 'Products',
            'quantity_sold' => 'Qty Sold',
            'revenue' => 'Revenue',
            'cost' => 'Cost',
            'profit' => 'Profit',
            'profit_margin' => 'Margin %',
        ],
        'unbilled' => [
            'delivery_order_number' => 'Delivery Order',
            'delivery_date' => 'Date',
            'customer_name' => 'Customer',
            'product_name' => 'Product',
            'sku' => 'SKU',
            'quantity_delivered' => 'Qty Delivered',
            'quantity_invoiced' => 'Qty Invoiced',
            'quantity_to_invoice' => 'Qty To Invoice',
            'unit_price' => 'Unit Price',
            'amount_to_invoice' => 'Amount To Invoice',
        ],
    ];

    private const TAB_TITLES = [
        'by-product' => 'Sales by Product',
        'by-customer' => 'Sales by Customer',
        'trends' => 'Sales Trends',
        'by-category' => 'Sales by Category',
        'pivot' => 'Sales Analysis Pivot',
        'unbilled' => 'Unbilled Deliveries',
    ];

    public function __construct(
        private readonly SalesAnalyticsService $salesAnalytics,
        private readonly CsvExportService $csvExport,
        private readonly PdfExportService $pdfExport,
    ) {}

    /**
     * Build the export payload for a saved view (no file written yet).
     *
     * @return array{title: string, period: array{start_date: string, end_date: string}, data: list<array<string, mixed>>, generated_at: string}
     */
    public function renderExportPayload(ReportSavedView $view): array
    {
        $filter = ReportFilter::fromRequest(is_array($view->filters) ? $view->filters : []);
        $report = $this->runTab($view, $filter);

        $period = $report['period'] ?? [
            'start_date' => ($filter->startDate ?? now()->startOfMonth())->format('Y-m-d'),
            'end_date' => ($filter->endDate ?? now()->endOfMonth())->format('Y-m-d'),
        ];

        return [
            'title' => $report['title'] ?? (self::TAB_TITLES[$view->tab] ?? 'Sales Report'),
            'period' => $period,
            'data' => $view->tab === 'pivot'
                ? $this->pivotRows($report)
                : $this->tabularRows($view->tab, $report['data'] ?? $report['deliveries'] ?? []),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Write the rendered view to a CSV or PDF file and return its full path.
     */
    public function exportFile(ReportSavedView $view, string $format): string
    {
        $payload = $this->renderExportPayload($view);
        $filename = 'saved-view-'.$view->id.'-'.now()->format('Y-m-d');

        return $format === 'pdf'
            ? $this->pdfExport->exportReport($payload, 'default', $filename)
            : $this->csvExport->exportReport($payload, $filename);
    }

    /**
     * @return array<string, mixed>
     */
    private function runTab(ReportSavedView $view, ReportFilter $filter): array
    {
        return match ($view->tab) {
            'by-product' => $this->salesAnalytics->getSalesByProduct($filter),
            'by-customer' => $this->salesAnalytics->getSalesByCustomer($filter),
            'trends' => $this->salesAnalytics->getSalesTrends($filter, $filter->interval ?: 'day'),
            'by-category' => $this->salesAnalytics->getSalesByCategory($filter),
            'unbilled' => $this->salesAnalytics->getUnbilledDeliveries($filter),
            'pivot' => $this->runPivot($view, $filter),
            default => throw new \InvalidArgumentException("Unsupported saved-view tab: {$view->tab}"),
        };
    }

    private function runPivot(ReportSavedView $view, ReportFilter $filter): array
    {
        $grouping = is_array($view->grouping) ? $view->grouping : [];

        $rowDims = array_values(array_filter((array) ($grouping['row_dims'] ?? ['product'])));
        $colDims = array_values(array_filter((array) ($grouping['col_dims'] ?? [])));
        $measures = array_values(array_filter((array) ($grouping['measures'] ?? SalesAnalyticsService::pivotMeasures())));

        if ($rowDims === []) {
            $rowDims = ['product'];
        }
        if ($measures === []) {
            $measures = SalesAnalyticsService::pivotMeasures();
        }

        return $this->salesAnalytics->getSalesPivot($filter, $rowDims, $colDims, $measures);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function tabularRows(string $tab, array $rows): array
    {
        $columns = self::TAB_COLUMNS[$tab] ?? null;
        $mapped = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            if ($columns === null) {
                $mapped[] = array_filter($row, fn ($value) => is_scalar($value) || $value === null);

                continue;
            }

            $exportRow = [];
            foreach ($columns as $key => $label) {
                $exportRow[$label] = $row[$key] ?? null;
            }
            $mapped[] = $exportRow;
        }

        return $mapped;
    }

    /**
     * Flatten the pivot hierarchy into one export row per node.
     *
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    private function pivotRows(array $report): array
    {
        $columns = array_values((array) ($report['columns'] ?? []));
        $measures = array_values((array) ($report['measures'] ?? []));
        $multiMeasure = count($measures) > 1;
        $rows = [];

        $walk = function (array $nodes, int $depth) use (&$walk, &$rows, $columns, $measures, $multiMeasure): void {
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }

                $row = ['Row' => str_repeat('  ', $depth).($node['label'] ?? '')];

                foreach (array_values((array) ($node['values'] ?? [])) as $index => $cell) {
                    foreach ($measures as $measure) {
                        $columnLabel = $columns[$index]['label'] ?? 'Column '.($index + 1);
                        $key = $this->uniqueKey($row, $multiMeasure ? "{$columnLabel} — {$measure}" : (string) $columnLabel);
                        $row[$key] = is_array($cell) ? ($cell[$measure] ?? 0) : 0;
                    }
                }

                foreach ($measures as $measure) {
                    $key = $this->uniqueKey($row, $multiMeasure ? "Total — {$measure}" : 'Total');
                    $row[$key] = $node['totals'][$measure] ?? 0;
                }

                $rows[] = $row;

                if (! empty($node['children'])) {
                    $walk((array) $node['children'], $depth + 1);
                }
            }
        };

        $walk((array) ($report['rows'] ?? []), 0);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function uniqueKey(array $row, string $key): string
    {
        if (! array_key_exists($key, $row)) {
            return $key;
        }

        $suffix = 2;
        while (array_key_exists("{$key} #{$suffix}", $row)) {
            $suffix++;
        }

        return "{$key} #{$suffix}";
    }
}

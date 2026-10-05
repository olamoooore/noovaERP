<?php

declare(strict_types=1);

namespace Modules\FinancialReporting\Services\Analytics;

use App\Accounting\Services\AccountMappingService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\FinancialReporting\DTOs\ReportFilter;

/**
 * Issue #604 (RPT-D1): ties every sales report's net revenue to the posted
 * ledger revenue for the SAME filters, and names what explains a difference.
 *
 * ── HOW THE TWO SIDES ARE BUILT ─────────────────────────────────────────
 *   - report_net_revenue: exactly what SalesAnalyticsService reports for the
 *     requested filters (#355 definition: posted invoices less credit notes,
 *     plus POS net of returns, plus un-invoiced bookings).
 *   - gl_revenue: the posted journal-entry lines on the tenant's mapped
 *     `sales_revenue` account(s) in the same period. Each line is attributed
 *     to the document it posted (journal_entry_links) and only counts while
 *     that document matches the report's dimension filters, so a customer /
 *     product / channel / ... filter narrows both sides.
 *   - difference = report_net_revenue - gl_revenue, decomposed into causes:
 *       * unposted_invoice — booking revenue in the report whose invoice is
 *         not posted (so the GL has nothing)            [report-only, +]
 *       * manual_journal   — posted revenue lines with no sales document
 *         (manual journals and other direct GL revenue)  [GL-only, -]
 *       * other_difference — residual (e.g. a product/category filter is
 *         applied to document-level ledger lines), so the causes always add
 *         up to the reported difference.
 *
 * Revenue recognition is deliberately NOT redefined here (#395 stays open):
 * the report side is the live report, the GL side is the live ledger.
 */
class SalesGlReconciliationService
{
    public function __construct(
        private readonly SalesAnalyticsService $salesAnalytics,
    ) {}

    /**
     * @return array{
     *     period: array{start_date: string, end_date: string},
     *     base_currency: string,
     *     report_net_revenue: float,
     *     gl_revenue: float,
     *     difference: float,
     *     reconciled: bool,
     *     report_breakdown: array<string, float>,
     *     causes: array<int, array<string, mixed>>,
     *     notes: array<int, string>
     * }
     */
    public function reconcile(ReportFilter $filter): array
    {
        $report = $this->salesAnalytics->getSalesByProduct($filter);
        $summary = $report['summary'] ?? [];

        $reportNetRevenue = round((float) ($summary['total_revenue'] ?? 0), 2);
        $bookingsRevenue = round((float) ($summary['bookings_revenue'] ?? 0), 2);

        $gl = $this->glRevenue($filter);
        $glRevenue = round($gl['total'], 2);
        $manualRevenue = round($gl['manual'], 2);

        $difference = round($reportNetRevenue - $glRevenue, 2);

        $causes = [];

        // Revenue the report books while the GL has no posting for it: the
        // un-invoiced/un-posted bookings (#355). The documents behind the
        // cause are the invoices that exist but were never posted.
        if (abs($bookingsRevenue) >= 0.01) {
            $documents = $this->unpostedInvoiceDocuments($filter);
            $causes[] = [
                'code' => 'unposted_invoice',
                'label' => 'Unposted invoice — in the report, not yet in the GL',
                'direction' => 'report_only',
                'amount' => $bookingsRevenue,
                'document_count' => count($documents),
                'documents' => $documents,
            ];
        }

        // Revenue the GL holds that no sales document posts: manual journals
        // and other direct ledger credits to the revenue account.
        if (abs($manualRevenue) >= 0.01) {
            $causes[] = [
                'code' => 'manual_journal',
                'label' => 'Manual journal — in the GL, not in the report',
                'direction' => 'gl_only',
                'amount' => round(-$manualRevenue, 2),
                'document_count' => count($gl['manual_documents']),
                'documents' => $gl['manual_documents'],
            ];
        }

        // Whatever the two named causes do not explain (rounding, a
        // product/category filter narrowing a document-level ledger line,
        // a posted document whose ledger amount drifted, ...). Kept explicit
        // so the causes always sum to the difference — never silently lost.
        $explained = round($bookingsRevenue - $manualRevenue, 2);
        $residual = round($difference - $explained, 2);

        if (abs($residual) >= 0.01) {
            $causes[] = [
                'code' => 'other_difference',
                'label' => $residual > 0
                    ? 'Other differences — in the report, not in the GL'
                    : 'Other differences — in the GL, not in the report',
                'direction' => $residual > 0 ? 'report_only' : 'gl_only',
                'amount' => $residual,
                'document_count' => 0,
                'documents' => [],
            ];
        }

        [$startDate, $endDate] = $this->period($filter);

        return [
            'period' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'base_currency' => (string) ($summary['base_currency'] ?? 'USD'),
            'report_net_revenue' => $reportNetRevenue,
            'gl_revenue' => $glRevenue,
            'difference' => $difference,
            'reconciled' => abs($difference) < 0.01,
            'report_breakdown' => $this->salesAnalytics->getSalesChannelBreakdown($filter),
            'causes' => $causes,
            'notes' => [],
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function period(ReportFilter $filter): array
    {
        return [
            ($filter->startDate ?? Carbon::now()->startOfMonth())->format('Y-m-d'),
            ($filter->endDate ?? Carbon::now()->endOfMonth())->format('Y-m-d'),
        ];
    }

    /**
     * Posted revenue-account lines with their source document, in period.
     *
     * A line is counted once even if its entry carries several post links.
     */
    private function revenueRows(ReportFilter $filter): Collection
    {
        [$start, $end] = $this->period($filter);

        return DB::table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->leftJoin('journal_entry_links as link', function ($join): void {
                $join->on('link.journal_entry_id', '=', 'je.id')
                    ->where('link.relation_type', '=', 'post');
            })
            ->whereIn('jel.account_id', $this->revenueAccountIds())
            ->where('je.status', 'posted')
            ->whereNull('je.deleted_at')
            ->whereBetween('je.entry_date', [$start, $end])
            ->orderBy('jel.id')
            ->get([
                'jel.id as line_id',
                'je.id as journal_entry_id',
                'je.entry_number',
                'je.legal_entity_id',
                DB::raw('(jel.credit - jel.debit) as amount'),
                'link.source_document_type',
                'link.source_document_id',
            ])
            ->unique('line_id')
            ->values();
    }

    /**
     * The mapped `sales_revenue` account(s). Document-type-specific mappings
     * are honoured too — an invoice and a credit note may map the role to
     * different accounts.
     *
     * @return array<int, int>
     */
    private function revenueAccountIds(): array
    {
        $ids = DB::table('account_mappings')
            ->where('mapping_key', 'sales_revenue')
            ->where('is_active', true)
            ->pluck('account_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            $ids = [app(AccountMappingService::class)->resolveId('sales_revenue')];
        }

        return $ids;
    }

    /**
     * Sum the GL side, attributing each line to its linked document so the
     * report's dimension filters narrow the ledger too. Unlinked revenue is
     * reported separately as the manual-journal cause.
     *
     * @return array{total: float, manual: float, manual_documents: array<int, array<string, mixed>>}
     */
    private function glRevenue(ReportFilter $filter): array
    {
        $rows = $this->revenueRows($filter);

        if ($rows->isEmpty()) {
            return ['total' => 0.0, 'manual' => 0.0, 'manual_documents' => []];
        }

        $context = $this->loadDocumentContext(
            $filter,
            $this->documentIdsOfType($rows, 'invoice'),
            $this->documentIdsOfType($rows, 'pos_order'),
            $this->documentIdsOfType($rows, 'credit_note'),
        );

        $total = 0.0;
        $manual = 0.0;
        $manualDocuments = [];

        foreach ($rows as $row) {
            $type = (string) ($row->source_document_type ?? '');
            $documentId = (int) ($row->source_document_id ?? 0);
            $amount = (float) $row->amount;

            if ($type === 'invoice') {
                if (isset($context['invoices'][$documentId])) {
                    if ($this->invoiceMatches($context['invoices'][$documentId], $filter, $context)) {
                        $total += $amount;
                    }
                } elseif (! $this->hasDocumentDimensionFilter($filter)) {
                    // Ledger revenue whose linked document is gone: the GL
                    // literally holds it, so keep it on the GL side and let
                    // the residual cause explain it.
                    $total += $amount;
                }

                continue;
            }

            if ($type === 'pos_order') {
                if (isset($context['pos_orders'][$documentId])) {
                    if ($this->posOrderMatches($context['pos_orders'][$documentId], $filter, $context)) {
                        $total += $amount;
                    }
                } elseif (! $this->hasDocumentDimensionFilter($filter)) {
                    $total += $amount;
                }

                continue;
            }

            if ($type === 'credit_note') {
                if (isset($context['credit_notes'][$documentId])) {
                    if ($this->creditNoteMatches($context['credit_notes'][$documentId], $filter, $context)) {
                        $total += $amount;
                    }
                } elseif (! $this->hasDocumentDimensionFilter($filter)) {
                    $total += $amount;
                }

                continue;
            }

            // No sales document behind the line: manual journal / direct GL
            // revenue. Only attributable to the report when no dimension
            // filter (customer, product, ...) is active.
            if ($this->manualRevenueMatches($row, $filter)) {
                $total += $amount;
                $manual += $amount;

                $entryId = (int) $row->journal_entry_id;
                $manualDocuments[$entryId] = [
                    'type' => 'journal_entry',
                    'id' => $entryId,
                    'label' => (string) $row->entry_number,
                    'url' => '/accounting/core/journal-entries?entry_id='.$entryId,
                ];
            }
        }

        ksort($manualDocuments);

        return [
            'total' => round($total, 2),
            'manual' => round($manual, 2),
            'manual_documents' => array_slice(array_values($manualDocuments), 0, 50),
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, int>
     */
    private function documentIdsOfType(Collection $rows, string $type): array
    {
        return $rows
            ->where('source_document_type', $type)
            ->pluck('source_document_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Everything needed to decide whether one linked document matches the
     * report's filters, loaded in bulk.
     *
     * @param  array<int, int>  $invoiceIds
     * @param  array<int, int>  $posOrderIds
     * @param  array<int, int>  $creditNoteIds
     * @return array<string, mixed>
     */
    private function loadDocumentContext(
        ReportFilter $filter,
        array $invoiceIds,
        array $posOrderIds,
        array $creditNoteIds,
    ): array {
        $context = [
            'invoices' => [],
            'pos_orders' => [],
            'credit_notes' => [],
            'sales_order_channels' => [],
            'salesperson_customer_ids' => [],
            'product_invoice_ids' => [],
            'category_invoice_ids' => [],
            'product_pos_order_ids' => [],
            'category_pos_order_ids' => [],
            'product_credit_note_ids' => [],
            'category_credit_note_ids' => [],
            'tag_invoice_ids' => [],
            'tag_sales_order_ids' => [],
        ];

        if ($creditNoteIds !== []) {
            $context['credit_notes'] = DB::table('customer_credit_notes')
                ->whereIn('id', $creditNoteIds)
                ->get(['id', 'credit_note_number', 'customer_id', 'invoice_id', 'legal_entity_id', 'currency_code'])
                ->keyBy('id')
                ->all();
        }

        // Load the invoices the revenue lines link to, plus the invoices a
        // credit note reverses (needed for its channel/tag dimensions).
        $linkedInvoiceIds = array_values(array_unique(array_merge(
            $invoiceIds,
            collect($context['credit_notes'])->pluck('invoice_id')->filter()->map(fn ($id) => (int) $id)->all(),
        )));

        if ($linkedInvoiceIds !== []) {
            $context['invoices'] = DB::table('invoices')
                ->whereIn('id', $linkedInvoiceIds)
                ->get(['id', 'invoice_number', 'customer_id', 'sales_order_id', 'pos_order_id', 'web_order_id', 'legal_entity_id', 'currency_code'])
                ->keyBy('id')
                ->all();

            $salesOrderIds = collect($context['invoices'])
                ->pluck('sales_order_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($salesOrderIds !== []) {
                $context['sales_order_channels'] = DB::table('sales_orders')
                    ->whereIn('id', $salesOrderIds)
                    ->pluck('channel', 'id')
                    ->all();
            }
        }

        if ($posOrderIds !== []) {
            $context['pos_orders'] = DB::table('pos_orders')
                ->whereIn('id', $posOrderIds)
                ->get(['id', 'customer_id', 'legal_entity_id', 'currency'])
                ->keyBy('id')
                ->all();
        }

        if ($filter->salespersonId !== null) {
            $context['salesperson_customer_ids'] = DB::table('customers')
                ->where('salesperson_id', $filter->salespersonId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $invoiceContextIds = array_map('intval', array_keys($context['invoices']));

        if ($filter->productId !== null) {
            if ($invoiceContextIds !== []) {
                $context['product_invoice_ids'] = DB::table('invoice_lines')
                    ->whereIn('invoice_id', $invoiceContextIds)
                    ->where('product_id', $filter->productId)
                    ->distinct()
                    ->pluck('invoice_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }
            if ($posOrderIds !== []) {
                $context['product_pos_order_ids'] = DB::table('pos_order_items')
                    ->whereIn('pos_order_id', $posOrderIds)
                    ->where('product_id', $filter->productId)
                    ->distinct()
                    ->pluck('pos_order_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }
            if ($creditNoteIds !== []) {
                $context['product_credit_note_ids'] = DB::table('customer_credit_note_lines')
                    ->whereIn('credit_note_id', $creditNoteIds)
                    ->where('product_id', $filter->productId)
                    ->distinct()
                    ->pluck('credit_note_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }
        }

        if ($filter->categoryId !== null) {
            if ($invoiceContextIds !== []) {
                $context['category_invoice_ids'] = DB::table('invoice_lines as il')
                    ->join('products as p', 'p.id', '=', 'il.product_id')
                    ->whereIn('il.invoice_id', $invoiceContextIds)
                    ->where('p.category_id', $filter->categoryId)
                    ->distinct()
                    ->pluck('il.invoice_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }
            if ($posOrderIds !== []) {
                $context['category_pos_order_ids'] = DB::table('pos_order_items as poi')
                    ->join('products as p', 'p.id', '=', 'poi.product_id')
                    ->whereIn('poi.pos_order_id', $posOrderIds)
                    ->where('p.category_id', $filter->categoryId)
                    ->distinct()
                    ->pluck('poi.pos_order_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }
            if ($creditNoteIds !== []) {
                $context['category_credit_note_ids'] = DB::table('customer_credit_note_lines as cnl')
                    ->join('products as p', 'p.id', '=', 'cnl.product_id')
                    ->whereIn('cnl.credit_note_id', $creditNoteIds)
                    ->where('p.category_id', $filter->categoryId)
                    ->distinct()
                    ->pluck('cnl.credit_note_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
            }
        }

        if ($filter->tag !== null && $filter->tag !== '') {
            $tagService = app(\App\Services\TagService::class);
            $context['tag_sales_order_ids'] = array_map('intval', $tagService->subjectIdsForTagSlugs([$filter->tag], 'sales_order'));
            $context['tag_invoice_ids'] = array_map('intval', $tagService->subjectIdsForTagSlugs([$filter->tag], 'invoice'));
        }

        return $context;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function invoiceMatches(object $invoice, ReportFilter $filter, array $context): bool
    {
        if ($filter->customerId !== null && (int) $invoice->customer_id !== (int) $filter->customerId) {
            return false;
        }

        if ($filter->legalEntityId !== null && (int) $invoice->legal_entity_id !== (int) $filter->legalEntityId) {
            return false;
        }

        if ($filter->currencyCode !== null && strtoupper((string) $invoice->currency_code) !== $filter->currencyCode) {
            return false;
        }

        if ($filter->salespersonId !== null
            && ! in_array((int) $invoice->customer_id, $context['salesperson_customer_ids'], true)) {
            return false;
        }

        if ($filter->productId !== null
            && ! in_array((int) $invoice->id, $context['product_invoice_ids'], true)) {
            return false;
        }

        if ($filter->categoryId !== null
            && ! in_array((int) $invoice->id, $context['category_invoice_ids'], true)) {
            return false;
        }

        if ($filter->channel !== null && $filter->channel !== ''
            && $this->invoiceChannel($invoice, $context) !== $filter->channel) {
            return false;
        }

        if ($filter->tag !== null && $filter->tag !== '') {
            $byInvoice = in_array((int) $invoice->id, $context['tag_invoice_ids'], true);
            $byOrder = $invoice->sales_order_id !== null
                && in_array((int) $invoice->sales_order_id, $context['tag_sales_order_ids'], true);

            if (! $byInvoice && ! $byOrder) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function posOrderMatches(object $order, ReportFilter $filter, array $context): bool
    {
        if ($filter->customerId !== null && (int) $order->customer_id !== (int) $filter->customerId) {
            return false;
        }

        if ($filter->legalEntityId !== null && (int) $order->legal_entity_id !== (int) $filter->legalEntityId) {
            return false;
        }

        if ($filter->currencyCode !== null && strtoupper((string) $order->currency) !== $filter->currencyCode) {
            return false;
        }

        if ($filter->salespersonId !== null
            && ! in_array((int) $order->customer_id, $context['salesperson_customer_ids'], true)) {
            return false;
        }

        if ($filter->productId !== null
            && ! in_array((int) $order->id, $context['product_pos_order_ids'], true)) {
            return false;
        }

        if ($filter->categoryId !== null
            && ! in_array((int) $order->id, $context['category_pos_order_ids'], true)) {
            return false;
        }

        if ($filter->channel !== null && $filter->channel !== '' && $filter->channel !== 'pos') {
            return false;
        }

        // POS orders cannot carry document tags, so a tag filter excludes
        // them — same rule as the report side.
        if ($filter->tag !== null && $filter->tag !== '') {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function creditNoteMatches(object $note, ReportFilter $filter, array $context): bool
    {
        if ($filter->customerId !== null && (int) $note->customer_id !== (int) $filter->customerId) {
            return false;
        }

        if ($filter->legalEntityId !== null && (int) $note->legal_entity_id !== (int) $filter->legalEntityId) {
            return false;
        }

        if ($filter->currencyCode !== null && strtoupper((string) $note->currency_code) !== $filter->currencyCode) {
            return false;
        }

        if ($filter->salespersonId !== null
            && ! in_array((int) $note->customer_id, $context['salesperson_customer_ids'], true)) {
            return false;
        }

        if ($filter->productId !== null
            && ! in_array((int) $note->id, $context['product_credit_note_ids'], true)) {
            return false;
        }

        if ($filter->categoryId !== null
            && ! in_array((int) $note->id, $context['category_credit_note_ids'], true)) {
            return false;
        }

        $invoice = $context['invoices'][(int) $note->invoice_id] ?? null;

        if ($filter->channel !== null && $filter->channel !== ''
            && ($invoice === null || $this->invoiceChannel($invoice, $context) !== $filter->channel)) {
            return false;
        }

        if ($filter->tag !== null && $filter->tag !== '') {
            $byInvoice = $invoice !== null
                && in_array((int) $invoice->id, $context['tag_invoice_ids'], true);
            $byOrder = $invoice !== null && $invoice->sales_order_id !== null
                && in_array((int) $invoice->sales_order_id, $context['tag_sales_order_ids'], true);

            if (! $byInvoice && ! $byOrder) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function invoiceChannel(object $invoice, array $context): string
    {
        if ($invoice->sales_order_id !== null) {
            $channel = $context['sales_order_channels'][(int) $invoice->sales_order_id] ?? null;
            if ($channel !== null && $channel !== '') {
                return (string) $channel;
            }
        }

        if ($invoice->pos_order_id !== null) {
            return 'pos';
        }

        if ($invoice->web_order_id !== null) {
            return 'ecommerce';
        }

        return 'admin';
    }

    private function manualRevenueMatches(object $row, ReportFilter $filter): bool
    {
        if ($filter->legalEntityId !== null && (int) $row->legal_entity_id !== (int) $filter->legalEntityId) {
            return false;
        }

        return ! $this->hasDocumentDimensionFilter($filter);
    }

    private function hasDocumentDimensionFilter(ReportFilter $filter): bool
    {
        return $filter->customerId !== null
            || $filter->productId !== null
            || $filter->categoryId !== null
            || $filter->salespersonId !== null
            || ($filter->channel !== null && $filter->channel !== '')
            || ($filter->currencyCode !== null && $filter->currencyCode !== '')
            || ($filter->tag !== null && $filter->tag !== '');
    }

    /**
     * Invoices that back report-only booking revenue for the same filters:
     * an SO's invoice exists but was never posted, so the report shows the
     * booking and the GL shows nothing.
     *
     * @return array<int, array<string, mixed>>
     */
    private function unpostedInvoiceDocuments(ReportFilter $filter): array
    {
        [$start, $end] = $this->period($filter);

        $query = DB::table('invoices as i')
            ->join('sales_orders as so', 'so.id', '=', 'i.sales_order_id')
            ->whereNull('i.deleted_at')
            ->where('i.status', '<>', 'cancelled')
            ->whereNotIn('i.lifecycle_status', ['posted', 'credited'])
            ->whereBetween('i.invoice_date', [$start, $end])
            ->whereNotIn('so.status', ['draft', 'cancelled'])
            ->where('so.channel', '<>', 'pos')
            ->where(function ($q): void {
                $q->whereNull('so.accounting_status')->orWhere('so.accounting_status', '<>', 'reversed');
            });

        if ($filter->customerId !== null) {
            $query->where('i.customer_id', $filter->customerId);
        }

        if ($filter->legalEntityId !== null) {
            $query->where('i.legal_entity_id', $filter->legalEntityId);
        }

        if ($filter->currencyCode !== null) {
            $query->where('i.currency_code', $filter->currencyCode);
        }

        if ($filter->salespersonId !== null) {
            $query->whereIn('i.customer_id', function ($sub) use ($filter): void {
                $sub->select('id')->from('customers')->where('salesperson_id', $filter->salespersonId);
            });
        }

        if ($filter->productId !== null) {
            $query->whereExists(function ($sub) use ($filter): void {
                $sub->selectRaw('1')
                    ->from('invoice_lines as il')
                    ->whereColumn('il.invoice_id', 'i.id')
                    ->where('il.product_id', $filter->productId);
            });
        }

        if ($filter->categoryId !== null) {
            $query->whereExists(function ($sub) use ($filter): void {
                $sub->selectRaw('1')
                    ->from('invoice_lines as il')
                    ->join('products as p', 'p.id', '=', 'il.product_id')
                    ->whereColumn('il.invoice_id', 'i.id')
                    ->where('p.category_id', $filter->categoryId);
            });
        }

        if ($filter->channel !== null && $filter->channel !== '') {
            $query->whereRaw(
                "COALESCE(so.channel, CASE WHEN i.pos_order_id IS NOT NULL THEN 'pos'"
                ." WHEN i.web_order_id IS NOT NULL THEN 'ecommerce' ELSE 'admin' END) = ?",
                [$filter->channel],
            );
        }

        if ($filter->tag !== null && $filter->tag !== '') {
            $tagService = app(\App\Services\TagService::class);
            $salesOrderIds = array_map('intval', $tagService->subjectIdsForTagSlugs([$filter->tag], 'sales_order'));
            $invoiceIds = array_map('intval', $tagService->subjectIdsForTagSlugs([$filter->tag], 'invoice'));

            $query->where(function ($q) use ($salesOrderIds, $invoiceIds): void {
                if ($salesOrderIds !== []) {
                    $q->orWhereIn('so.id', $salesOrderIds);
                }
                if ($invoiceIds !== []) {
                    $q->orWhereIn('i.id', $invoiceIds);
                }
                if ($salesOrderIds === [] && $invoiceIds === []) {
                    $q->whereRaw('1 = 0');
                }
            });
        }

        return $query
            ->orderBy('i.id')
            ->limit(50)
            ->get(['i.id', 'i.invoice_number'])
            ->map(fn ($invoice) => [
                'type' => 'invoice',
                'id' => (int) $invoice->id,
                'label' => (string) ($invoice->invoice_number ?? 'Invoice #'.$invoice->id),
                'url' => '/invoices/'.$invoice->id,
            ])
            ->all();
    }
}

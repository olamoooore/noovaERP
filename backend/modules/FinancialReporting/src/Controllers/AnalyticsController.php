<?php

namespace Modules\FinancialReporting\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Analytics\CustomerAnalyticsService;
use Modules\FinancialReporting\Services\Analytics\ExpenseAnalyticsService;
use Modules\FinancialReporting\Services\Analytics\SalesAnalyticsService;
use Modules\FinancialReporting\Services\Analytics\SalesGlReconciliationService;

class AnalyticsController extends Controller
{
    public function __construct(
        protected SalesAnalyticsService $salesAnalyticsService,
        protected ExpenseAnalyticsService $expenseAnalyticsService,
        protected CustomerAnalyticsService $customerAnalyticsService,
        protected SalesGlReconciliationService $salesGlReconciliationService
    ) {}

    /**
     * Query keys the six sales-analytics endpoints accept (#593).
     *
     * A filter the caller believes was applied but silently dropped is worse
     * than an error, so anything outside this set — and outside each
     * endpoint's own extras (top / interval) — is rejected with a 422.
     */
    private const SALES_FILTER_RULES = [
        'start_date' => 'nullable|date',
        'end_date' => 'nullable|date|after_or_equal:start_date',
        'tag' => 'nullable|string|max:120',
        'currency_code' => 'nullable|string|size:3',
        'customer_id' => 'nullable|integer|exists:customers,id',
        'product_id' => 'nullable|integer|exists:products,id',
        'category_id' => 'nullable|integer|exists:product_categories,id',
        'salesperson_id' => 'nullable|integer|exists:users,id',
        'channel' => 'nullable|in:pos,admin,ecommerce,storefront',
        'legal_entity_id' => 'nullable|integer|exists:legal_entities,id',
        // ResolveEntityContext reads entity_id (or X-Entity-Id); keep the
        // query spelling of that context legal on these routes too.
        'entity_id' => 'nullable|integer|exists:legal_entities,id',
    ];

    /**
     * Issue #595: comparison period. Derived modes plus an explicit custom
     * range (the two date params alone imply `custom`). Built in a method
     * because the mode list is composed from the service's shared constants
     * and a class constant cannot call implode().
     *
     * @return array<string, string>
     */
    private static function comparisonRules(): array
    {
        return [
            'comparison' => 'nullable|in:'.implode(',', SalesAnalyticsService::comparisonModes()),
            'compare_start_date' => 'nullable|date',
            'compare_end_date' => 'nullable|date|after_or_equal:compare_start_date',
        ];
    }

    /**
     * Validates a sales report's filters, rejects unknown query keys with a
     * 422 and resolves the legal entity to scope by (#593).
     *
     * The entity is the explicit legal_entity_id when given, otherwise the
     * request's entity context set by ResolveEntityContext (X-Entity-Id
     * header, entity_id param, or the user's default entity) — so a session
     * bound to one entity reads only that entity.
     *
     * Deliberately called OUTSIDE each method's try/catch: ValidationException
     * has to reach Laravel's handler as a 422, not be logged as a 500.
     *
     * @param  array<string, string>  $extraRules
     * @param  bool  $allowComparison  false for endpoints where a comparison
     *                                 window is meaningless (the pivot): the
     *                                 comparison keys then 422 instead of
     *                                 being accepted and silently dropped.
     */
    private function salesFilter(Request $request, array $extraRules = [], bool $allowComparison = true): ReportFilter
    {
        $rules = array_merge(
            self::SALES_FILTER_RULES,
            $allowComparison ? self::comparisonRules() : [],
            $extraRules,
        );

        $unknown = array_diff(array_keys($request->query()), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'filters' => 'Unknown filter(s) for this report: '.implode(', ', $unknown),
            ]);
        }

        $validated = $request->validate($rules);
        $filter = ReportFilter::fromRequest($validated);

        // Issue #595: a custom comparison needs both bounds, and a derived
        // mode must not carry stray explicit dates — reject rather than guess
        // which the caller meant.
        if ($filter->comparisonMode === SalesAnalyticsService::COMPARISON_MODE_CUSTOM
            && ($filter->compareStartDate === null || $filter->compareEndDate === null)) {
            throw ValidationException::withMessages([
                'comparison' => 'A custom comparison requires compare_start_date and compare_end_date.',
            ]);
        }

        if (in_array($filter->comparisonMode, [
            SalesAnalyticsService::COMPARISON_MODE_PREVIOUS_PERIOD,
            SalesAnalyticsService::COMPARISON_MODE_SAME_PERIOD_LAST_YEAR,
        ], true)
            && ($filter->compareStartDate !== null || $filter->compareEndDate !== null)) {
            throw ValidationException::withMessages([
                'comparison' => 'compare_start_date/compare_end_date are only valid with comparison=custom.',
            ]);
        }

        if ($filter->legalEntityId === null) {
            $context = $request->attributes->get('legal_entity_id');
            if ($context === null && isset($validated['entity_id'])) {
                $context = $validated['entity_id'];
            }
            $filter->legalEntityId = $context !== null ? (int) $context : null;
        }

        return $filter;
    }

    /**
     * Issue #595: only add the comparison envelope when one was computed, so
     * the response shape is unchanged for callers that did not ask for it.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $reportData
     * @return array<string, mixed>
     */
    private function withComparisonPayload(array $data, array $reportData): array
    {
        if (isset($reportData['comparison'])) {
            $data['comparison'] = $reportData['comparison'];
        }

        return $data;
    }

    /**
     * Issue #604 (RPT-D1): ties-to-GL reconciliation for the sales reports'
     * footer. Same filter contract as the other sales reports (#593), same
     * period and dimensions on both sides.
     */
    public function salesReconciliation(Request $request): JsonResponse
    {
        $filter = $this->salesFilter($request);

        try {
            return response()->json([
                'success' => true,
                'data' => $this->salesGlReconciliationService->reconcile($filter),
            ]);
        } catch (\Exception $e) {
            \Log::error('Sales reconciliation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics. Please try again.',
            ], 500);
        }
    }

    /**
     * Get sales by product
     */
    public function salesByProduct(Request $request): JsonResponse
    {
        $filter = $this->salesFilter($request, [
            'top' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            // Check if top N products requested
            if ($request->has('top')) {
                $reportData = $this->salesAnalyticsService->getTopSellingProducts($filter, $request->integer('top'));
            } else {
                $reportData = $this->salesAnalyticsService->getSalesByProduct($filter);
            }

            // Transform response to match frontend expectations
            return response()->json([
                'success' => true,
                'data' => $this->withComparisonPayload([
                    'products' => $reportData['data'] ?? [],
                    'pos_excluded_by_tag' => (bool) ($reportData['pos_excluded_by_tag'] ?? false),
                ], $reportData),
            ]);

        } catch (\Exception $e) {
            \Log::error('Sales by Product analytics failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics. Please try again.',
            ], 500);
        }
    }

    /**
     * Get sales by customer
     */
    public function salesByCustomer(Request $request): JsonResponse
    {
        $filter = $this->salesFilter($request);

        try {
            $reportData = $this->salesAnalyticsService->getSalesByCustomer($filter);

            // Transform response to match frontend expectations
            return response()->json([
                'success' => true,
                'data' => $this->withComparisonPayload([
                    'customers' => $reportData['data'] ?? [],
                    'pos_excluded_by_tag' => (bool) ($reportData['pos_excluded_by_tag'] ?? false),
                ], $reportData),
            ]);

        } catch (\Exception $e) {
            \Log::error('Sales by Customer analytics failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics. Please try again.',
            ], 500);
        }
    }

    /**
     * MRD FR-8.1 — sales split by document currency (snapshot-rate base).
     */
    public function salesByCurrency(Request $request): JsonResponse
    {
        $filter = $this->salesFilter($request);

        try {
            $reportData = $this->salesAnalyticsService->getSalesByCurrency($filter);

            return response()->json([
                'success' => true,
                'data' => $this->withComparisonPayload([
                    'currencies' => $reportData['data'] ?? [],
                    'summary' => $reportData['summary'] ?? [],
                    'pos_excluded_by_tag' => (bool) ($reportData['pos_excluded_by_tag'] ?? false),
                ], $reportData),
            ]);
        } catch (\Exception $e) {
            \Log::error('Sales by Currency analytics failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics. Please try again.',
            ], 500);
        }
    }

    /**
     * Get sales trends
     */
    public function salesTrends(Request $request): JsonResponse
    {
        $filter = $this->salesFilter($request, [
            'interval' => 'nullable|in:day,week,month,year',
        ]);

        try {
            $reportData = $this->salesAnalyticsService->getSalesTrends($filter, $filter->interval ?? 'day');

            // Transform response to match frontend expectations
            return response()->json([
                'success' => true,
                'data' => $this->withComparisonPayload([
                    'trends' => $reportData['data'] ?? [],
                    'pos_excluded_by_tag' => (bool) ($reportData['pos_excluded_by_tag'] ?? false),
                ], $reportData),
            ]);

        } catch (\Exception $e) {
            \Log::error('Sales Trends analytics failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics. Please try again.',
            ], 500);
        }
    }

    /**
     * Get sales by category
     */
    public function salesByCategory(Request $request): JsonResponse
    {
        $filter = $this->salesFilter($request);

        try {
            $reportData = $this->salesAnalyticsService->getSalesByCategory($filter);

            // Transform response to match frontend expectations
            return response()->json([
                'success' => true,
                'data' => $this->withComparisonPayload([
                    'categories' => $reportData['data'] ?? [],
                    'pos_excluded_by_tag' => (bool) ($reportData['pos_excluded_by_tag'] ?? false),
                ], $reportData),
            ]);

        } catch (\Exception $e) {
            \Log::error('Sales by Category analytics failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics. Please try again.',
            ], 500);
        }
    }

    /**
     * Issue #601: pivot-style sales analysis.
     *
     * Group rows by up to three whitelisted dimensions (`row_dims[]`), spread
     * columns by up to two more (`col_dims[]` or the singular `col_dim`) and
     * choose up to five measures (`measure[]`, `measures[]` also accepted).
     * The same sales filters as the fixed tabs scope the read model. A
     * comparison window is deliberately not accepted here — a pivot reports
     * one period — so `comparison`/`compare_*` are rejected rather than
     * ignored.
     */
    public function salesPivot(Request $request): JsonResponse
    {
        $filter = $this->salesFilter($request, [
            'row_dims' => 'nullable|array|max:3',
            'row_dims.*' => ['string', Rule::in(SalesAnalyticsService::pivotDimensions())],
            'col_dim' => ['nullable', 'string', Rule::in(SalesAnalyticsService::pivotDimensions())],
            'col_dims' => 'nullable|array|max:2',
            'col_dims.*' => ['string', Rule::in(SalesAnalyticsService::pivotDimensions())],
            'measure' => 'nullable|array|max:5',
            'measure.*' => ['string', Rule::in(SalesAnalyticsService::pivotMeasures())],
            'measures' => 'nullable|array|max:5',
            'measures.*' => ['string', Rule::in(SalesAnalyticsService::pivotMeasures())],
        ], allowComparison: false);

        try {
            $rowDims = array_values((array) $request->input('row_dims', []));
            if ($rowDims === []) {
                $rowDims = ['product'];
            }

            $colDims = array_values(array_filter(array_merge(
                $request->filled('col_dim') ? [(string) $request->input('col_dim')] : [],
                (array) $request->input('col_dims', []),
            )));

            $measures = array_values((array) ($request->input('measure') ?? $request->input('measures') ?? []));
            if ($measures === []) {
                $measures = SalesAnalyticsService::pivotMeasures();
            }

            $reportData = $this->salesAnalyticsService->getSalesPivot($filter, $rowDims, $colDims, $measures);

            return response()->json([
                'success' => true,
                'data' => $reportData,
            ]);

        } catch (\Exception $e) {
            \Log::error('Sales Pivot analytics failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics. Please try again.',
            ], 500);
        }
    }

    /**
     * Get profit margins analysis
     */
    public function profitMargins(Request $request): JsonResponse
    {
        $filter = $this->salesFilter($request);

        try {
            $reportData = $this->salesAnalyticsService->getProfitMargins($filter);

            return response()->json([
                'success' => true,
                'data' => $reportData,
            ]);

        } catch (\Exception $e) {
            \Log::error('Profit Margins analytics failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics. Please try again.',
            ], 500);
        }
    }

    /**
     * Get expenses by category
     */
    public function expensesByCategory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'top' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);

            // Check if top N accounts requested
            if (isset($validated['top'])) {
                $reportData = $this->expenseAnalyticsService->getTopExpenseAccounts($filter, $validated['top']);
            } else {
                $reportData = $this->expenseAnalyticsService->getExpensesByCategory($filter);
            }

            // Transform response to match frontend expectations
            return response()->json([
                'success' => true,
                'data' => [
                    'categories' => $reportData['data'] ?? [],
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('Expenses by Category analytics failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics. Please try again.',
            ], 500);
        }
    }

    /**
     * Get expenses by vendor
     */
    public function expensesByVendor(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);
            $reportData = $this->expenseAnalyticsService->getExpensesByVendor($filter);

            // Transform response to match frontend expectations
            return response()->json([
                'success' => true,
                'data' => [
                    'vendors' => $reportData['data'] ?? [],
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('Expenses by Vendor analytics failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics. Please try again.',
            ], 500);
        }
    }

    /**
     * Get expenses by department
     */
    public function expensesByDepartment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);
            $reportData = $this->expenseAnalyticsService->getExpensesByDepartment($filter);

            // Transform response to match frontend expectations
            return response()->json([
                'success' => true,
                'data' => [
                    'departments' => $reportData['data'] ?? [],
                ],
            ]);

        } catch (\Exception $e) {
            \Log::error('Expenses by Department analytics failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics. Please try again.',
            ], 500);
        }
    }

    /**
     * Get expense trends
     */
    public function expenseTrends(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'interval' => 'nullable|in:day,week,month,year',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);
            $interval = $validated['interval'] ?? 'month';

            $reportData = $this->expenseAnalyticsService->getExpenseTrends($filter, $interval);

            return response()->json([
                'success' => true,
                'data' => $reportData,
            ]);

        } catch (\Exception $e) {
            \Log::error('Expense Trends analytics failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics. Please try again.',
            ], 500);
        }
    }

    /**
     * Get customer lifetime value
     */
    public function customerLifetimeValue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);
            $reportData = $this->customerAnalyticsService->getCustomerLifetimeValue($filter);

            return response()->json([
                'success' => true,
                'data' => $reportData,
            ]);

        } catch (\Exception $e) {
            \Log::error('Customer Lifetime Value analytics failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics. Please try again.',
            ], 500);
        }
    }

    /**
     * Get purchase patterns
     */
    public function purchasePatterns(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);
            $reportData = $this->customerAnalyticsService->getPurchasePatterns($filter);

            return response()->json([
                'success' => true,
                'data' => $reportData,
            ]);

        } catch (\Exception $e) {
            \Log::error('Purchase Patterns analytics failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics. Please try again.',
            ], 500);
        }
    }

    /**
     * Get customer retention
     */
    public function customerRetention(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);
            $reportData = $this->customerAnalyticsService->getCustomerRetention($filter);

            return response()->json([
                'success' => true,
                'data' => $reportData,
            ]);

        } catch (\Exception $e) {
            \Log::error('Customer Retention analytics failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics. Please try again.',
            ], 500);
        }
    }

    /**
     * Get RFM segmentation
     */
    public function rfmSegmentation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);
            $reportData = $this->customerAnalyticsService->getRFMSegmentation($filter);

            return response()->json([
                'success' => true,
                'data' => $reportData,
            ]);

        } catch (\Exception $e) {
            \Log::error('RFM Segmentation analytics failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to generate analytics. Please try again.',
            ], 500);
        }
    }
}

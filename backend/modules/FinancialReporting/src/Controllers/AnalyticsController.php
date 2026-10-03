<?php

namespace Modules\FinancialReporting\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Analytics\CustomerAnalyticsService;
use Modules\FinancialReporting\Services\Analytics\ExpenseAnalyticsService;
use Modules\FinancialReporting\Services\Analytics\SalesAnalyticsService;

class AnalyticsController extends Controller
{
    public function __construct(
        protected SalesAnalyticsService $salesAnalyticsService,
        protected ExpenseAnalyticsService $expenseAnalyticsService,
        protected CustomerAnalyticsService $customerAnalyticsService
    ) {}

    /**
     * Get sales by product
     */
    public function salesByProduct(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'tag' => 'nullable|string|max:120',
            'currency_code' => 'nullable|string|size:3',
            'top' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);

            // Check if top N products requested
            if (isset($validated['top'])) {
                $reportData = $this->salesAnalyticsService->getTopSellingProducts($filter, $validated['top']);
            } else {
                $reportData = $this->salesAnalyticsService->getSalesByProduct($filter);
            }

            // Transform response to match frontend expectations
            return response()->json([
                'success' => true,
                'data' => [
                    'products' => $reportData['data'] ?? [],
                ],
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
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'tag' => 'nullable|string|max:120',
            'currency_code' => 'nullable|string|size:3',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);
            $reportData = $this->salesAnalyticsService->getSalesByCustomer($filter);

            // Transform response to match frontend expectations
            return response()->json([
                'success' => true,
                'data' => [
                    'customers' => $reportData['data'] ?? [],
                ],
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
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'tag' => 'nullable|string|max:120',
            'currency_code' => 'nullable|string|size:3',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);
            $reportData = $this->salesAnalyticsService->getSalesByCurrency($filter);

            return response()->json([
                'success' => true,
                'data' => [
                    'currencies' => $reportData['data'] ?? [],
                    'summary' => $reportData['summary'] ?? [],
                ],
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
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'interval' => 'nullable|in:day,week,month,year',
            'tag' => 'nullable|string|max:120',
            'currency_code' => 'nullable|string|size:3',
            'interval' => 'nullable|in:day,week,month,year',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);
            $interval = $validated['interval'] ?? 'day';

            $reportData = $this->salesAnalyticsService->getSalesTrends($filter, $interval);

            // Transform response to match frontend expectations
            return response()->json([
                'success' => true,
                'data' => [
                    'trends' => $reportData['data'] ?? [],
                ],
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
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'tag' => 'nullable|string|max:120',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);
            $reportData = $this->salesAnalyticsService->getSalesByCategory($filter);

            // Transform response to match frontend expectations
            return response()->json([
                'success' => true,
                'data' => [
                    'categories' => $reportData['data'] ?? [],
                ],
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
     * Get profit margins analysis
     */
    public function profitMargins(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);
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

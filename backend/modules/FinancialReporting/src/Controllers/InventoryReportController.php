<?php

namespace Modules\FinancialReporting\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\Cache\ReportCacheService;
use Modules\FinancialReporting\Services\Inventory\InventoryAgingService;
use Modules\FinancialReporting\Services\Inventory\InventoryTurnoverService;
use Modules\FinancialReporting\Services\Inventory\ReorderService;
use Modules\FinancialReporting\Services\Inventory\StockMovementService;
use Modules\FinancialReporting\Services\Inventory\StockValuationService;

class InventoryReportController extends Controller
{
    public function __construct(
        protected StockValuationService $stockValuationService,
        protected StockMovementService $stockMovementService,
        protected ReorderService $reorderService,
        protected InventoryAgingService $inventoryAgingService,
        protected InventoryTurnoverService $inventoryTurnoverService,
        protected ReportCacheService $cacheService
    ) {}

    /**
     * Generate Stock Valuation report
     */
    public function valuation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of_date' => 'nullable|date',
            'category_id' => 'nullable|integer|exists:categories,id',
            'historical' => 'nullable|boolean',
            'low_stock' => 'nullable|boolean',
        ]);

        try {
            // Check if low stock report is requested
            if ($validated['low_stock'] ?? false) {
                $reportData = $this->stockValuationService->getLowStockProducts();

                return response()->json([
                    'success' => true,
                    'data' => $reportData,
                ]);
            }

            $filter = ReportFilter::fromRequest($validated);

            // Check if historical valuation is requested
            if ($validated['historical'] ?? false) {
                $asOfDate = $filter->endDate ?? now();
                $reportData = $this->stockValuationService->getHistoricalValuation(
                    $asOfDate,
                    $filter->categoryId
                );

                return response()->json([
                    'success' => true,
                    'data' => $reportData,
                ]);
            }

            // Get current stock valuation with caching
            $reportData = $this->cacheService->remember(
                reportType: 'stock-valuation',
                filter: $filter,
                callback: fn () => $this->stockValuationService->generate($filter),
                userId: $request->user()?->id
            );

            // Transform to frontend format
            $response = [
                'items' => collect($reportData->data)->map(function ($item) {
                    return [
                        'product_id' => $item['id'],
                        'product_name' => $item['name'],
                        'sku' => $item['sku'],
                        'category' => $item['category_name'] ?? 'Uncategorized',
                        'quantity' => $item['quantity'],
                        'cost' => $item['cost'],
                        'value' => $item['total_value'],
                        'location' => null,
                    ];
                })->toArray(),
                'total_value' => $reportData->summary['total_value'] ?? 0,
                'by_category' => collect($reportData->metadata['by_category'] ?? [])->mapWithKeys(function ($cat) {
                    return [$cat['category'] => $cat['total_value']];
                })->toArray(),
            ];

            return response()->json([
                'success' => true,
                'data' => $response,
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Stock Valuation report generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to generate report. Please try again.',
            ], 500);
        }
    }

    /**
     * Generate Stock Movement report
     */
    public function movement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => 'nullable|integer|exists:products,id',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'summary_by_type' => 'nullable|boolean',
            'most_active' => 'nullable|boolean',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);

            // Check if summary by type is requested
            if ($validated['summary_by_type'] ?? false) {
                $reportData = $this->stockMovementService->getMovementSummaryByType($filter);

                return response()->json([
                    'success' => true,
                    'data' => $reportData,
                ]);
            }

            // Check if most active products report is requested
            if ($validated['most_active'] ?? false) {
                $startDate = $filter->startDate ?? now()->subDays(30);
                $endDate = $filter->endDate ?? now();
                $reportData = $this->stockMovementService->getMostActiveProducts($startDate, $endDate);

                return response()->json([
                    'success' => true,
                    'data' => $reportData,
                ]);
            }

            // Get detailed stock movements
            $reportData = $this->stockMovementService->generate($filter);
            $reportArray = $reportData->toArray();

            // Transform to frontend expected format
            return response()->json([
                'success' => true,
                'data' => [
                    'movements' => $reportArray['data'],
                    'summary' => $reportArray['summary'],
                    'pagination' => $reportArray['metadata']['pagination'] ?? null,
                ],
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Stock Movement report generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to generate report. Please try again.',
            ], 500);
        }
    }

    /**
     * Generate Reorder Report
     */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category_id' => 'nullable|integer|exists:categories,id',
            'grouped' => 'nullable|boolean',
            'critical_only' => 'nullable|boolean',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);

            // Check if critical items only is requested
            if ($validated['critical_only'] ?? false) {
                $reportData = $this->reorderService->getCriticalItems($filter);

                return response()->json([
                    'success' => true,
                    'data' => $reportData->toArray(),
                ]);
            }

            // Check if grouped view is requested
            if ($validated['grouped'] ?? false) {
                $reportData = $this->reorderService->getGroupedByCategory($filter);

                return response()->json([
                    'success' => true,
                    'data' => $reportData,
                ]);
            }

            // Get standard reorder report with caching
            $reportData = $this->cacheService->remember(
                reportType: 'reorder',
                filter: $filter,
                callback: fn () => $this->reorderService->generate($filter),
                userId: $request->user()?->id
            );

            return response()->json([
                'success' => true,
                'data' => $reportData->toArray(),
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Reorder report generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to generate report. Please try again.',
            ], 500);
        }
    }

    /**
     * Generate Inventory Aging Report
     */
    public function aging(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'as_of_date' => 'nullable|date',
            'category_id' => 'nullable|integer|exists:categories,id',
            'slow_moving_only' => 'nullable|boolean',
            'age_bucket' => 'nullable|in:0-30,31-60,61-90,90+',
            'older_than_days' => 'nullable|integer|min:1',
        ]);

        try {
            $filter = ReportFilter::fromRequest($validated);

            // Check if slow-moving items only is requested
            if ($validated['slow_moving_only'] ?? false) {
                $reportData = $this->inventoryAgingService->getSlowMovingItems($filter);

                return response()->json([
                    'success' => true,
                    'data' => $reportData->toArray(),
                ]);
            }

            // Check if specific age bucket is requested
            if (isset($validated['age_bucket'])) {
                $reportData = $this->inventoryAgingService->getByAgeBucket(
                    $validated['age_bucket'],
                    $filter
                );

                return response()->json([
                    'success' => true,
                    'data' => $reportData->toArray(),
                ]);
            }

            // Check if items older than X days is requested
            if (isset($validated['older_than_days'])) {
                $reportData = $this->inventoryAgingService->getItemsOlderThan(
                    $validated['older_than_days'],
                    $filter
                );

                return response()->json([
                    'success' => true,
                    'data' => $reportData,
                ]);
            }

            // Get standard aging report with caching
            $reportData = $this->cacheService->remember(
                reportType: 'inventory-aging',
                filter: $filter,
                callback: fn () => $this->inventoryAgingService->generate($filter),
                userId: $request->user()?->id
            );

            // Transform to frontend format
            $response = [
                'items' => collect($reportData->data)->map(function ($item) {
                    return [
                        'product_id' => $item['id'],
                        'product_name' => $item['name'],
                        'sku' => $item['sku'],
                        'quantity' => $item['stock_quantity'],
                        'age_days' => $item['age_in_days'],
                        'age_bucket' => $item['age_bucket'],
                        'value' => $item['inventory_value'],
                        'carrying_cost' => $item['carrying_cost'],
                    ];
                })->toArray(),
                'by_age_bucket' => collect($reportData->summary['by_age_bucket'] ?? [])->mapWithKeys(function ($bucket) {
                    return [$bucket['bucket'] => [
                        'quantity' => $bucket['total_quantity'],
                        'value' => $bucket['total_value'],
                    ]];
                })->toArray(),
                'total_carrying_cost' => $reportData->summary['total_carrying_cost'] ?? 0,
            ];

            return response()->json([
                'success' => true,
                'data' => $response,
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Inventory Aging report generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to generate report. Please try again.',
            ], 500);
        }
    }

    /**
     * Generate Inventory Turnover Report
     */
    public function turnover(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'category_id' => 'nullable|integer|exists:categories,id',
            'fast_movers_only' => 'nullable|boolean',
            'slow_movers_only' => 'nullable|boolean',
            'classification' => 'nullable|in:Fast,Medium,Slow,No Movement',
            'by_category' => 'nullable|boolean',
        ]);

        try {
            $startDate = \Carbon\Carbon::parse($validated['start_date']);
            $endDate = \Carbon\Carbon::parse($validated['end_date']);
            $categoryId = $validated['category_id'] ?? null;

            // Check if by category view is requested
            if ($validated['by_category'] ?? false) {
                $reportData = $this->inventoryTurnoverService->getByCategory($startDate, $endDate);

                return response()->json([
                    'success' => true,
                    'data' => $reportData,
                ]);
            }

            // Check if fast movers only is requested
            if ($validated['fast_movers_only'] ?? false) {
                $reportData = $this->inventoryTurnoverService->getFastMovers($startDate, $endDate, $categoryId);

                return response()->json([
                    'success' => true,
                    'data' => $reportData,
                ]);
            }

            // Check if slow movers only is requested
            if ($validated['slow_movers_only'] ?? false) {
                $reportData = $this->inventoryTurnoverService->getSlowMovers($startDate, $endDate, $categoryId);

                return response()->json([
                    'success' => true,
                    'data' => $reportData,
                ]);
            }

            // Check if specific classification is requested
            if (isset($validated['classification'])) {
                $reportData = $this->inventoryTurnoverService->getByClassification(
                    $validated['classification'],
                    $startDate,
                    $endDate,
                    $categoryId
                );

                return response()->json([
                    'success' => true,
                    'data' => $reportData,
                ]);
            }

            // Get standard turnover report
            $reportData = $this->inventoryTurnoverService->generate($startDate, $endDate, $categoryId);

            // Transform to frontend format
            $response = [
                'items' => collect($reportData['data'] ?? [])->map(function ($item) {
                    return [
                        'product_id' => $item['id'],
                        'product_name' => $item['name'],
                        'category' => $item['category'] ?? 'Uncategorized',
                        'turnover_ratio' => $item['turnover_ratio'],
                        'days_in_inventory' => $item['days_in_inventory'],
                        'classification' => $item['classification'],
                    ];
                })->toArray(),
                'average_turnover' => $reportData['summary']['average_turnover_ratio'] ?? 0,
                'average_days' => $reportData['summary']['average_days_in_inventory'] ?? 0,
            ];

            return response()->json([
                'success' => true,
                'data' => $response,
            ]);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (\Exception $e) {
            \Log::error('Inventory Turnover report generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to generate report. Please try again.',
            ], 500);
        }
    }
}

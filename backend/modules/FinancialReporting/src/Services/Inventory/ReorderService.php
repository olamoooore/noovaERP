<?php

namespace Modules\FinancialReporting\Services\Inventory;

use Illuminate\Support\Facades\DB;
use Modules\FinancialReporting\DTOs\ReportData;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\BaseReportService;

class ReorderService extends BaseReportService
{
    /**
     * Generate Reorder Report
     *
     * Identifies products that are below their reorder level
     * and calculates suggested order quantities
     */
    public function generate(ReportFilter $filter): ReportData
    {
        // Get products that need reordering
        $reorderProducts = $this->getProductsBelowReorderLevel($filter);

        // Calculate totals
        $totalProducts = $reorderProducts->count();
        $totalSuggestedOrderValue = $reorderProducts->sum('suggested_order_value');
        $criticalCount = $reorderProducts->where('is_critical', true)->count();

        return new ReportData(
            title: 'Reorder Report',
            data: $reorderProducts->toArray(),
            summary: [
                'total_products_to_reorder' => $totalProducts,
                'critical_items' => $criticalCount,
                'total_suggested_order_value' => round($totalSuggestedOrderValue, 2),
            ],
            metadata: [
                'generated_at' => now()->toIso8601String(),
                'report_date' => now()->format('Y-m-d'),
            ]
        );
    }

    /**
     * Get products that are below their reorder level
     */
    protected function getProductsBelowReorderLevel(ReportFilter $filter)
    {
        // Check if categories table exists
        $categoriesExists = DB::select("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'categories')")[0]->exists;
        $stockLevelsExists = DB::getSchemaBuilder()->hasTable('stock_levels');

        // Threshold resolution matches the Stock Management low-stock rule:
        // product reorder_level when set, else the stock-level min_quantity,
        // else the platform default of 10. The previous version required
        // `reorder_level IS NOT NULL` and compared against it only, so
        // tenants that never set a reorder level got an empty report while
        // Stock Management correctly showed low/out-of-stock items.
        $defaultThreshold = 10;

        $query = DB::table('products as p')
            ->where('p.is_active', true)
            ->where('p.track_inventory', true)
            ->where('p.deleted_at', null);

        if ($stockLevelsExists) {
            $query->leftJoin(
                DB::raw('(SELECT product_id, SUM(quantity) as total_quantity, MAX(min_quantity) as max_min_quantity FROM stock_levels GROUP BY product_id) as sl'),
                'sl.product_id',
                '=',
                'p.id'
            );
            $currentStockSelect = DB::raw('COALESCE(sl.total_quantity, 0) as current_stock');
            $thresholdSelect = DB::raw('COALESCE(NULLIF(p.reorder_level, 0), NULLIF(sl.max_min_quantity, 0), '.$defaultThreshold.') as effective_threshold');
            // Include zero-stock products: an out-of-stock item is the most
            // urgent reorder case, not an "all good" one.
            $query->whereRaw('COALESCE(sl.total_quantity, 0) <= COALESCE(NULLIF(p.reorder_level, 0), NULLIF(sl.max_min_quantity, 0), '.$defaultThreshold.')');
            $query->orderByRaw('COALESCE(sl.total_quantity, 0) asc');
        } else {
            $currentStockSelect = 'p.stock_quantity as current_stock';
            $thresholdSelect = DB::raw('COALESCE(NULLIF(p.reorder_level, 0), '.$defaultThreshold.') as effective_threshold');
            $query->whereRaw('p.stock_quantity <= COALESCE(NULLIF(p.reorder_level, 0), '.$defaultThreshold.')');
            $query->orderBy('p.stock_quantity', 'asc');
        }

        // Only join categories if the table exists
        if ($categoriesExists) {
            $query->leftJoin('categories as c', 'c.id', '=', 'p.category_id');
            $categorySelect = 'c.name as category_name';
        } else {
            $categorySelect = DB::raw('NULL as category_name');
        }

        // Apply category filter if provided and table exists
        if ($filter->categoryId && $categoriesExists) {
            $query->where('p.category_id', $filter->categoryId);
        }

        // #1020: product filter uses the shared server-side product picker.
        if ($filter->productId) {
            $query->where('p.id', $filter->productId);
        }

        $products = $query->select([
            'p.id',
            'p.sku',
            'p.name',
            'p.short_description',
            $currentStockSelect,
            $thresholdSelect,
            'p.reorder_level',
            'p.cost as unit_cost',
            'p.price as unit_price',
            $categorySelect,
            'p.unit',
        ])
            ->get();

        // Calculate suggested order quantities and additional metrics
        return $products->map(function ($product) {
            // Resolved threshold: product reorder level, else the stock-level
            // minimum, else the platform default. (`??` would keep a stored 0,
            // so the <= 0 guard runs after.)
            $threshold = (float) ($product->effective_threshold ?? $product->reorder_level ?? 10);
            if ($threshold <= 0) {
                $threshold = 10;
            }

            // Calculate how much below the threshold
            $shortage = max(0, $threshold - $product->current_stock);

            // Suggested order quantity (threshold + 50% buffer)
            $suggestedQuantity = $threshold + ceil($threshold * 0.5);

            // Calculate order value
            $suggestedOrderValue = $suggestedQuantity * $product->unit_cost;

            // Determine if critical (out of stock or very low)
            $isCritical = $product->current_stock <= 0 ||
                         $product->current_stock < ($threshold * 0.3);

            // Calculate days until stockout (simple estimation)
            // This would ideally use historical sales data
            $daysUntilStockout = $this->estimateDaysUntilStockout($product->id, $product->current_stock);

            // Get last purchase information
            $lastPurchase = $this->getLastPurchaseInfo($product->id);

            $product->effective_threshold = $threshold;
            $product->shortage = $shortage;
            $product->suggested_order_quantity = $suggestedQuantity;
            $product->suggested_order_value = round($suggestedOrderValue, 2);
            $product->is_critical = $isCritical;
            $product->priority = $isCritical ? 'HIGH' : 'MEDIUM';
            $product->days_until_stockout = $daysUntilStockout;
            $product->last_purchase_date = $lastPurchase['date'] ?? null;
            $product->last_purchase_price = $lastPurchase['price'] ?? $product->unit_cost;
            $product->last_supplier = $lastPurchase['supplier'] ?? null;

            // Round numeric values
            $product->unit_cost = round($product->unit_cost, 2);
            $product->unit_price = round($product->unit_price, 2);
            $product->last_purchase_price = round($product->last_purchase_price, 2);

            return $product;
        });
    }

    /**
     * Estimate days until stockout based on recent sales velocity
     */
    protected function estimateDaysUntilStockout(int $productId, int $currentStock): ?int
    {
        if ($currentStock <= 0) {
            return 0;
        }

        try {
            // Check if invoice_items table exists
            $tableExists = DB::select("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'invoice_items')")[0]->exists;

            if (! $tableExists) {
                return null;
            }

            // Get sales velocity from last 30 days
            $salesVelocity = DB::table('invoice_items as ii')
                ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
                ->where('ii.product_id', $productId)
                ->where('i.invoice_date', '>=', now()->subDays(30))
                ->where('i.status', '!=', 'cancelled')
                ->sum('ii.quantity');

            if ($salesVelocity <= 0) {
                return null; // No recent sales data
            }

            // Calculate daily average
            $dailyAverage = $salesVelocity / 30;

            if ($dailyAverage <= 0) {
                return null;
            }

            // Estimate days until stockout
            $daysUntilStockout = ceil($currentStock / $dailyAverage);

            return $daysUntilStockout;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get last purchase information for a product
     */
    protected function getLastPurchaseInfo(int $productId): array
    {
        // Last purchase order that included this product (#1020). The previous
        // query selected `invoice_items.price` and `invoices.type`, neither of
        // which exist — it failed on every call and a blanket catch hid the
        // error, so "last supplier" was silently always null.
        $lastPurchase = DB::table('purchase_order_items as poi')
            ->join('purchase_orders as po', 'po.id', '=', 'poi.purchase_order_id')
            ->leftJoin('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->where('poi.product_id', $productId)
            ->orderByDesc('po.order_date')
            ->orderByDesc('po.id')
            ->select([
                'po.order_date as date',
                's.name as supplier',
            ])
            ->first();

        if ($lastPurchase) {
            return [
                'date' => $lastPurchase->date,
                'supplier' => $lastPurchase->supplier,
            ];
        }

        return [];
    }

    /**
     * Get reorder report grouped by category
     */
    public function getGroupedByCategory(ReportFilter $filter): array
    {
        $products = $this->getProductsBelowReorderLevel($filter);

        $grouped = $products->groupBy('category_name')->map(function ($categoryProducts, $categoryName) {
            return [
                'category_name' => $categoryName ?? 'Uncategorized',
                'products' => $categoryProducts->values()->toArray(),
                'total_products' => $categoryProducts->count(),
                'total_order_value' => round($categoryProducts->sum('suggested_order_value'), 2),
                'critical_items' => $categoryProducts->where('is_critical', true)->count(),
            ];
        });

        return [
            'groups' => $grouped->values()->toArray(),
            'total_products' => $products->count(),
            'total_order_value' => round($products->sum('suggested_order_value'), 2),
            'total_critical_items' => $products->where('is_critical', true)->count(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Get critical items only (out of stock or very low)
     */
    public function getCriticalItems(ReportFilter $filter): ReportData
    {
        $allProducts = $this->getProductsBelowReorderLevel($filter);
        $criticalProducts = $allProducts->where('is_critical', true)->values();

        return new ReportData(
            title: 'Critical Reorder Items',
            data: $criticalProducts->toArray(),
            summary: [
                'total_critical_items' => $criticalProducts->count(),
                'total_suggested_order_value' => round($criticalProducts->sum('suggested_order_value'), 2),
                'out_of_stock_count' => $criticalProducts->where('current_stock', '<=', 0)->count(),
            ],
            metadata: [
                'generated_at' => now()->toIso8601String(),
                'report_date' => now()->format('Y-m-d'),
                'priority' => 'HIGH',
            ]
        );
    }
}

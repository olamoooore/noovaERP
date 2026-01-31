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

        $query = DB::table('products as p')
            ->where('p.is_active', true)
            ->where('p.track_inventory', true)
            ->where('p.deleted_at', null)
            ->whereNotNull('p.reorder_level')
            ->whereRaw('p.stock_quantity <= p.reorder_level');

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

        $products = $query->select([
                'p.id',
                'p.sku',
                'p.name',
                'p.stock_quantity as current_stock',
                'p.reorder_level',
                'p.cost as unit_cost',
                'p.price as unit_price',
                $categorySelect,
                'p.unit',
            ])
            ->orderBy('p.stock_quantity', 'asc')
            ->get();

        // Calculate suggested order quantities and additional metrics
        return $products->map(function ($product) {
            // Calculate how much below reorder level
            $shortage = max(0, $product->reorder_level - $product->current_stock);
            
            // Suggested order quantity (reorder level + 50% buffer)
            $suggestedQuantity = $product->reorder_level + ceil($product->reorder_level * 0.5);
            
            // Calculate order value
            $suggestedOrderValue = $suggestedQuantity * $product->unit_cost;
            
            // Determine if critical (out of stock or very low)
            $isCritical = $product->current_stock <= 0 || 
                         $product->current_stock < ($product->reorder_level * 0.3);
            
            // Calculate days until stockout (simple estimation)
            // This would ideally use historical sales data
            $daysUntilStockout = $this->estimateDaysUntilStockout($product->id, $product->current_stock);
            
            // Get last purchase information
            $lastPurchase = $this->getLastPurchaseInfo($product->id);

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
            
            if (!$tableExists) {
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
        try {
            // Check if invoice_items table exists
            $tableExists = DB::select("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'invoice_items')")[0]->exists;
            
            if (!$tableExists) {
                return [];
            }

            // This would typically query purchase orders or bills
            // For now, we'll return a placeholder structure
            // You would need to implement this based on your purchase order system
            
            $lastPurchase = DB::table('invoice_items as ii')
                ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
                ->leftJoin('customers as c', 'c.id', '=', 'i.customer_id')
                ->where('ii.product_id', $productId)
                ->where('i.type', 'purchase') // Assuming invoices table has a type field
                ->orderBy('i.invoice_date', 'desc')
                ->select([
                    'i.invoice_date as date',
                    'ii.price as price',
                    'c.name as supplier'
                ])
                ->first();

            if ($lastPurchase) {
                return [
                    'date' => $lastPurchase->date,
                    'price' => $lastPurchase->price,
                    'supplier' => $lastPurchase->supplier,
                ];
            }

            return [];
        } catch (\Exception $e) {
            return [];
        }
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

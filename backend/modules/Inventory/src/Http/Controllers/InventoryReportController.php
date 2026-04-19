<?php

namespace Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\StockLevel;
use Modules\Inventory\Services\InventoryService;

class InventoryReportController extends Controller
{
    public function __construct(
        private InventoryService $inventoryService
    ) {}

    /**
     * Inventory Dashboard Summary
     */
    public function dashboard()
    {
        $totalProducts   = Product::where('is_active', true)->where('track_inventory', true)->count();
        $totalWarehouses = Warehouse::where('is_active', true)->count();

        // Stock values sourced from stock_levels (the operational source of truth).
        // Joining to products to get unit cost/price for valuation.
        $stockLevels = StockLevel::with(['product:id,cost,price'])->get();

        $totalStockValue  = $stockLevels->sum(fn($sl) => $sl->quantity * ($sl->product->cost ?? 0));
        $totalRetailValue = $stockLevels->sum(fn($sl) => $sl->quantity * ($sl->product->price ?? 0));

        // Low/out-of-stock counts from stock_levels
        $lowStockCount = StockLevel::where('quantity', '>', 0)
            ->where(function ($query) {
                $query->whereRaw('quantity <= NULLIF(min_quantity, 0)')
                    ->orWhere(function ($fallback) {
                        $fallback->whereRaw('(min_quantity IS NULL OR min_quantity = 0)')
                            ->where('quantity', '<=', 10);
                    });
            })
            ->whereHas('product', fn($q) => $q->where('is_active', true)->where('track_inventory', true))
            ->distinct('product_id')
            ->count('product_id');

        $outOfStockCount = Product::where('is_active', true)
            ->where('track_inventory', true)
            ->whereDoesntHave('stockLevels', fn($q) => $q->where('quantity', '>', 0))
            ->count();

        // Recent movements
        $recentMovements = StockMovement::with(['product', 'createdBy'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Movement summary for last 30 days
        $thirtyDaysAgo = now()->subDays(30);
        $movementSummary = StockMovement::where('created_at', '>=', $thirtyDaysAgo)
            ->select('type', DB::raw('COUNT(*) as count'), DB::raw('SUM(quantity) as total_quantity'))
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        return response()->json([
            'summary' => [
                'total_products' => $totalProducts,
                'total_warehouses' => $totalWarehouses,
                'total_stock_value' => round($totalStockValue, 2),
                'total_retail_value' => round($totalRetailValue, 2),
                'potential_profit' => round($totalRetailValue - $totalStockValue, 2),
                'low_stock_count' => $lowStockCount,
                'out_of_stock_count' => $outOfStockCount,
            ],
            'recent_movements' => $recentMovements,
            'movement_summary' => $movementSummary,
        ]);
    }

    /**
     * Inventory Valuation Report
     */
    public function valuation(Request $request)
    {
        $warehouseId = $request->input('warehouse_id');
        return response()->json($this->inventoryService->getInventoryValuation($warehouseId));
    }

    /**
     * Low Stock Report
     */
    public function lowStockReport(Request $request)
    {
        $query = StockLevel::with(['product', 'warehouse'])
            ->where('quantity', '>', 0)
            ->where(function ($query) {
                $query->whereColumn('quantity', '<=', 'min_quantity')
                    ->orWhere(function ($fallback) {
                        $fallback->where(function ($nullOrZero) {
                            $nullOrZero->whereNull('min_quantity')
                                ->orWhere('min_quantity', 0);
                        })->where('quantity', '<=', 10);
                    });
            });

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 50)));

        return response()->json($query->orderBy('quantity')->paginate($perPage));
    }

    /**
     * Out of Stock Report
     */
    public function outOfStockReport(Request $request)
    {
        $query = StockLevel::with(['product', 'warehouse'])
            ->where('quantity', '<=', 0);

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 50)));

        return response()->json($query->paginate($perPage));
    }

    /**
     * Warehouse Stock Report
     */
    public function warehouseReport(Request $request, int $warehouseId)
    {
        $warehouse = Warehouse::findOrFail($warehouseId);

        $query = StockLevel::with('product')
            ->where('warehouse_id', $warehouseId);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->whereHas('product', fn($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
        }

        if ($request->filled('category_id')) {
            $query->whereHas('product', fn($q) => $q->where('category_id', $request->input('category_id')));
        }

        $stockLevels = $query->get();

        $summary = [
            'warehouse' => $warehouse,
            'total_products' => $stockLevels->count(),
            'total_quantity' => $stockLevels->sum('quantity'),
            'total_cost_value' => $stockLevels->sum(fn($sl) => $sl->quantity * ($sl->product->cost ?? 0)),
            'total_retail_value' => $stockLevels->sum(fn($sl) => $sl->quantity * ($sl->product->price ?? 0)),
            'low_stock_count' => $stockLevels->filter(fn($sl) => $sl->isLowStock())->count(),
            'out_of_stock_count' => $stockLevels->filter(fn($sl) => $sl->isOutOfStock())->count(),
        ];

        $perPage = min(100, max(1, (int) $request->input('per_page', 50)));

        return response()->json([
            'summary' => $summary,
            'items' => $query->paginate($perPage),
        ]);
    }

    /**
     * Movement History Report
     */
    public function movementReport(Request $request)
    {
        $query = StockMovement::with(['product', 'createdBy'])
            ->orderBy('created_at', 'desc');

        if ($request->filled('product_id')) {
            $query->where('product_id', $request->input('product_id'));
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->input('warehouse_id'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->input('type'));
        }

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->input('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->input('to_date'));
        }

        // Summary
        $summaryQuery = clone $query;
        $summary = [
            'total_movements' => $summaryQuery->count(),
            'by_type' => $summaryQuery->select('type', DB::raw('COUNT(*) as count'), DB::raw('SUM(quantity) as total_quantity'))
                ->groupBy('type')
                ->get(),
        ];

        $perPage = min(100, max(1, (int) $request->input('per_page', 50)));

        return response()->json([
            'summary' => $summary,
            'movements' => $query->paginate($perPage),
        ]);
    }

    /**
     * Stock Ageing Report
     */
    public function ageingReport(Request $request)
    {
        $warehouseId = $request->input('warehouse_id');
        $daysThreshold = (int) $request->input('days', 90);

        $query = StockLevel::with(['product', 'warehouse'])
            ->where('quantity', '>', 0);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        // Get products with their last movement date
        $stockLevels = $query->get()->map(function ($sl) use ($daysThreshold) {
            $lastMovement = StockMovement::where('product_id', $sl->product_id)
                ->where('warehouse_id', $sl->warehouse_id)
                ->where('type', 'in')
                ->orderBy('created_at', 'desc')
                ->first();

            $daysSinceLastIn = $lastMovement 
                ? now()->diffInDays($lastMovement->created_at) 
                : 999;

            $ageCategory = match(true) {
                $daysSinceLastIn <= 30 => '0-30 days',
                $daysSinceLastIn <= 60 => '31-60 days',
                $daysSinceLastIn <= 90 => '61-90 days',
                $daysSinceLastIn <= 180 => '91-180 days',
                default => '180+ days',
            };

            return [
                'product_id' => $sl->product_id,
                'product_name' => $sl->product->name,
                'sku' => $sl->product->sku,
                'warehouse_id' => $sl->warehouse_id,
                'warehouse_name' => $sl->warehouse->name,
                'quantity' => $sl->quantity,
                'cost_value' => $sl->quantity * ($sl->product->cost ?? 0),
                'days_since_last_in' => $daysSinceLastIn,
                'age_category' => $ageCategory,
                'last_movement_date' => $lastMovement?->created_at,
            ];
        });

        // Group by age category
        $byCategory = $stockLevels->groupBy('age_category')->map(fn($items) => [
            'count' => $items->count(),
            'total_quantity' => $items->sum('quantity'),
            'total_value' => $items->sum('cost_value'),
        ]);

        return response()->json([
            'summary' => $byCategory,
            'items' => $stockLevels->filter(fn($item) => $item['days_since_last_in'] >= $daysThreshold)->values(),
        ]);
    }
}

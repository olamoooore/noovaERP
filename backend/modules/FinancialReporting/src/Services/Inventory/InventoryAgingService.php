<?php

namespace Modules\FinancialReporting\Services\Inventory;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\FinancialReporting\DTOs\ReportData;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\BaseReportService;

class InventoryAgingService extends BaseReportService
{
    /**
     * Age buckets in days
     */
    protected array $ageBuckets = [
        '0-30' => ['min' => 0, 'max' => 30],
        '31-60' => ['min' => 31, 'max' => 60],
        '61-90' => ['min' => 61, 'max' => 90],
        '90+' => ['min' => 91, 'max' => PHP_INT_MAX],
    ];

    /**
     * Generate Inventory Aging Report
     *
     * Analyzes how long inventory items have been in stock
     * and identifies slow-moving items
     */
    public function generate(ReportFilter $filter): ReportData
    {
        $asOfDate = $filter->endDate ?? now();

        // Get aged inventory items
        $agedInventory = $this->getAgedInventory($asOfDate, $filter);

        // Calculate summary statistics
        $summary = $this->calculateSummary($agedInventory);

        // Group by age buckets
        $byAgeBucket = $this->groupByAgeBucket($agedInventory);

        return new ReportData(
            title: 'Inventory Aging Report',
            data: $agedInventory->toArray(),
            summary: array_merge($summary, [
                'by_age_bucket' => $byAgeBucket,
            ]),
            metadata: [
                'as_of_date' => $asOfDate->format('Y-m-d'),
                'generated_at' => now()->toIso8601String(),
                'total_items' => $agedInventory->count(),
            ]
        );
    }

    /**
     * Get aged inventory with age calculations
     */
    protected function getAgedInventory(Carbon $asOfDate, ReportFilter $filter)
    {
        // Check if stock_movements table exists
        $stockMovementsExists = DB::select("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'stock_movements')")[0]->exists;

        // Check if categories table exists
        $categoriesExists = DB::select("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'categories')")[0]->exists;

        $query = DB::table('products as p')
            ->where('p.is_active', true)
            ->where('p.track_inventory', true)
            ->where('p.deleted_at', null);

        // Join categories if table exists
        if ($categoriesExists) {
            $query->leftJoin('categories as c', 'c.id', '=', 'p.category_id');
            $categorySelect = 'c.name as category_name';
        } else {
            $categorySelect = DB::raw('NULL as category_name');
        }

        // Apply category filter if provided
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
            'p.cost as unit_cost',
            'p.price as unit_price',
            $categorySelect,
            'p.created_at',
        ])
            ->get();

        // Calculate age and additional metrics for each product
        $agedInventory = collect();

        foreach ($products as $product) {
            // Get current quantity from stock movements
            $quantity = $this->getQuantityAsOfDate($product->id, $asOfDate, $stockMovementsExists);

            // Only include products with stock
            if ($quantity > 0) {
                // Get the date of the oldest stock still on hand
                $oldestStockDate = $this->getOldestStockDate($product->id, $stockMovementsExists);

                // If we can't determine oldest stock date, use product creation date
                $referenceDate = $oldestStockDate ?? Carbon::parse($product->created_at);

                // Calculate age in days
                $ageInDays = $referenceDate->diffInDays($asOfDate);

                // Determine age bucket
                $ageBucket = $this->determineAgeBucket($ageInDays);

                // Calculate inventory value
                $inventoryValue = $quantity * $product->unit_cost;

                // Calculate carrying cost (estimated at 25% annual rate)
                $carryingCostRate = 0.25; // 25% per year
                $carryingCost = ($inventoryValue * $carryingCostRate * $ageInDays) / 365;

                // Get sales velocity
                $salesVelocity = $this->getSalesVelocity($product->id);

                // Determine if slow-moving (less than 1 sale per month on average)
                $isSlowMoving = $salesVelocity < (1 / 30); // Less than 1 per month

                // Calculate turnover rate
                $turnoverRate = $salesVelocity > 0 ?
                    ($quantity / $salesVelocity) : null;

                $agedInventory->push([
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'short_description' => $product->short_description,
                    'category_name' => $product->category_name,
                    'stock_quantity' => $quantity,
                    'age_in_days' => $ageInDays,
                    'age_bucket' => $ageBucket,
                    'oldest_stock_date' => $referenceDate->format('Y-m-d'),
                    'inventory_value' => round($inventoryValue, 2),
                    'carrying_cost' => round($carryingCost, 2),
                    'sales_velocity_per_day' => round($salesVelocity, 2),
                    'is_slow_moving' => $isSlowMoving,
                    'days_to_sell' => $turnoverRate ? round($turnoverRate, 0) : null,
                    'unit_cost' => round($product->unit_cost, 2),
                    'unit_price' => round($product->unit_price, 2),
                ]);
            }
        }

        return $agedInventory->sortByDesc('age_in_days')->values();
    }

    /**
     * Get current quantity as of date
     */
    protected function getQuantityAsOfDate(int $productId, Carbon $asOfDate, bool $stockMovementsExists): int
    {
        if (
            DB::getSchemaBuilder()->hasTable('stock_levels')
            && $asOfDate->copy()->startOfDay()->equalTo(now()->startOfDay())
        ) {
            return (int) DB::table('stock_levels')
                ->where('product_id', $productId)
                ->sum('quantity');
        }

        if (! $stockMovementsExists) {
            // Fallback to products table
            $product = DB::table('products')
                ->where('id', $productId)
                ->first(['stock_quantity']);

            return $product ? $product->stock_quantity : 0;
        }

        // Get the last stock movement before or on the date
        $lastMovement = DB::table('stock_movements')
            ->where('product_id', $productId)
            ->where('created_at', '<=', $asOfDate)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return $lastMovement ? $lastMovement->balance_after : 0;
    }

    /**
     * Get the date of the oldest stock still on hand
     */
    protected function getOldestStockDate(int $productId, bool $stockMovementsExists): ?Carbon
    {
        if (! $stockMovementsExists) {
            return null;
        }

        try {
            // Get the oldest stock-in movement that hasn't been fully sold
            $oldestMovement = DB::table('stock_movements')
                ->where('product_id', $productId)
                ->whereIn('type', ['in', 'purchase', 'return', 'transfer_in', 'adjustment_in', 'initial'])
                ->orderBy('created_at', 'asc')
                ->first();

            if ($oldestMovement) {
                return Carbon::parse($oldestMovement->created_at);
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get sales velocity (average daily sales)
     */
    protected function getSalesVelocity(int $productId): float
    {
        try {
            // Check if invoice_items table exists
            $tableExists = DB::select("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_name = 'invoice_items')")[0]->exists;

            if (! $tableExists) {
                return 0;
            }

            // Get sales from last 90 days
            $salesQuantity = DB::table('invoice_items as ii')
                ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
                ->where('ii.product_id', $productId)
                ->where('i.invoice_date', '>=', now()->subDays(90))
                ->where('i.status', '!=', 'cancelled')
                ->sum('ii.quantity');

            // Calculate daily average
            return $salesQuantity / 90;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Determine which age bucket an item falls into
     */
    protected function determineAgeBucket(int $ageInDays): string
    {
        foreach ($this->ageBuckets as $bucket => $range) {
            if ($ageInDays >= $range['min'] && $ageInDays <= $range['max']) {
                return $bucket;
            }
        }

        return '90+';
    }

    /**
     * Calculate summary statistics
     */
    protected function calculateSummary($agedInventory): array
    {
        return [
            'total_items' => $agedInventory->count(),
            'total_inventory_value' => round($agedInventory->sum('inventory_value'), 2),
            'total_carrying_cost' => round($agedInventory->sum('carrying_cost'), 2),
            'slow_moving_items' => $agedInventory->where('is_slow_moving', true)->count(),
            'average_age_days' => round($agedInventory->avg('age_in_days'), 0),
            'oldest_item_age_days' => $agedInventory->max('age_in_days'),
        ];
    }

    /**
     * Group inventory by age bucket
     */
    protected function groupByAgeBucket($agedInventory): array
    {
        $grouped = [];

        foreach ($this->ageBuckets as $bucket => $range) {
            $items = $agedInventory->where('age_bucket', $bucket);

            $grouped[$bucket] = [
                'bucket' => $bucket,
                'item_count' => $items->count(),
                'total_quantity' => $items->sum('stock_quantity'),
                'total_value' => round($items->sum('inventory_value'), 2),
                'total_carrying_cost' => round($items->sum('carrying_cost'), 2),
                'slow_moving_count' => $items->where('is_slow_moving', true)->count(),
            ];
        }

        return array_values($grouped);
    }

    /**
     * Get slow-moving items only
     */
    public function getSlowMovingItems(ReportFilter $filter): ReportData
    {
        $asOfDate = $filter->endDate ?? now();
        $agedInventory = $this->getAgedInventory($asOfDate, $filter);

        $slowMoving = $agedInventory->where('is_slow_moving', true)->values();

        return new ReportData(
            title: 'Slow-Moving Inventory',
            data: $slowMoving->toArray(),
            summary: [
                'total_slow_moving_items' => $slowMoving->count(),
                'total_value_tied_up' => round($slowMoving->sum('inventory_value'), 2),
                'total_carrying_cost' => round($slowMoving->sum('carrying_cost'), 2),
                'average_age_days' => round($slowMoving->avg('age_in_days'), 0),
            ],
            metadata: [
                'as_of_date' => $asOfDate->format('Y-m-d'),
                'generated_at' => now()->toIso8601String(),
            ]
        );
    }

    /**
     * Get items by specific age bucket
     */
    public function getByAgeBucket(string $bucket, ReportFilter $filter): ReportData
    {
        $asOfDate = $filter->endDate ?? now();
        $agedInventory = $this->getAgedInventory($asOfDate, $filter);

        $bucketItems = $agedInventory->where('age_bucket', $bucket)->values();

        return new ReportData(
            title: "Inventory Aged {$bucket} Days",
            data: $bucketItems->toArray(),
            summary: [
                'total_items' => $bucketItems->count(),
                'total_quantity' => $bucketItems->sum('stock_quantity'),
                'total_value' => round($bucketItems->sum('inventory_value'), 2),
                'total_carrying_cost' => round($bucketItems->sum('carrying_cost'), 2),
            ],
            metadata: [
                'age_bucket' => $bucket,
                'as_of_date' => $asOfDate->format('Y-m-d'),
                'generated_at' => now()->toIso8601String(),
            ]
        );
    }

    /**
     * Get items older than specified days
     */
    public function getItemsOlderThan(int $days, ReportFilter $filter): array
    {
        $asOfDate = $filter->endDate ?? now();
        $agedInventory = $this->getAgedInventory($asOfDate, $filter);

        $oldItems = $agedInventory->where('age_in_days', '>', $days)->values();

        return [
            'items' => $oldItems->toArray(),
            'total_items' => $oldItems->count(),
            'total_value' => round($oldItems->sum('inventory_value'), 2),
            'total_carrying_cost' => round($oldItems->sum('carrying_cost'), 2),
            'threshold_days' => $days,
            'as_of_date' => $asOfDate->format('Y-m-d'),
        ];
    }
}

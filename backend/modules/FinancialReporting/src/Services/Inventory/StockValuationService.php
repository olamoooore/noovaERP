<?php

namespace Modules\FinancialReporting\Services\Inventory;

use App\Models\AccountingPolicy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\FinancialReporting\DTOs\ReportData;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\BaseReportService;

class StockValuationService extends BaseReportService
{
    /**
     * Generate Stock Valuation report
     *
     * Shows current inventory value based on quantity × cost (WAC - Weighted Average Cost)
     */
    public function generate(ReportFilter $filter): ReportData
    {
        $asOfDate = $filter->endDate ?? now();
        $categoryId = $filter->categoryId;

        // Get stock valuation
        $products = $this->getStockValuation($asOfDate, $categoryId);

        // Calculate totals
        $totalQuantity = $products->sum('quantity');
        $totalValue = $products->sum('total_value');
        $valuationMethod = $this->resolveValuationMethod();

        // Group by category if available
        $byCategory = $products->groupBy('category_name')->map(function ($items, $category) {
            return [
                'category' => $category ?: 'Uncategorized',
                'product_count' => $items->count(),
                'total_quantity' => $items->sum('quantity'),
                'total_value' => round($items->sum('total_value'), 2),
            ];
        })->values();

        return new ReportData(
            title: 'Stock Valuation Report',
            data: $products->toArray(),
            summary: [
                'total_products' => $products->count(),
                'total_quantity' => $totalQuantity,
                'total_value' => round($totalValue, 2),
                'average_value_per_product' => $products->count() > 0 ? round($totalValue / $products->count(), 2) : 0,
            ],
            metadata: [
                'as_of_date' => $asOfDate->format('Y-m-d'),
                'category_filter' => $categoryId,
                'valuation_method' => $valuationMethod,
                'by_category' => $byCategory->toArray(),
                'generated_at' => now()->toIso8601String(),
            ]
        );
    }

    /**
     * Get stock valuation for all products
     */
    protected function getStockValuation(Carbon $asOfDate, ?int $categoryId = null)
    {
        $stockLevelsExists = DB::getSchemaBuilder()->hasTable('stock_levels');
        $query = DB::table('products as p')
            ->where('p.is_active', true)
            ->where('p.track_inventory', true);

        // Note: Category filtering not available as products table doesn't have category_id
        // If needed in future, add category_id column to products table

        if ($stockLevelsExists) {
            $query->leftJoin(
                DB::raw('(SELECT product_id, SUM(quantity) as total_quantity FROM stock_levels GROUP BY product_id) as sl'),
                'sl.product_id',
                '=',
                'p.id'
            );
        }

        $products = $query->select([
            'p.id',
            'p.sku',
            'p.name',
            'p.cost',
            'p.unit',
            'p.reorder_level',
            DB::raw($stockLevelsExists ? 'COALESCE(sl.total_quantity, 0) as current_quantity' : 'p.stock_quantity as current_quantity'),
        ])
            ->get();

        // Preserve historical behavior when a past as-of date is requested.
        if ($stockLevelsExists && ! $asOfDate->copy()->startOfDay()->equalTo(now()->startOfDay())) {
            $stockLevelsExists = false;
        }

        // Calculate current stock from stock_levels when available
        $valuations = collect();
        $valuationMethod = $this->resolveValuationMethod();

        foreach ($products as $product) {
            $quantity = $stockLevelsExists
                ? (int) $product->current_quantity
                : $this->getQuantityAsOfDate($product->id, $asOfDate);

            // Only include products with stock
            if ($quantity > 0) {
                $unitCost = $this->resolveCostForMethod(
                    productId: (int) $product->id,
                    fallbackCost: (float) $product->cost,
                    valuationMethod: $valuationMethod,
                );
                $totalValue = $quantity * $unitCost;

                $valuations->push([
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'category_name' => null, // No categories in current schema
                    'quantity' => $quantity,
                    'unit' => $product->unit,
                    'cost' => round($unitCost, 2),
                    'total_value' => round($totalValue, 2),
                    'reorder_level' => $product->reorder_level,
                    'below_reorder' => $quantity < $product->reorder_level,
                ]);
            }
        }

        return $valuations->sortByDesc('total_value')->values();
    }

    /**
     * Get historical stock valuation (as of specific date)
     */
    public function getHistoricalValuation(Carbon $asOfDate, ?int $categoryId = null): array
    {
        $stockLevelsExists = DB::getSchemaBuilder()->hasTable('stock_levels');
        // Get all products
        $query = DB::table('products as p')
            ->where('p.is_active', true)
            ->where('p.track_inventory', true);

        // Note: Category filtering not available as products table doesn't have category_id

        if ($stockLevelsExists) {
            $query->leftJoin(
                DB::raw('(SELECT product_id, SUM(quantity) as total_quantity FROM stock_levels GROUP BY product_id) as sl'),
                'sl.product_id',
                '=',
                'p.id'
            );
        }

        $products = $query->select([
            'p.id',
            'p.sku',
            'p.name',
            'p.cost',
            'p.unit',
            DB::raw($stockLevelsExists ? 'COALESCE(sl.total_quantity, 0) as current_quantity' : 'p.stock_quantity as current_quantity'),
        ])
            ->get();

        // Preserve historical behavior when a past as-of date is requested.
        if ($stockLevelsExists && ! $asOfDate->copy()->startOfDay()->equalTo(now()->startOfDay())) {
            $stockLevelsExists = false;
        }

        $valuationMethod = $this->resolveValuationMethod();

        $valuations = [];
        $totalValue = 0;

        foreach ($products as $product) {
            $quantity = $stockLevelsExists
                ? (int) $product->current_quantity
                : $this->getQuantityAsOfDate($product->id, $asOfDate);

            if ($quantity > 0) {
                $unitCost = $this->resolveCostForMethod(
                    productId: (int) $product->id,
                    fallbackCost: (float) $product->cost,
                    valuationMethod: $valuationMethod,
                );
                $value = $quantity * $unitCost;
                $totalValue += $value;

                $valuations[] = [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'category_name' => null, // No categories in current schema
                    'quantity' => $quantity,
                    'unit' => $product->unit,
                    'cost' => round($unitCost, 2),
                    'total_value' => round($value, 2),
                ];
            }
        }

        // Sort by value descending
        usort($valuations, fn ($a, $b) => $b['total_value'] <=> $a['total_value']);

        return [
            'as_of_date' => $asOfDate->format('Y-m-d'),
            'valuation_method' => $valuationMethod,
            'products' => $valuations,
            'summary' => [
                'total_products' => count($valuations),
                'total_value' => round($totalValue, 2),
            ],
        ];
    }

    protected function resolveValuationMethod(): string
    {
        try {
            return AccountingPolicy::current()->inventory_valuation_method ?? 'fifo';
        } catch (\Throwable $e) {
            return 'fifo';
        }
    }

    protected function resolveCostForMethod(int $productId, float $fallbackCost, string $valuationMethod): float
    {
        if (! DB::getSchemaBuilder()->hasTable('product_batches')) {
            return $fallbackCost;
        }

        $batches = DB::table('product_batches')
            ->where('product_id', $productId)
            ->where('quantity', '>', 0)
            ->select('cost', 'quantity', 'created_at', 'id');

        if (DB::getSchemaBuilder()->hasColumn('product_batches', 'manufacture_date')) {
            $batches->addSelect(DB::raw('manufacture_date as valuation_date'));
        } elseif (DB::getSchemaBuilder()->hasColumn('product_batches', 'manufacturing_date')) {
            $batches->addSelect(DB::raw('manufacturing_date as valuation_date'));
        } else {
            $batches->addSelect(DB::raw('NULL as valuation_date'));
        }

        $batches = $batches->get();

        if ($batches->isEmpty()) {
            return $fallbackCost;
        }

        return match ($valuationMethod) {
            'weighted_average' => $this->weightedAverageBatchCost($batches, $fallbackCost),
            'lifo' => (float) ($batches->sortByDesc(fn ($b) => [$b->valuation_date ?? $b->created_at, $b->id])->first()->cost ?? $fallbackCost),
            'fifo' => (float) ($batches->sortBy(fn ($b) => [$b->valuation_date ?? $b->created_at, $b->id])->first()->cost ?? $fallbackCost),
            'specific_identification' => $fallbackCost,
            default => $fallbackCost,
        };
    }

    protected function weightedAverageBatchCost($batches, float $fallbackCost): float
    {
        $totalQty = (float) $batches->sum('quantity');
        if ($totalQty <= 0) {
            return $fallbackCost;
        }

        $totalCost = $batches->reduce(
            fn (float $carry, $batch) => $carry + ((float) $batch->cost * (float) $batch->quantity),
            0.0
        );

        return $totalCost / $totalQty;
    }

    /**
     * Calculate quantity as of specific date from stock movements
     */
    protected function getQuantityAsOfDate(int $productId, Carbon $asOfDate): int
    {
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
     * Get low stock products (below reorder level)
     */
    public function getLowStockProducts(): array
    {
        $stockLevelsExists = DB::getSchemaBuilder()->hasTable('stock_levels');
        $products = DB::table('products as p')
            ->where('p.is_active', true)
            ->where('p.track_inventory', true)
            ->when($stockLevelsExists, function ($query) {
                $query->leftJoin(
                    DB::raw('(SELECT product_id, SUM(quantity) as total_quantity FROM stock_levels GROUP BY product_id) as sl'),
                    'sl.product_id',
                    '=',
                    'p.id'
                )->whereRaw('COALESCE(sl.total_quantity, 0) < p.reorder_level')
                    ->select([
                        'p.id',
                        'p.sku',
                        'p.name',
                        DB::raw('COALESCE(sl.total_quantity, 0) as stock_quantity'),
                        'p.reorder_level',
                        'p.cost',
                        'p.unit',
                        DB::raw('(p.reorder_level - COALESCE(sl.total_quantity, 0)) as shortage'),
                        DB::raw('((p.reorder_level - COALESCE(sl.total_quantity, 0)) * p.cost) as reorder_value'),
                    ])
                    ->orderBy('shortage', 'desc');
            }, function ($query) {
                $query->whereRaw('p.stock_quantity < p.reorder_level')
                    ->select([
                        'p.id',
                        'p.sku',
                        'p.name',
                        'p.stock_quantity',
                        'p.reorder_level',
                        'p.cost',
                        'p.unit',
                        DB::raw('(p.reorder_level - p.stock_quantity) as shortage'),
                        DB::raw('((p.reorder_level - p.stock_quantity) * p.cost) as reorder_value'),
                    ])
                    ->orderBy('shortage', 'desc');
            })
            ->get();

        return [
            'products' => $products->map(function ($p) {
                return [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'category_name' => null, // No categories in current schema
                    'current_quantity' => $p->stock_quantity,
                    'reorder_level' => $p->reorder_level,
                    'shortage' => $p->shortage,
                    'unit' => $p->unit,
                    'cost' => round($p->cost, 2),
                    'reorder_value' => round($p->reorder_value, 2),
                ];
            })->toArray(),
            'summary' => [
                'total_products' => $products->count(),
                'total_reorder_value' => round($products->sum('reorder_value'), 2),
            ],
        ];
    }
}

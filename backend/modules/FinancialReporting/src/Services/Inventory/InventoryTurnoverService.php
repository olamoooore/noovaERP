<?php

namespace Modules\FinancialReporting\Services\Inventory;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class InventoryTurnoverService
{
    /**
     * Generate inventory turnover report
     * 
     * Calculates:
     * - Inventory Turnover Ratio = COGS / Average Inventory
     * - Days in Inventory = 365 / Turnover Ratio
     * - Fast vs Slow movers classification
     */
    public function generate(Carbon $startDate, Carbon $endDate, ?int $categoryId = null): array
    {
        $products = $this->getProductsWithTurnover($startDate, $endDate, $categoryId);
        
        return [
            'title' => 'Inventory Turnover Report',
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
                'days' => $startDate->diffInDays($endDate),
            ],
            'data' => $products,
            'summary' => $this->calculateSummary($products),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Get products with turnover metrics
     */
    protected function getProductsWithTurnover(Carbon $startDate, Carbon $endDate, ?int $categoryId): array
    {
        $days = $startDate->diffInDays($endDate);
        $stockLevelsExists = $this->tableExists('stock_levels');
        
        // Base query for products
        $query = DB::table('products as p')
            ->select([
                'p.id',
                'p.sku',
                'p.name',
                'p.cost',
                'p.price',
            ]);

        if ($stockLevelsExists) {
            $query->leftJoin(
                DB::raw('(SELECT product_id, SUM(quantity) as total_quantity FROM stock_levels GROUP BY product_id) as sl'),
                'sl.product_id',
                '=',
                'p.id'
            )->addSelect(DB::raw('COALESCE(sl.total_quantity, 0) as stock_quantity'));
        } else {
            $query->addSelect('p.stock_quantity');
        }
        
        // Add category if table exists
        if ($this->tableExists('categories')) {
            $query->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->addSelect('c.name as category_name');
            
            if ($categoryId) {
                $query->where('p.category_id', $categoryId);
            }
        }
        
        $products = $query->get();
        
        $result = [];
        
        foreach ($products as $product) {
            // Calculate COGS (Cost of Goods Sold)
            $cogs = $this->calculateCOGS($product->id, $startDate, $endDate);
            
            // Calculate average inventory
            $avgInventory = $this->calculateAverageInventory($product->id, $startDate, $endDate);
            
            // Calculate turnover ratio
            $turnoverRatio = $avgInventory > 0 ? $cogs / $avgInventory : 0;
            
            // Calculate days in inventory
            $daysInInventory = $turnoverRatio > 0 ? 365 / $turnoverRatio : 0;
            
            // Calculate sales quantity
            $salesQuantity = $this->getSalesQuantity($product->id, $startDate, $endDate);
            
            // Classify as fast/slow mover
            $classification = $this->classifyMover($turnoverRatio);
            
            $result[] = [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'category' => $product->category_name ?? null,
                'current_stock' => (int) $product->stock_quantity,
                'cost' => (float) $product->cost,
                'price' => (float) $product->price,
                'cogs' => round($cogs, 2),
                'average_inventory_value' => round($avgInventory, 2),
                'turnover_ratio' => round($turnoverRatio, 2),
                'days_in_inventory' => round($daysInInventory, 0),
                'sales_quantity' => (int) $salesQuantity,
                'classification' => $classification,
                'is_fast_mover' => $classification === 'Fast',
                'is_slow_mover' => $classification === 'Slow',
            ];
        }
        
        // Sort by turnover ratio descending
        usort($result, fn($a, $b) => $b['turnover_ratio'] <=> $a['turnover_ratio']);
        
        return $result;
    }

    /**
     * Calculate Cost of Goods Sold for a product
     */
    protected function calculateCOGS(int $productId, Carbon $startDate, Carbon $endDate): float
    {
        if (!$this->tableExists('invoice_items')) {
            return 0;
        }
        
        $result = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->join('products as p', 'p.id', '=', 'ii.product_id')
            ->where('ii.product_id', $productId)
            ->whereBetween('i.invoice_date', [$startDate, $endDate])
            ->whereIn('i.status', ['paid', 'partial'])
            ->select([
                DB::raw('SUM(ii.quantity * p.cost) as total_cogs')
            ])
            ->first();
        
        return (float) ($result->total_cogs ?? 0);
    }

    /**
     * Calculate average inventory value for a product
     */
    protected function calculateAverageInventory(int $productId, Carbon $startDate, Carbon $endDate): float
    {
        if (!$this->tableExists('stock_movements')) {
            // Fallback: use current stock_levels aggregate if available
            if ($this->tableExists('stock_levels')) {
                $totalQuantity = (float) DB::table('stock_levels')
                    ->where('product_id', $productId)
                    ->sum('quantity');

                $cost = (float) (DB::table('products')->where('id', $productId)->value('cost') ?? 0);
                return $totalQuantity * $cost;
            }

            // Final fallback: products.stock_quantity
            $product = DB::table('products')
                ->where('id', $productId)
                ->first(['stock_quantity', 'cost']);
            
            return $product ? ($product->stock_quantity * $product->cost) : 0;
        }
        
        // Get opening and closing balances
        $openingMovement = DB::table('stock_movements')
            ->where('product_id', $productId)
            ->where('created_at', '<=', $startDate)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        
        $closingMovement = DB::table('stock_movements')
            ->where('product_id', $productId)
            ->where('created_at', '<=', $endDate)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        
        $openingBalance = $openingMovement ? $openingMovement->balance_after : 0;
        $closingBalance = $closingMovement ? $closingMovement->balance_after : 0;
        
        // Get product cost
        $product = DB::table('products')
            ->where('id', $productId)
            ->first(['cost']);
        
        $cost = $product ? $product->cost : 0;
        
        // Calculate average inventory value: (Opening + Closing) / 2 * Cost
        $averageQuantity = ($openingBalance + $closingBalance) / 2;
        
        return $averageQuantity * $cost;
    }

    /**
     * Get stock level at a specific date
     */
    protected function getStockLevelAtDate(int $productId, Carbon $date): int
    {
        // Get the last stock movement before or on the date
        $lastMovement = DB::table('stock_movements')
            ->where('product_id', $productId)
            ->where('created_at', '<=', $date)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        
        return $lastMovement ? $lastMovement->balance_after : 0;
    }

    /**
     * Get product cost at a specific date
     */
    protected function getCostAtDate(int $productId, Carbon $date): float
    {
        // For simplicity, use current cost
        // In a more sophisticated system, you'd track historical costs
        $product = DB::table('products')
            ->where('id', $productId)
            ->first(['cost']);
        
        return (float) ($product->cost ?? 0);
    }

    /**
     * Get sales quantity for a product
     */
    protected function getSalesQuantity(int $productId, Carbon $startDate, Carbon $endDate): int
    {
        if (!$this->tableExists('invoice_items')) {
            return 0;
        }
        
        $result = DB::table('invoice_items as ii')
            ->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->where('ii.product_id', $productId)
            ->whereBetween('i.invoice_date', [$startDate, $endDate])
            ->whereIn('i.status', ['paid', 'partial'])
            ->sum('ii.quantity');
        
        return (int) $result;
    }

    /**
     * Classify product as fast/slow/medium mover
     */
    protected function classifyMover(float $turnoverRatio): string
    {
        if ($turnoverRatio >= 6) {
            return 'Fast'; // Turns over 6+ times per year
        } elseif ($turnoverRatio >= 3) {
            return 'Medium'; // Turns over 3-6 times per year
        } elseif ($turnoverRatio > 0) {
            return 'Slow'; // Turns over less than 3 times per year
        } else {
            return 'No Movement'; // No sales
        }
    }

    /**
     * Calculate summary statistics
     */
    protected function calculateSummary(array $products): array
    {
        if (empty($products)) {
            return [
                'total_products' => 0,
                'average_turnover_ratio' => 0,
                'average_days_in_inventory' => 0,
                'fast_movers' => 0,
                'medium_movers' => 0,
                'slow_movers' => 0,
                'no_movement' => 0,
                'total_cogs' => 0,
                'total_inventory_value' => 0,
            ];
        }
        
        $totalTurnover = 0;
        $totalDays = 0;
        $fastMovers = 0;
        $mediumMovers = 0;
        $slowMovers = 0;
        $noMovement = 0;
        $totalCOGS = 0;
        $totalInventoryValue = 0;
        
        foreach ($products as $product) {
            $totalTurnover += $product['turnover_ratio'];
            $totalDays += $product['days_in_inventory'];
            $totalCOGS += $product['cogs'];
            $totalInventoryValue += $product['average_inventory_value'];
            
            switch ($product['classification']) {
                case 'Fast':
                    $fastMovers++;
                    break;
                case 'Medium':
                    $mediumMovers++;
                    break;
                case 'Slow':
                    $slowMovers++;
                    break;
                case 'No Movement':
                    $noMovement++;
                    break;
            }
        }
        
        $count = count($products);
        
        return [
            'total_products' => $count,
            'average_turnover_ratio' => round($totalTurnover / $count, 2),
            'average_days_in_inventory' => round($totalDays / $count, 0),
            'fast_movers' => $fastMovers,
            'medium_movers' => $mediumMovers,
            'slow_movers' => $slowMovers,
            'no_movement' => $noMovement,
            'total_cogs' => round($totalCOGS, 2),
            'total_inventory_value' => round($totalInventoryValue, 2),
            'by_classification' => [
                [
                    'classification' => 'Fast',
                    'count' => $fastMovers,
                    'percentage' => round(($fastMovers / $count) * 100, 1),
                ],
                [
                    'classification' => 'Medium',
                    'count' => $mediumMovers,
                    'percentage' => round(($mediumMovers / $count) * 100, 1),
                ],
                [
                    'classification' => 'Slow',
                    'count' => $slowMovers,
                    'percentage' => round(($slowMovers / $count) * 100, 1),
                ],
                [
                    'classification' => 'No Movement',
                    'count' => $noMovement,
                    'percentage' => round(($noMovement / $count) * 100, 1),
                ],
            ],
        ];
    }

    /**
     * Get fast movers only
     */
    public function getFastMovers(Carbon $startDate, Carbon $endDate, ?int $categoryId = null): array
    {
        $report = $this->generate($startDate, $endDate, $categoryId);
        
        $report['data'] = array_filter($report['data'], fn($item) => $item['is_fast_mover']);
        $report['title'] = 'Fast Moving Products';
        
        return $report;
    }

    /**
     * Get slow movers only
     */
    public function getSlowMovers(Carbon $startDate, Carbon $endDate, ?int $categoryId = null): array
    {
        $report = $this->generate($startDate, $endDate, $categoryId);
        
        $report['data'] = array_filter($report['data'], fn($item) => $item['is_slow_mover']);
        $report['title'] = 'Slow Moving Products';
        
        return $report;
    }

    /**
     * Get products by classification
     */
    public function getByClassification(string $classification, Carbon $startDate, Carbon $endDate, ?int $categoryId = null): array
    {
        $report = $this->generate($startDate, $endDate, $categoryId);
        
        $report['data'] = array_filter($report['data'], fn($item) => $item['classification'] === $classification);
        $report['title'] = "{$classification} Moving Products";
        
        return $report;
    }

    /**
     * Get turnover by category
     */
    public function getByCategory(Carbon $startDate, Carbon $endDate): array
    {
        if (!$this->tableExists('categories')) {
            return [
                'title' => 'Turnover by Category',
                'data' => [],
                'message' => 'Categories table not available',
            ];
        }
        
        $report = $this->generate($startDate, $endDate);
        
        // Group by category
        $byCategory = [];
        foreach ($report['data'] as $product) {
            $category = $product['category'] ?? 'Uncategorized';
            
            if (!isset($byCategory[$category])) {
                $byCategory[$category] = [
                    'category' => $category,
                    'product_count' => 0,
                    'total_cogs' => 0,
                    'total_inventory_value' => 0,
                    'average_turnover_ratio' => 0,
                    'fast_movers' => 0,
                    'slow_movers' => 0,
                ];
            }
            
            $byCategory[$category]['product_count']++;
            $byCategory[$category]['total_cogs'] += $product['cogs'];
            $byCategory[$category]['total_inventory_value'] += $product['average_inventory_value'];
            $byCategory[$category]['average_turnover_ratio'] += $product['turnover_ratio'];
            
            if ($product['is_fast_mover']) {
                $byCategory[$category]['fast_movers']++;
            }
            if ($product['is_slow_mover']) {
                $byCategory[$category]['slow_movers']++;
            }
        }
        
        // Calculate averages
        foreach ($byCategory as &$category) {
            $category['average_turnover_ratio'] = round($category['average_turnover_ratio'] / $category['product_count'], 2);
            $category['total_cogs'] = round($category['total_cogs'], 2);
            $category['total_inventory_value'] = round($category['total_inventory_value'], 2);
        }
        
        return [
            'title' => 'Inventory Turnover by Category',
            'period' => $report['period'],
            'data' => array_values($byCategory),
            'generated_at' => now()->toISOString(),
        ];
    }

    /**
     * Check if table exists
     */
    protected function tableExists(string $table): bool
    {
        try {
            return DB::getSchemaBuilder()->hasTable($table);
        } catch (\Exception $e) {
            return false;
        }
    }
}

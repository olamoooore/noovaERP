<?php

namespace Modules\FinancialReporting\Services\Inventory;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;
use Modules\FinancialReporting\DTOs\ReportData;
use Modules\FinancialReporting\DTOs\ReportFilter;
use Modules\FinancialReporting\Services\BaseReportService;

class StockMovementService extends BaseReportService
{
    /**
     * Generate Stock Movement report
     * 
     * Shows all stock movements with running balances per product
     */
    public function generate(ReportFilter $filter): ReportData
    {
        $productId = $filter->productId;
        $startDate = $filter->startDate;
        $endDate = $filter->endDate;
        $page = $filter->page;
        $perPage = $filter->perPage;

        // Get product info if specific product requested
        $product = $productId ? $this->getProductInfo($productId) : null;

        // Get opening balance
        $openingBalance = $productId ? $this->getOpeningBalance($productId, $startDate) : 0;

        // Get movements with pagination
        $movements = $this->getMovements($productId, $startDate, $endDate, $page, $perPage);

        // Transform movements to match frontend expectations
        $transformedMovements = collect($movements->items())->map(function ($movement) {
            return [
                'id' => $movement->id,
                'date' => $movement->created_at,
                'product_id' => $movement->product_id,
                'product_name' => $movement->product_name,
                'sku' => $movement->sku,
                'type' => $movement->type,
                'quantity' => $movement->quantity,
                'reference' => $movement->reference,
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id,
                'notes' => $movement->notes,
                'running_balance' => $movement->balance_after,
                'created_by' => $movement->created_by_name,
            ];
        })->toArray();

        // Calculate summary
        $summary = $this->calculateSummary($movements->items(), $openingBalance);

        return new ReportData(
            title: 'Stock Movement Report',
            data: $transformedMovements,
            summary: $summary,
            metadata: [
                'product' => $product ? [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                ] : null,
                'period' => [
                    'start_date' => $startDate?->format('Y-m-d'),
                    'end_date' => $endDate?->format('Y-m-d'),
                ],
                'pagination' => [
                    'current_page' => $movements->currentPage(),
                    'per_page' => $movements->perPage(),
                    'total' => $movements->total(),
                    'last_page' => $movements->lastPage(),
                ],
                'generated_at' => now()->toIso8601String(),
            ]
        );
    }

    /**
     * Get product information
     */
    protected function getProductInfo(int $productId): ?object
    {
        return DB::table('products')
            ->where('id', $productId)
            ->select('id', 'sku', 'name', 'unit', 'stock_quantity')
            ->first();
    }

    /**
     * Get opening balance (balance before start date)
     */
    protected function getOpeningBalance(int $productId, ?Carbon $startDate): int
    {
        if (!$startDate) {
            return 0;
        }

        $lastMovement = DB::table('stock_movements')
            ->where('product_id', $productId)
            ->where('created_at', '<', $startDate)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        return $lastMovement ? $lastMovement->balance_after : 0;
    }

    /**
     * Get stock movements with pagination
     */
    protected function getMovements(
        ?int $productId,
        ?Carbon $startDate,
        ?Carbon $endDate,
        int $page,
        int $perPage
    ): LengthAwarePaginator {
        $query = DB::table('stock_movements as sm')
            ->join('products as p', 'p.id', '=', 'sm.product_id')
            ->leftJoin('users as u', 'u.id', '=', 'sm.created_by');

        // Apply filters
        if ($productId) {
            $query->where('sm.product_id', $productId);
        }
        if ($startDate) {
            $query->where('sm.created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('sm.created_at', '<=', $endDate);
        }

        return $query->select([
                'sm.id',
                'sm.product_id',
                'p.sku',
                'p.name as product_name',
                'p.unit',
                'sm.type',
                'sm.quantity',
                'sm.reference',
                'sm.reference_type',
                'sm.reference_id',
                'sm.notes',
                'sm.balance_after',
                'sm.created_at',
                'u.name as created_by_name',
            ])
            ->orderBy('sm.created_at', 'desc')
            ->orderBy('sm.id', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Calculate summary statistics
     */
    protected function calculateSummary(array $movements, int $openingBalance): array
    {
        $totalIn = 0;
        $totalOut = 0;

        foreach ($movements as $movement) {
            if ($movement->quantity > 0) {
                $totalIn += $movement->quantity;
            } else {
                $totalOut += abs($movement->quantity);
            }
        }

        $closingBalance = count($movements) > 0 
            ? $movements[0]->balance_after 
            : $openingBalance;

        return [
            'opening_balance' => $openingBalance,
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'net_change' => $totalIn - $totalOut,
            'closing_balance' => $closingBalance,
            'movement_count' => count($movements),
        ];
    }

    /**
     * Get movement summary by type
     */
    public function getMovementSummaryByType(ReportFilter $filter): array
    {
        $startDate = $filter->startDate;
        $endDate = $filter->endDate;
        $productId = $filter->productId;

        $query = DB::table('stock_movements as sm')
            ->join('products as p', 'p.id', '=', 'sm.product_id');

        if ($productId) {
            $query->where('sm.product_id', $productId);
        }
        if ($startDate) {
            $query->where('sm.created_at', '>=', $startDate);
        }
        if ($endDate) {
            $query->where('sm.created_at', '<=', $endDate);
        }

        $summary = $query->select([
                'sm.type',
                DB::raw('COUNT(*) as movement_count'),
                DB::raw('SUM(CASE WHEN sm.quantity > 0 THEN sm.quantity ELSE 0 END) as total_in'),
                DB::raw('SUM(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) as total_out'),
                DB::raw('SUM(sm.quantity) as net_change'),
            ])
            ->groupBy('sm.type')
            ->orderBy('movement_count', 'desc')
            ->get();

        return [
            'by_type' => $summary->map(function ($item) {
                return [
                    'type' => $item->type,
                    'movement_count' => $item->movement_count,
                    'total_in' => $item->total_in,
                    'total_out' => $item->total_out,
                    'net_change' => $item->net_change,
                ];
            })->toArray(),
            'period' => [
                'start_date' => $startDate?->format('Y-m-d'),
                'end_date' => $endDate?->format('Y-m-d'),
            ],
        ];
    }

    /**
     * Get most active products (by movement count)
     */
    public function getMostActiveProducts(Carbon $startDate, Carbon $endDate, int $limit = 10): array
    {
        $products = DB::table('stock_movements as sm')
            ->join('products as p', 'p.id', '=', 'sm.product_id')
            ->whereBetween('sm.created_at', [$startDate, $endDate])
            ->select([
                'p.id',
                'p.sku',
                'p.name',
                'p.unit',
                DB::raw('COUNT(*) as movement_count'),
                DB::raw('SUM(CASE WHEN sm.quantity > 0 THEN sm.quantity ELSE 0 END) as total_in'),
                DB::raw('SUM(CASE WHEN sm.quantity < 0 THEN ABS(sm.quantity) ELSE 0 END) as total_out'),
            ])
            ->groupBy('p.id', 'p.sku', 'p.name', 'p.unit')
            ->orderBy('movement_count', 'desc')
            ->limit($limit)
            ->get();

        return [
            'products' => $products->toArray(),
            'period' => [
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
        ];
    }
}

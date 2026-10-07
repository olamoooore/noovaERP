<?php

namespace Modules\FinancialReporting\Services\Inventory;

use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
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

        // Portfolio opening balance: the net of everything before the period.
        // (Previously this was 0 whenever no single product was filtered, so
        // Closing never reconciled with Opening + Net Change.)
        $openingBalance = $this->getOpeningBalance($productId, $startDate);

        // Get movements with pagination
        $movements = $this->getMovements($productId, $startDate, $endDate, $page, $perPage);

        // Transform movements to match frontend expectations
        $transformedMovements = collect($movements->items())->map(function ($movement) {
            return [
                'id' => $movement->id,
                'date' => $movement->created_at,
                'product_id' => $movement->product_id,
                'product_name' => $movement->product_name,
                'product_short_description' => $movement->product_short_description,
                'sku' => $movement->sku,
                'type' => $movement->type,
                'quantity' => $movement->quantity,
                // Signed effect of the movement. Stored `quantity` is always
                // positive (direction lives in `type`; adjustments carry a
                // balance delta), so reports must not read its sign.
                'effective_quantity' => $this->effectiveDelta(
                    $movement->type,
                    (float) $movement->quantity,
                    isset($movement->balance_after) ? (float) $movement->balance_after : null,
                    isset($movement->old_quantity) ? (float) $movement->old_quantity : null,
                ),
                'reference' => $movement->reference,
                'reference_type' => $movement->reference_type,
                'reference_id' => $movement->reference_id,
                'notes' => $movement->notes,
                'running_balance' => $movement->balance_after,
                'created_by' => $movement->created_by_name,
            ];
        })->toArray();

        // Summary is computed over the WHOLE filtered range, not just the
        // current page — paginating changed the totals before.
        $summary = $this->calculateSummary($filter, $openingBalance);

        return new ReportData(
            title: 'Stock Movement Report',
            data: $transformedMovements,
            summary: $summary,
            metadata: [
                'product' => $product ? [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'short_description' => $product->short_description,
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
            ->select('id', 'sku', 'name', 'short_description', 'unit', 'stock_quantity')
            ->first();
    }

    /**
     * Get opening balance (balance before start date)
     */
    protected function getOpeningBalance(?int $productId, ?Carbon $startDate): float
    {
        if (! $startDate) {
            return 0.0;
        }

        $query = DB::table('stock_movements as sm')->where('created_at', '<', $startDate);

        if ($productId) {
            $query->where('product_id', $productId);

            // Single product: the running balance is authoritative.
            $lastMovement = (clone $query)->orderBy('id', 'desc')->first();

            return (float) ($lastMovement->balance_after ?? 0);
        }

        // Portfolio: sum of every prior signed movement reconstructs the
        // total stock the period starts from.
        return (float) $query->selectRaw('COALESCE(SUM('.$this->effectiveDeltaSql().'), 0) as opening')
            ->value('opening');
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
            'p.short_description as product_short_description',
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
    protected function calculateSummary(ReportFilter $filter, float $openingBalance): array
    {
        $query = DB::table('stock_movements as sm');

        if ($filter->productId) {
            $query->where('sm.product_id', $filter->productId);
        }
        if ($filter->startDate) {
            $query->where('sm.created_at', '>=', $filter->startDate);
        }
        if ($filter->endDate) {
            $query->where('sm.created_at', '<=', $filter->endDate);
        }

        $deltaSql = $this->effectiveDeltaSql();

        $row = $query->selectRaw("
            COALESCE(SUM(CASE WHEN ($deltaSql) > 0 THEN ($deltaSql) ELSE 0 END), 0) as total_in,
            COALESCE(SUM(CASE WHEN ($deltaSql) < 0 THEN ABS($deltaSql) ELSE 0 END), 0) as total_out,
            COALESCE(SUM($deltaSql), 0) as net_change,
            COUNT(*) as movement_count
        ")->first();

        $totalIn = (float) ($row->total_in ?? 0);
        $totalOut = (float) ($row->total_out ?? 0);
        $netChange = (float) ($row->net_change ?? 0);

        return [
            'opening_balance' => round($openingBalance, 4),
            'total_in' => round($totalIn, 4),
            'total_out' => round($totalOut, 4),
            'net_change' => round($netChange, 4),
            'closing_balance' => round($openingBalance + $netChange, 4),
            'movement_count' => (int) ($row->movement_count ?? 0),
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

        $deltaSql = $this->effectiveDeltaSql();

        $summary = $query->select([
            'sm.type',
            DB::raw('COUNT(*) as movement_count'),
            DB::raw("SUM(CASE WHEN ($deltaSql) > 0 THEN ($deltaSql) ELSE 0 END) as total_in"),
            DB::raw("SUM(CASE WHEN ($deltaSql) < 0 THEN ABS($deltaSql) ELSE 0 END) as total_out"),
            DB::raw("SUM($deltaSql) as net_change"),
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

    /**
     * SQL expression for a movement's signed stock effect.
     *
     * `stock_movements.quantity` is always stored positive: direction is
     * encoded in `type`, and an adjustment carries the new balance so its
     * effect is balance_after - old_quantity. Reading the sign of `quantity`
     * (the previous report logic) counted every removal as an addition.
     */
    protected function effectiveDeltaSql(string $alias = 'sm'): string
    {
        return 'CASE '
            ."WHEN {$alias}.type IN ('out', 'sale', 'transfer_out') THEN -ABS({$alias}.quantity) "
            ."WHEN {$alias}.type IN ('in', 'purchase', 'return', 'transfer_in') THEN ABS({$alias}.quantity) "
            ."WHEN {$alias}.type = 'adjustment' THEN (COALESCE({$alias}.balance_after, 0) - COALESCE({$alias}.old_quantity, 0)) "
            ."ELSE {$alias}.quantity END";
    }

    /**
     * PHP-side counterpart of effectiveDeltaSql for a single movement row.
     */
    protected function effectiveDelta(string $type, float $quantity, ?float $balanceAfter, ?float $oldQuantity): float
    {
        return match ($type) {
            'out', 'sale', 'transfer_out' => -abs($quantity),
            'in', 'purchase', 'return', 'transfer_in' => abs($quantity),
            'adjustment' => ($balanceAfter ?? 0.0) - ($oldQuantity ?? 0.0),
            default => $quantity,
        };
    }
}

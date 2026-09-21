<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Services\EntityAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Issue #113: legal entity ids the current user may see. Null means
     * "entity scoping not active" — the same fail-open default as
     * EnsureEntityAccess (single-entity tenants / users with no entity grants
     * keep the historical dashboard).
     *
     * @var int[]|null
     */
    private ?array $scopedEntityIds = null;

    private bool $entityScopeActive = false;

    public function index(Request $request)
    {
        $this->resolveEntityScope($request);

        $range = $request->validate([
            'range' => 'nullable|in:7d,30d,90d',
        ])['range'] ?? '30d';
        $days = (int) rtrim($range, 'd');
        $startDate = now()->startOfDay()->subDays($days - 1);
        $endDate = now()->endOfDay();
        $previousStart = $startDate->copy()->subDays($days);
        $previousEnd = $startDate->copy()->subSecond();

        $totalRevenue = $this->getTotalRevenue($startDate, $endDate);
        $previousRevenue = $this->getTotalRevenue($previousStart, $previousEnd);

        $stats = [
            'range' => $range,
            'range_start' => $startDate->toDateString(),
            'range_end' => $endDate->toDateString(),
            'entity_scoped' => $this->entityScopeActive,
            'total_revenue' => $totalRevenue,
            'revenue_delta_pct' => $previousRevenue > 0
                ? round((($totalRevenue - $previousRevenue) / $previousRevenue) * 100, 1)
                : null,
            'outstanding_invoices' => $this->getOutstandingInvoices(),
            'total_customers' => $this->applyEntityScope(Customer::where('is_active', true))->count(),
            // Products have no legal entity dimension (tenant-global catalogue) —
            // deliberately unscoped, unlike the financial figures above.
            'total_products' => Product::where('is_active', true)->count(),
            'low_stock_products' => $this->getLowStockProducts(),
            'recent_invoices' => $this->getRecentInvoices(),
            'revenue_trend' => $this->getRevenueTrend($startDate, $endDate),
        ];

        return response()->json($stats);
    }

    /**
     * Issue #113: resolve the user's accessible legal entities. Empty grants
     * leave scoping inactive (fail-open) so single-entity tenants that never
     * provisioned the entity model keep working, matching EnsureEntityAccess.
     */
    private function resolveEntityScope(Request $request): void
    {
        $user = $request->user();

        if (! $user) {
            return;
        }

        $ids = collect(app(EntityAccessService::class)->getAccessibleEntities((int) $user->id))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        $this->scopedEntityIds = $ids;
        $this->entityScopeActive = true;
    }

    /**
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    private function applyEntityScope(Builder $query, string $column = 'legal_entity_id'): Builder
    {
        if (! $this->entityScopeActive) {
            return $query;
        }

        return $query->whereIn($column, $this->scopedEntityIds);
    }

    /**
     * Invoices have no legal_entity_id column; scope them through the customer
     * that owns them.
     *
     * @template T of Builder
     *
     * @param  T  $query
     * @return T
     */
    private function applyInvoiceEntityScope(Builder $query): Builder
    {
        if (! $this->entityScopeActive) {
            return $query;
        }

        return $query->whereHas('customer', fn (Builder $q) => $q->whereIn('legal_entity_id', $this->scopedEntityIds));
    }

    private function getTotalRevenue($startDate, $endDate): float
    {
        return (float) $this->applyInvoiceEntityScope(
            Invoice::whereIn('status', ['sent', 'paid', 'overdue'])
                ->whereBetween('invoice_date', [$startDate->toDateString(), $endDate->toDateString()])
        )->sum('total');
    }

    private function getOutstandingInvoices()
    {
        $base = fn () => $this->applyInvoiceEntityScope(
            Invoice::whereIn('status', ['sent', 'overdue'])
        );

        return [
            'count' => $base()->count(),
            'amount' => (float) $base()->sum('balance'),
        ];
    }

    private function getLowStockProducts()
    {
        return Product::where('track_inventory', true)
            ->whereColumn('stock_quantity', '<=', 'reorder_level')
            ->count();
    }

    private function getRecentInvoices()
    {
        return $this->applyInvoiceEntityScope(
            Invoice::with('customer')
        )
            ->orderBy('invoice_date', 'desc')
            ->limit(5)
            ->get();
    }

    private function getRevenueTrend($startDate, $endDate): array
    {
        $revenueByDate = $this->applyInvoiceEntityScope(
            Invoice::select(
                DB::raw('CAST(invoice_date AS DATE) as revenue_date'),
                DB::raw('SUM(total) as revenue'),
            )
                ->whereIn('status', ['sent', 'paid', 'overdue'])
                ->whereBetween('invoice_date', [$startDate->toDateString(), $endDate->toDateString()])
        )
            ->groupBy(DB::raw('CAST(invoice_date AS DATE)'))
            ->pluck('revenue', 'revenue_date');

        $series = [];
        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $key = $date->toDateString();
            $series[] = ['x' => $key, 'v' => (float) ($revenueByDate[$key] ?? 0)];
        }

        return $series;
    }
}

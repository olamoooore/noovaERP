<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Services\AgedReceivablesService;
use App\Services\EntityAccessService;
use Modules\Ecommerce\Models\WebOrder;
use Modules\POS\Models\PosOrder;
use Modules\Sales\Models\SalesOrder;
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
            // Issue #114: trend badge for the AR Outstanding KPI tile.
            'outstanding_delta_pct' => $this->getOutstandingDeltaPct($startDate),
            // Issue #111: real AR aging buckets (was always the frontend's
            // hardcoded zero fallback because the backend omitted the key).
            'ar_aging' => $this->getArAging(),
            'total_customers' => $this->applyEntityScope(Customer::where('is_active', true))->count(),
            // Products have no legal entity dimension (tenant-global catalogue) —
            // deliberately unscoped, unlike the financial figures above.
            'total_products' => Product::where('is_active', true)->count(),
            // Issue #112: the Reports tab reads orders_today (falling back to
            // pos_orders_today) — the backend previously returned neither, so
            // the metric (and its CSV export) was silently always 0.
            'orders_today' => $this->getOrdersToday(),
            'pos_orders_today' => $this->getPosOrdersToday(),
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
        // Canonical outstanding AR universe (matches AgedReceivablesService):
        // open statuses with a positive balance, excluding voided/reversed/
        // superseded documents (issue #96) — status alone is not enough.
        $base = fn () => $this->applyInvoiceEntityScope(
            Invoice::whereIn('status', ['sent', 'posted', 'partial', 'overdue'])
                ->where('balance', '>', 0)
                ->notSuperseded()
        );

        return [
            'count' => $base()->count(),
            'amount' => (float) $base()->sum('balance'),
        ];
    }

    /**
     * Issue #111: AR aging buckets for the dashboard card, derived from the
     * canonical AgedReceivablesService (shared-service-first) and mapped to the
     * five buckets the frontend renders.
     */
    private function getArAging(): array
    {
        $filters = ($this->entityScopeActive && ! empty($this->scopedEntityIds))
            ? ['legal_entity_ids' => $this->scopedEntityIds]
            : [];

        $summary = app(AgedReceivablesService::class)->getAgedReceivables($filters)['summary'];

        return [
            ['bucket' => 'Current', 'amount' => round((float) $summary['current'], 2), 'color' => 'bg-emerald-500'],
            ['bucket' => '1 - 30 days', 'amount' => round((float) $summary['days_1_30'], 2), 'color' => 'bg-sky-500'],
            ['bucket' => '31 - 60 days', 'amount' => round((float) $summary['days_31_60'], 2), 'color' => 'bg-amber-500'],
            ['bucket' => '61 - 90 days', 'amount' => round((float) $summary['days_61_90'], 2), 'color' => 'bg-orange-500'],
            ['bucket' => '90+ days', 'amount' => round((float) $summary['days_90_plus'], 2), 'color' => 'bg-rose-500'],
        ];
    }

    /**
     * Issue #114: current outstanding AR vs. the outstanding AR as of the start
     * of the selected range (mirrors revenue_delta_pct's prior-period framing).
     */
    private function getOutstandingDeltaPct(\Carbon\Carbon $rangeStart): ?float
    {
        $current = (float) $this->getOutstandingInvoices()['amount'];
        $prior = $this->getOutstandingAmountAsOf($rangeStart->copy()->subSecond());

        if ($prior <= 0) {
            return null;
        }

        return round((($current - $prior) / $prior) * 100, 1);
    }

    /**
     * Outstanding AR as of a point in time: posted invoices dated on/before the
     * date, less applied payments and posted credit notes dated on/before it.
     */
    private function getOutstandingAmountAsOf(\Carbon\Carbon $asOf): float
    {
        $invoiceIds = $this->applyInvoiceEntityScope(
            Invoice::whereIn('lifecycle_status', ['posted', 'credited'])
                ->notSuperseded()
        )
            ->where('invoice_date', '<=', $asOf->toDateString())
            ->pluck('id');

        if ($invoiceIds->isEmpty()) {
            return 0.0;
        }

        $invoiced = (float) Invoice::whereIn('id', $invoiceIds)->sum('total');

        $paid = (float) Payment::whereIn('invoice_id', $invoiceIds)
            ->where('payment_date', '<=', $asOf->toDateString())
            ->where('payment_status', 'applied')
            ->sum('amount');

        $credited = (float) CustomerCreditNote::whereIn('invoice_id', $invoiceIds)
            ->where('status', 'posted')
            ->where('credit_note_date', '<=', $asOf->toDateString())
            ->sum('total_amount');

        return max(0.0, round($invoiced - $paid - $credited, 2));
    }

    /**
     * Issue #112: orders created today across the sales channels.
     * Issue #235: POS and web orders now carry legal_entity_id (migration
     * 2026_09_22_000010) and are scoped like sales orders — previously the
     * figure blended every entity's POS/web volume.
     */
    private function getOrdersToday(): int
    {
        $today = now()->toDateString();

        $salesOrders = $this->applyEntityScope(
            SalesOrder::whereDate('created_at', $today)
        )->count();

        return $salesOrders
            + $this->applyEntityScope(PosOrder::whereDate('created_at', $today))->count()
            + $this->applyEntityScope(WebOrder::whereDate('created_at', $today))->count();
    }

    private function getPosOrdersToday(): int
    {
        return $this->applyEntityScope(
            PosOrder::whereDate('created_at', now()->toDateString())
        )->count();
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

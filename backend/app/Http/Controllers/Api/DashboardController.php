<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
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
            'total_revenue' => $totalRevenue,
            'revenue_delta_pct' => $previousRevenue > 0
                ? round((($totalRevenue - $previousRevenue) / $previousRevenue) * 100, 1)
                : null,
            'outstanding_invoices' => $this->getOutstandingInvoices(),
            'total_customers' => Customer::where('is_active', true)->count(),
            'total_products' => Product::where('is_active', true)->count(),
            'low_stock_products' => $this->getLowStockProducts(),
            'recent_invoices' => $this->getRecentInvoices(),
            'revenue_trend' => $this->getRevenueTrend($startDate, $endDate),
        ];

        return response()->json($stats);
    }

    private function getTotalRevenue($startDate, $endDate): float
    {
        return (float) Invoice::whereIn('status', ['sent', 'paid', 'overdue'])
            ->whereBetween('invoice_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->sum('total');
    }

    private function getOutstandingInvoices()
    {
        return [
            'count' => Invoice::whereIn('status', ['sent', 'overdue'])->count(),
            'amount' => Invoice::whereIn('status', ['sent', 'overdue'])->sum('balance'),
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
        return Invoice::with('customer')
            ->orderBy('invoice_date', 'desc')
            ->limit(5)
            ->get();
    }

    private function getRevenueTrend($startDate, $endDate): array
    {
        $revenueByDate = Invoice::select(
            DB::raw('CAST(invoice_date AS DATE) as revenue_date'),
            DB::raw('SUM(total) as revenue'),
        )
            ->whereIn('status', ['sent', 'paid', 'overdue'])
            ->whereBetween('invoice_date', [$startDate->toDateString(), $endDate->toDateString()])
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

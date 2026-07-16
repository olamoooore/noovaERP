<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'total_revenue' => $this->getTotalRevenue(),
            'outstanding_invoices' => $this->getOutstandingInvoices(),
            'total_customers' => Customer::count(),
            'total_products' => Product::where('is_active', true)->count(),
            'low_stock_products' => $this->getLowStockProducts(),
            'recent_invoices' => $this->getRecentInvoices(),
            'revenue_by_month' => $this->getRevenueByMonth(),
        ];

        return response()->json($stats);
    }

    private function getTotalRevenue()
    {
        return Invoice::whereIn('status', ['sent', 'paid', 'overdue'])
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

    private function getRevenueByMonth()
    {
        return Invoice::select(
            DB::raw('DATE_TRUNC(\'month\', invoice_date) as month'),
            DB::raw('SUM(total) as revenue')
        )
            ->whereIn('status', ['sent', 'paid', 'overdue'])
            ->where('invoice_date', '>=', now()->subMonths(12))
            ->groupBy('month')
            ->orderBy('month')
            ->get();
    }
}

<?php

/**
 * PHASE 1 - Sales Module Routes
 * 
 * Core sales functionality:
 * - Customers management
 * - Invoices with auto-posting to accounting
 * - Payments with ledger integration
 * 
 * Note: Main routes are in Core module (customers, invoices, payments)
 * This file contains sales-specific extensions
 */

use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    // Health check
    Route::get('/sales/health', function () {
        return ['module' => 'sales', 'ok' => true, 'phase' => 1];
    });

    // Sales Dashboard Stats
    Route::get('/sales/dashboard', function () {
        $today = now()->startOfDay();
        $thisMonth = now()->startOfMonth();

        return response()->json([
            'today' => [
                'invoices' => \App\Models\Invoice::whereDate('invoice_date', $today)->count(),
                'revenue' => \App\Models\Invoice::whereDate('invoice_date', $today)->where('status', 'paid')->sum('total'),
                'payments' => \App\Models\Payment::whereDate('payment_date', $today)->sum('amount'),
            ],
            'this_month' => [
                'invoices' => \App\Models\Invoice::where('invoice_date', '>=', $thisMonth)->count(),
                'revenue' => \App\Models\Invoice::where('invoice_date', '>=', $thisMonth)->where('status', 'paid')->sum('total'),
                'payments' => \App\Models\Payment::where('payment_date', '>=', $thisMonth)->sum('amount'),
                'outstanding' => \App\Models\Invoice::whereIn('status', ['sent', 'overdue'])->sum('balance'),
            ],
            'customers' => [
                'total' => \App\Models\Customer::count(),
                'with_balance' => \App\Models\Customer::where('outstanding_balance', '>', 0)->count(),
            ],
        ]);
    });

    // Sales Reports
    Route::prefix('sales/reports')->group(function () {
        // Revenue by period
        Route::get('/revenue', function () {
            $start = request('start_date', now()->startOfMonth()->format('Y-m-d'));
            $end = request('end_date', now()->format('Y-m-d'));

            $daily = \App\Models\Invoice::where('status', 'paid')
                ->whereBetween('invoice_date', [$start, $end])
                ->selectRaw('DATE(invoice_date) as date, SUM(total) as revenue, COUNT(*) as count')
                ->groupByRaw('DATE(invoice_date)')
                ->orderBy('date')
                ->get();

            return response()->json([
                'period' => ['start' => $start, 'end' => $end],
                'daily' => $daily,
                'total' => $daily->sum('revenue'),
            ]);
        });

        // Top customers
        Route::get('/top-customers', function () {
            return response()->json(
                \App\Models\Customer::withSum(['invoices' => fn($q) => $q->where('status', 'paid')], 'total')
                    ->orderByDesc('invoices_sum_total')
                    ->limit(10)
                    ->get()
            );
        });
    });
});

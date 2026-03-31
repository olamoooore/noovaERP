<?php

// FROZEN — see EDR-ACC-ARCH-2026-03-29 — no new routes
// All new accounting routes must be added to backend/modules/Core/routes/api.php
// This file is monitored by CI: any non-comment change will fail the build.

/**
 * @deprecated EDR-ACC-ARCH-2026-03-29 — Legacy accounting module routes.
 * Core accounting routes live in Core module now.
 * These routes are frozen and will be retired in a future phase.
 */

use Illuminate\Support\Facades\Route;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;

Route::middleware('auth:sanctum')->group(function () {
    // Health check
    Route::get('/accounting/health', function () {
        return [
            'module' => 'accounting',
            'ok' => true,
            'phase' => 1,
            'features' => [
                'chart_of_accounts' => true,
                'journal_entries' => true,
                'trial_balance' => true,
                'ledger' => true,
                'auto_posting' => true,
            ],
        ];
    });

    // RETIRED — see EDR-ACC-ARCH-2026-03-29
    // Dashboard route moved to Core module (AccountingDashboardController)
    // Route::get('/accounting/dashboard', function () { ... });

    // RETIRED — see EDR-ACC-ARCH-2026-03-29
    // Journal entry creation moved to Core module (JournalEntriesController::store)
    // Route::post('/accounting/journal-entries', function () { ... });

    // Post draft journal entry
    Route::post('/accounting/journal-entries/{journalEntry}/post', function (JournalEntry $journalEntry) {
        if ($journalEntry->status !== 'draft') {
            return response()->json(['error' => 'Entry already posted'], 422);
        }

        // Validate balance
        $totalDebit = $journalEntry->lines->sum('debit');
        $totalCredit = $journalEntry->lines->sum('credit');

        if (abs($totalDebit - $totalCredit) > 0.01) {
            return response()->json(['error' => 'Entry must balance before posting'], 422);
        }

        $journalEntry->update([
            'status' => 'posted',
            'posted_at' => now(),
            'posted_by' => auth()->id(),
        ]);

        return response()->json($journalEntry->fresh('lines.account'));
    });

    // Financial Reports
    Route::prefix('/accounting/reports')->group(function () {
        // Income Statement (P&L)
        Route::get('/income-statement', function () {
            $start = request('start_date', now()->startOfMonth()->format('Y-m-d'));
            $end = request('end_date', now()->format('Y-m-d'));

            $revenueAccounts = ChartOfAccount::where('type', 'revenue')
                ->where('is_active', true)
                ->get()
                ->map(fn($a) => [
                    'code' => $a->code,
                    'name' => $a->name,
                    'balance' => $a->getBalance(),
                ]);

            $expenseAccounts = ChartOfAccount::where('type', 'expense')
                ->where('is_active', true)
                ->get()
                ->map(fn($a) => [
                    'code' => $a->code,
                    'name' => $a->name,
                    'balance' => $a->getBalance(),
                ]);

            $totalRevenue = $revenueAccounts->sum('balance');
            $totalExpenses = $expenseAccounts->sum('balance');

            return response()->json([
                'period' => ['start' => $start, 'end' => $end],
                'revenue' => $revenueAccounts,
                'expenses' => $expenseAccounts,
                'total_revenue' => $totalRevenue,
                'total_expenses' => $totalExpenses,
                'net_income' => $totalRevenue - $totalExpenses,
            ]);
        });

        // Balance Sheet
        Route::get('/balance-sheet', function () {
            $assets = ChartOfAccount::where('type', 'asset')
                ->where('is_active', true)
                ->get()
                ->map(fn($a) => ['code' => $a->code, 'name' => $a->name, 'balance' => $a->getBalance()]);

            $liabilities = ChartOfAccount::where('type', 'liability')
                ->where('is_active', true)
                ->get()
                ->map(fn($a) => ['code' => $a->code, 'name' => $a->name, 'balance' => $a->getBalance()]);

            $equity = ChartOfAccount::where('type', 'equity')
                ->where('is_active', true)
                ->get()
                ->map(fn($a) => ['code' => $a->code, 'name' => $a->name, 'balance' => $a->getBalance()]);

            return response()->json([
                'date' => now()->format('Y-m-d'),
                'assets' => $assets,
                'liabilities' => $liabilities,
                'equity' => $equity,
                'total_assets' => $assets->sum('balance'),
                'total_liabilities' => $liabilities->sum('balance'),
                'total_equity' => $equity->sum('balance'),
            ]);
        });
    });
});

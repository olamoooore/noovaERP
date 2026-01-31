<?php

/**
 * PHASE 1 - Accounting Module Routes
 * 
 * Double-Entry Accounting System:
 * - Chart of Accounts (COA)
 * - Journal Entries with balance validation
 * - Trial Balance
 * - Account Ledger
 * - Auto-posting from Invoices/Payments/POS
 * 
 * Note: Core accounting routes are in Core module for now
 * This file contains accounting-specific extensions
 */

use Illuminate\Support\Facades\Route;
use App\Services\AccountingService;
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

    // Accounting Dashboard Stats
    Route::get('/accounting/dashboard', function () {
        $thisMonth = now()->startOfMonth();
        $lastMonth = now()->subMonth()->startOfMonth();

        // Get revenue/expense totals
        $revenueAccounts = ChartOfAccount::where('type', 'revenue')->pluck('id');
        $expenseAccounts = ChartOfAccount::where('type', 'expense')->pluck('id');

        return response()->json([
            'accounts' => [
                'total' => ChartOfAccount::where('is_active', true)->count(),
                'by_type' => ChartOfAccount::where('is_active', true)
                    ->selectRaw('type, COUNT(*) as count')
                    ->groupBy('type')
                    ->pluck('count', 'type'),
            ],
            'journal_entries' => [
                'this_month' => JournalEntry::where('entry_date', '>=', $thisMonth)->where('status', 'posted')->count(),
                'pending' => JournalEntry::where('status', 'draft')->count(),
            ],
            'quick_stats' => [
                'cash_balance' => ChartOfAccount::where('code', '1010')->first()?->getBalance() ?? 0,
                'bank_balance' => ChartOfAccount::where('code', '1020')->first()?->getBalance() ?? 0,
                'ar_balance' => ChartOfAccount::where('code', '1100')->first()?->getBalance() ?? 0,
                'ap_balance' => ChartOfAccount::where('code', '2100')->first()?->getBalance() ?? 0,
            ],
        ]);
    });

    // Create manual journal entry
    Route::post('/accounting/journal-entries', function () {
        $validated = request()->validate([
            'entry_date' => 'required|date',
            'description' => 'required|string',
            'reference' => 'nullable|string',
            'lines' => 'required|array|min:2',
            'lines.*.account_id' => 'required|exists:chart_of_accounts,id',
            'lines.*.debit' => 'required|numeric|min:0',
            'lines.*.credit' => 'required|numeric|min:0',
            'lines.*.description' => 'nullable|string',
        ]);

        $accountingService = app(AccountingService::class);

        try {
            $entry = $accountingService->createJournalEntry([
                'entry_date' => $validated['entry_date'],
                'type' => 'manual',
                'reference' => $validated['reference'] ?? null,
                'description' => $validated['description'],
            ], $validated['lines']);

            return response()->json($entry->load('lines.account'), 201);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    });

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

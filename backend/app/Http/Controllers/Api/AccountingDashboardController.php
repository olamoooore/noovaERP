<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\TenantCurrencyFormatter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountingDashboardController extends Controller
{
    private function formatAmount(float $amount): string
    {
        return app(TenantCurrencyFormatter::class)->format($amount);
    }

    /**
     * Get accounting dashboard metrics
     */
    public function index(Request $request)
    {
        try {
            $receivables = $this->getOutstandingReceivables();
            $payables = $this->getOutstandingPayables();
            $bankBalance = $this->getBankBalance();

            $metrics = [
                'outstanding_receivables' => $receivables,
                'outstanding_payables' => $payables,
                'bank_balance' => $bankBalance,
                'collection_coverage' => $this->calculateCollectionCoverage($receivables['amount'], $payables['amount'], $bankBalance['amount']),
                'unreconciled_items' => $this->getUnreconciledItems(),
                'recent_invoices' => $this->getRecentInvoices(),
                'recent_bills' => $this->getRecentBills(),
            ];

            return response()->json($metrics);
        } catch (\Exception $e) {
            \Log::error('Dashboard error: '.$e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'Failed to load dashboard',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Calculate collection coverage percentage with zero-division guard.
     * Collection Coverage = (Bank Balance / Outstanding Receivables) × 100
     * When AR is zero, coverage is 0% (not Infinity).
     */
    private function calculateCollectionCoverage(float $ar, float $ap, float $bankBalance): array
    {
        if ($ar <= 0) {
            return [
                'percentage' => 0.0,
                'formatted' => '0%',
            ];
        }

        $coverage = ($bankBalance / $ar) * 100;

        return [
            'percentage' => round($coverage, 2),
            'formatted' => round($coverage, 1).'%',
        ];
    }

    /**
     * Get total outstanding receivables from customer invoices.
     *
     * Only posted/sent/overdue/partial invoices with a positive outstanding balance
     * contribute to AR. Draft invoices have not been released to the GL and must
     * not appear in the AR figure.
     */
    private function getOutstandingReceivables()
    {
        $result = DB::table('invoices')
            ->whereIn('status', ['posted', 'sent', 'overdue', 'partial'])
            // Issue #96: voided/reversed/superseded invoices are not outstanding AR.
            ->whereNotIn('lifecycle_status', ['voided', 'reversed', 'superseded'])
            ->where(function ($q) {
                $q->whereNull('balance')
                    ->orWhere('balance', '>', 0);
            })
            ->selectRaw('COALESCE(SUM(COALESCE(balance, total)), 0) as total')
            ->first();

        $amount = round((float) ($result->total ?? 0), 2);

        return [
            'amount' => $amount,
            'formatted' => $this->formatAmount($amount),
        ];
    }

    /**
     * Get total outstanding payables from vendor bills.
     *
     * Only posted/overdue/partial bills with a positive outstanding balance
     * contribute to AP. Draft bills have not been released to the GL and must
     * not appear in the Payables figure.
     */
    private function getOutstandingPayables()
    {
        $result = DB::table('bills')
            ->whereIn('status', ['posted', 'overdue', 'partial'])
            // Issue #96: voided/reversed/superseded bills are not outstanding AP.
            ->whereNotIn('lifecycle_status', ['voided', 'reversed', 'superseded'])
            ->selectRaw('COALESCE(SUM(GREATEST(total - COALESCE(paid_amount, 0), 0)), 0) as total')
            ->first();

        $amount = round((float) ($result->total ?? 0), 2);

        return [
            'amount' => $amount,
            'formatted' => $this->formatAmount($amount),
        ];
    }

    /**
     * Get total bank balance across all accounts
     */
    private function getBankBalance()
    {
        $total = 0;
        if (Schema::hasTable('bank_accounts') && Schema::hasTable('journal_entry_lines')) {
            $total = DB::table('bank_accounts')
                ->join('journal_entry_lines', 'bank_accounts.gl_account_id', '=', 'journal_entry_lines.account_id')
                ->join('journal_entries', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
                ->where('bank_accounts.is_active', true)
                ->where('journal_entries.status', 'posted')
                ->selectRaw('COALESCE(SUM(journal_entry_lines.debit - journal_entry_lines.credit), 0) as total')
                ->value('total');
        }

        return [
            'amount' => round((float) $total, 2),
            'formatted' => $this->formatAmount(round((float) $total, 2)),
        ];
    }

    /**
     * Get count of unreconciled bank transactions.
     *
     * Only counts journal entry lines that are linked to a bank GL account
     * (via bank_accounts.gl_account_id) and have not yet been reconciled.
     * Counting all journal_entry_lines produces a wildly inflated number that
     * does not match what the Bank Reconciliation page actually shows.
     */
    private function getUnreconciledItems()
    {
        $count = 0;

        if (! Schema::hasTable('journal_entry_lines') || ! Schema::hasTable('bank_accounts')) {
            return ['count' => $count];
        }

        try {
            // Collect the GL account IDs that belong to active bank accounts
            $bankGlAccountIds = DB::table('bank_accounts')
                ->where('is_active', true)
                ->whereNotNull('gl_account_id')
                ->pluck('gl_account_id');

            if ($bankGlAccountIds->isEmpty()) {
                return ['count' => 0];
            }

            $query = DB::table('journal_entry_lines as jel')
                ->join('journal_entries as je', 'jel.journal_entry_id', '=', 'je.id')
                ->whereIn('jel.account_id', $bankGlAccountIds)
                ->where('je.status', 'posted')
                ->whereNull('je.deleted_at');

            if (Schema::hasColumn('journal_entry_lines', 'reconciliation_id')) {
                $query->whereNull('jel.reconciliation_id');
            } elseif (Schema::hasColumn('journal_entry_lines', 'reconciled')) {
                $query->where(function ($q) {
                    $q->whereNull('jel.reconciled')->orWhere('jel.reconciled', false);
                });
            }

            $count = $query->count();

            // Verification logging: compare with per-account service counts
            $perAccountCounts = [];
            foreach ($bankGlAccountIds as $glId) {
                $accountCount = DB::table('journal_entry_lines as jel')
                    ->join('journal_entries as je', 'jel.journal_entry_id', '=', 'je.id')
                    ->where('jel.account_id', $glId)
                    ->where('je.status', 'posted')
                    ->whereNull('je.deleted_at')
                    ->whereNull('jel.reconciliation_id')
                    ->count();
                $bankAccount = DB::table('bank_accounts')->where('gl_account_id', $glId)->first();
                if ($bankAccount) {
                    $perAccountCounts[] = [
                        'bank_account_id' => $bankAccount->id,
                        'bank_name' => $bankAccount->account_name,
                        'gl_account_id' => $glId,
                        'unreconciled_count' => $accountCount,
                    ];
                }
            }

            \Log::debug('AccountingDashboard unreconciled count', [
                'total_count' => $count,
                'bank_accounts_considered' => $bankGlAccountIds->count(),
                'per_account_breakdown' => $perAccountCounts,
            ]);
        } catch (\Exception $e) {
            \Log::warning('AccountingDashboard: failed to count unreconciled items', [
                'error' => $e->getMessage(),
            ]);
        }

        return ['count' => $count];
    }

    /**
     * Get recent customer invoices
     */
    private function getRecentInvoices()
    {
        return DB::table('invoices as i')
            ->leftJoin('customers as c', 'i.customer_id', '=', 'c.id')
            ->select(
                'i.id',
                'i.invoice_number',
                'i.invoice_date',
                'i.total',
                'i.status',
                'c.name as customer_name'
            )
            ->orderBy('i.invoice_date', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date' => $invoice->invoice_date,
                    'total_amount' => (float) $invoice->total,
                    'status' => $invoice->status,
                    'customer_name' => $invoice->customer_name ?? 'Unknown',
                ];
            });
    }

    /**
     * Get recent vendor bills
     */
    private function getRecentBills()
    {
        return DB::table('bills as b')
            ->leftJoin('vendors as v', 'b.vendor_id', '=', 'v.id')
            ->select(
                'b.id',
                'b.bill_number',
                'b.bill_date',
                'b.total',
                'b.status',
                'v.name as vendor_name'
            )
            ->orderBy('b.bill_date', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($bill) {
                return [
                    'id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'bill_date' => $bill->bill_date,
                    'total_amount' => (float) $bill->total,
                    'status' => $bill->status,
                    'vendor_name' => $bill->vendor_name ?? 'Unknown',
                ];
            });
    }
}

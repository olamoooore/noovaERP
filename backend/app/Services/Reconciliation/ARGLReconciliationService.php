<?php

namespace App\Services\Reconciliation;

use App\Models\ARTransaction;
use App\Models\JournalEntryLine;
use App\Models\ChartOfAccount;
use App\Models\ReconciliationControlResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * ARGLReconciliationService
 * 
 * Provides monthly reconciliation between AR subledger and GL control account.
 * Identifies discrepancies and generates control reports.
 *
 * Hardening notes (2026-04-15):
 *   - Fixed class name: ChartOfAccounts → ChartOfAccount
 *   - Fixed column name: posting_date → entry_date (journal_entries table)
 *   - Fixed AR control account resolution to use account_mappings table
 *   - Fixed MySQL-specific DATEDIFF / INTERVAL syntax → PostgreSQL
 *   - Fixed references to non-existent columns: journal_entry_lines.transaction_date
 *   - Fixed view column names: id → transaction_id, reference → document_number,
 *     balance → amount (ar_transactions is a VIEW, not a table)
 *   - Created missing ARTransaction Eloquent model for the ar_transactions view
 */
class ARGLReconciliationService
{
    /**
     * Run AR to GL reconciliation control
     * 
     * @param string $asOfDate Date to reconcile as of (YYYY-MM-DD)
     * @param int|null $controlAccountId Optional specific AR control account
     * @return ReconciliationControlResult
     */
    public function reconcile(string $asOfDate, ?int $controlAccountId = null): ReconciliationControlResult
    {
        $asOfDate = Carbon::parse($asOfDate);
        
        // Get AR control account if not specified
        if (!$controlAccountId) {
            $controlAccount = $this->getARControlAccount();
            $controlAccountId = $controlAccount?->id;
        }
        
        if (!$controlAccountId) {
            throw new \Exception('AR control account not found');
        }
        
        // Get subledger balance
        $subledgerBalance = $this->getSubledgerBalance($asOfDate);
        
        // Get GL control account balance
        $glBalance = $this->getGLBalance($controlAccountId, $asOfDate);
        
        // Calculate difference
        $difference = round($subledgerBalance - $glBalance, 2);
        
        // Identify discrepancies if any
        $discrepancies = [];
        if (abs($difference) > 0.01) {
            $discrepancies = $this->identifyDiscrepancies($asOfDate, $controlAccountId);
        }
        
        // Create control result record
        $result = ReconciliationControlResult::create([
            'control_name' => 'ar_gl_reconciliation',
            'as_of_date' => $asOfDate->toDateString(),
            'subledger_balance' => $subledgerBalance,
            'gl_balance' => $glBalance,
            'difference' => $difference,
            'status' => abs($difference) < 0.01 ? 'passed' : 'failed',
            'discrepancies' => $discrepancies,
            'executed_by' => auth()->id(),
            'notes' => null,
        ]);
        
        Log::info('AR to GL reconciliation completed', [
            'as_of_date' => $asOfDate->toDateString(),
            'subledger_balance' => $subledgerBalance,
            'gl_balance' => $glBalance,
            'difference' => $difference,
            'status' => $result->status
        ]);
        
        return $result;
    }
    
    /**
     * Get AR control account from chart of accounts.
     *
     * Resolution order:
     *   1. account_mappings table (mapping_key = 'accounts_receivable')
     *   2. COA code '1100' (seeded default for AR)
     *   3. Name-based fallback
     */
    private function getARControlAccount(): ?ChartOfAccount
    {
        // Prefer the configured AR mapping
        $mapping = \App\Models\AccountMapping::where('mapping_key', 'accounts_receivable')
            ->whereNull('document_type')
            ->where('is_active', true)
            ->first();

        if ($mapping) {
            return $mapping->account;
        }

        return ChartOfAccount::where('code', '1100')
            ->orWhere('name', 'like', '%Accounts Receivable%')
            ->first();
    }
    
    /**
     * Get AR subledger balance as of date
     *
     * Uses the ar_transactions view which exposes debit/credit columns.
     * For AR: balance = SUM(debit) - SUM(credit) = net receivable outstanding.
     */
    private function getSubledgerBalance(Carbon $asOfDate): float
    {
        return ARTransaction::whereIn('status', ['sent', 'posted', 'partial', 'overdue', 'paid'])
            ->where('transaction_date', '<=', $asOfDate)
            ->selectRaw('SUM(debit - credit) as balance')
            ->value('balance') ?? 0.0;
    }
    
    /**
     * Get GL control account balance as of date
     */
    private function getGLBalance(int $controlAccountId, Carbon $asOfDate): float
    {
        return JournalEntryLine::where('account_id', $controlAccountId)
            ->whereHas('journalEntry', function ($q) use ($asOfDate) {
                $q->where('entry_date', '<=', $asOfDate)
                  ->where('status', 'posted');
            })
            ->selectRaw('SUM(debit - credit) as balance')
            ->value('balance') ?? 0.0;
    }
    
    /**
     * Identify specific discrepancies between subledger and GL
     */
    private function identifyDiscrepancies(Carbon $asOfDate, int $controlAccountId): array
    {
        $discrepancies = [];
        
        // 1. Find unposted invoices
        $unpostedInvoices = $this->findUnpostedInvoices($asOfDate);
        if (!empty($unpostedInvoices)) {
            $discrepancies[] = [
                'type' => 'unposted_invoices',
                'description' => 'Invoices in subledger but not posted to GL',
                'count' => count($unpostedInvoices),
                'total_amount' => array_sum(array_column($unpostedInvoices, 'amount')),
                'items' => $unpostedInvoices,
            ];
        }
        
        // 2. Find unposted payments
        $unpostedPayments = $this->findUnpostedPayments($asOfDate);
        if (!empty($unpostedPayments)) {
            $discrepancies[] = [
                'type' => 'unposted_payments',
                'description' => 'Payments in subledger but not posted to GL',
                'count' => count($unpostedPayments),
                'total_amount' => array_sum(array_column($unpostedPayments, 'amount')),
                'items' => $unpostedPayments,
            ];
        }
        
        // 3. Find GL entries without subledger record
        $missingSubledger = $this->findGLEntriesWithoutSubledger($asOfDate, $controlAccountId);
        if (!empty($missingSubledger)) {
            $discrepancies[] = [
                'type' => 'missing_subledger',
                'description' => 'GL entries without corresponding subledger record',
                'count' => count($missingSubledger),
                'total_amount' => array_sum(array_column($missingSubledger, 'amount')),
                'items' => $missingSubledger,
            ];
        }
        
        // 4. Find timing differences (posted in different periods)
        $timingDifferences = $this->findTimingDifferences($asOfDate, $controlAccountId);
        if (!empty($timingDifferences)) {
            $discrepancies[] = [
                'type' => 'timing_differences',
                'description' => 'Transactions posted in different periods',
                'count' => count($timingDifferences),
                'items' => $timingDifferences,
            ];
        }
        
        return $discrepancies;
    }
    
    /**
     * Find invoices in subledger not posted to GL.
     *
     * View columns: transaction_id, document_number, customer_id, debit, transaction_date, status
     */
    private function findUnpostedInvoices(Carbon $asOfDate): array
    {
        return ARTransaction::where('transaction_type', 'invoice')
            ->where('transaction_date', '<=', $asOfDate)
            ->where('status', 'sent') // Not posted
            ->select('transaction_id', 'customer_id', 'document_number', 'debit as amount', 'transaction_date')
            ->limit(100)
            ->get()
            ->toArray();
    }
    
    /**
     * Find payments in subledger not posted to GL.
     *
     * We look for AR payment records whose document_number does not appear as
     * a reference on any journal_entry_line hitting the AR control account.
     */
    private function findUnpostedPayments(Carbon $asOfDate): array
    {
        return ARTransaction::where('transaction_type', 'payment')
            ->where('transaction_date', '<=', $asOfDate)
            ->where('status', 'posted')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('journal_entry_lines')
                    ->whereColumn('journal_entry_lines.reference', 'ar_transactions.document_number');
            })
            ->select('transaction_id', 'customer_id', 'document_number', 'credit as amount', 'transaction_date')
            ->limit(100)
            ->get()
            ->toArray();
    }
    
    /**
     * Find GL entries on the AR control account without a corresponding subledger record.
     *
     * Joins through journal_entries to get entry_date (journal_entry_lines has no date column).
     */
    private function findGLEntriesWithoutSubledger(Carbon $asOfDate, int $controlAccountId): array
    {
        return JournalEntryLine::where('account_id', $controlAccountId)
            ->whereHas('journalEntry', function ($q) use ($asOfDate) {
                $q->where('entry_date', '<=', $asOfDate)
                  ->where('status', 'posted');
            })
            ->where(function ($q) {
                $q->whereNull('reference')
                  ->orWhereNotExists(function ($sub) {
                      $sub->select(DB::raw(1))
                          ->from('ar_transactions')
                          ->whereColumn('ar_transactions.document_number', 'journal_entry_lines.reference');
                  });
            })
            ->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
            ->select(
                'journal_entry_lines.id',
                'journal_entry_lines.journal_entry_id',
                'journal_entry_lines.reference',
                'journal_entry_lines.debit',
                'journal_entry_lines.credit',
                'je.entry_date'
            )
            ->limit(100)
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'journal_entry_id' => $row->journal_entry_id,
                'reference' => $row->reference,
                'amount' => (float) $row->debit - (float) $row->credit,
                'debit' => $row->debit,
                'credit' => $row->credit,
                'entry_date' => $row->entry_date,
            ])
            ->toArray();
    }
    
    /**
     * Find timing differences between subledger and GL posting.
     *
     * PostgreSQL-compatible: uses (date1 - date2) which returns an integer (days).
     * Joins journal_entry_lines → journal_entries for the GL posting date.
     */
    private function findTimingDifferences(Carbon $asOfDate, int $controlAccountId): array
    {
        return DB::select("
            SELECT 
                ar.transaction_id as ar_transaction_id,
                ar.document_number,
                ar.transaction_date as ar_date,
                je.entry_date as gl_date,
                ABS(ar.transaction_date::date - je.entry_date::date) as days_difference
            FROM ar_transactions ar
            JOIN journal_entry_lines jel ON ar.document_number = jel.reference
            JOIN journal_entries je ON jel.journal_entry_id = je.id
            WHERE ar.transaction_date <= ?
                AND jel.account_id = ?
                AND je.status = 'posted'
                AND ABS(ar.transaction_date::date - je.entry_date::date) > 0
            LIMIT 100
        ", [$asOfDate->toDateString(), $controlAccountId]);
    }
    
    /**
     * Generate reconciliation report
     * 
     * @param string $asOfDate
     * @return array Report data
     */
    public function generateReport(string $asOfDate): array
    {
        $result = $this->reconcile($asOfDate);
        
        // Get aging summary
        $agingSummary = $this->getAgingSummary(Carbon::parse($asOfDate));
        
        // Get customer balances
        $customerBalances = $this->getCustomerBalances(Carbon::parse($asOfDate));
        
        return [
            'report_date' => now()->toDateTimeString(),
            'as_of_date' => $asOfDate,
            'reconciliation_result' => [
                'subledger_balance' => $result->subledger_balance,
                'gl_balance' => $result->gl_balance,
                'difference' => $result->difference,
                'status' => $result->status,
                'discrepancies_count' => count($result->discrepancies),
            ],
            'aging_summary' => $agingSummary,
            'top_customer_balances' => array_slice($customerBalances, 0, 20),
            'discrepancies' => $result->discrepancies,
        ];
    }
    
    /**
     * Get aging summary for AR.
     *
     * PostgreSQL-compatible: uses (date - INTERVAL 'N days') syntax.
     * Uses 'amount' column from the ar_transactions view (not 'balance').
     */
    private function getAgingSummary(Carbon $asOfDate): array
    {
        $date = $asOfDate->toDateString();

        return DB::select("
            SELECT 
                COALESCE(SUM(CASE WHEN due_date >= ?::date THEN amount ELSE 0 END), 0) as current,
                COALESCE(SUM(CASE WHEN due_date BETWEEN (?::date - INTERVAL '30 days') AND (?::date - INTERVAL '1 day') THEN amount ELSE 0 END), 0) as days_1_30,
                COALESCE(SUM(CASE WHEN due_date BETWEEN (?::date - INTERVAL '60 days') AND (?::date - INTERVAL '31 days') THEN amount ELSE 0 END), 0) as days_31_60,
                COALESCE(SUM(CASE WHEN due_date BETWEEN (?::date - INTERVAL '90 days') AND (?::date - INTERVAL '61 days') THEN amount ELSE 0 END), 0) as days_61_90,
                COALESCE(SUM(CASE WHEN due_date < (?::date - INTERVAL '90 days') THEN amount ELSE 0 END), 0) as days_90_plus,
                COALESCE(SUM(amount), 0) as total
            FROM ar_transactions
            WHERE status IN ('sent', 'posted', 'partial', 'overdue')
                AND transaction_type = 'invoice'
                AND transaction_date <= ?::date
        ", [$date, $date, $date, $date, $date, $date, $date, $date, $date]);
    }
    
    /**
     * Get customer balances
     */
    private function getCustomerBalances(Carbon $asOfDate): array
    {
        return ARTransaction::whereIn('status', ['sent', 'posted', 'partial', 'overdue'])
            ->where('transaction_date', '<=', $asOfDate)
            ->selectRaw('customer_id, SUM(debit - credit) as balance')
            ->groupBy('customer_id')
            ->havingRaw('ABS(SUM(debit - credit)) > 0.01')
            ->orderByDesc('balance')
            ->limit(100)
            ->with('customer:id,name')
            ->get()
            ->toArray();
    }
    
    /**
     * Get reconciliation history
     * 
     * @param int $limit Number of historical results
     * @return array
     */
    public function getHistory(int $limit = 12): array
    {
        return ReconciliationControlResult::where('control_name', 'ar_gl_reconciliation')
            ->orderByDesc('as_of_date')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}

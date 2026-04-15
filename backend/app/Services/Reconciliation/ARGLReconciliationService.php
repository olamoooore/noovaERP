<?php

namespace App\Services\Reconciliation;

use App\Models\ARTransaction;
use App\Models\JournalEntryLine;
use App\Models\ChartOfAccounts;
use App\Models\ReconciliationControlResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * ARGLReconciliationService
 * 
 * Provides monthly reconciliation between AR subledger and GL control account.
 * Identifies discrepancies and generates control reports.
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
     * Get AR control account from chart of accounts
     */
    private function getARControlAccount(): ?ChartOfAccounts
    {
        return ChartOfAccounts::where('account_type', 'ar')
            ->orWhere('account_code', '1200')
            ->orWhere('name', 'like', '%Accounts Receivable%')
            ->first();
    }
    
    /**
     * Get AR subledger balance as of date
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
                $q->where('posting_date', '<=', $asOfDate)
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
     * Find invoices in subledger not posted to GL
     */
    private function findUnpostedInvoices(Carbon $asOfDate): array
    {
        return ARTransaction::where('transaction_type', 'invoice')
            ->where('transaction_date', '<=', $asOfDate)
            ->where('status', 'sent') // Not posted
            ->select('id', 'customer_id', 'reference', 'debit as amount', 'transaction_date')
            ->limit(100)
            ->get()
            ->toArray();
    }
    
    /**
     * Find payments in subledger not posted to GL
     */
    private function findUnpostedPayments(Carbon $asOfDate): array
    {
        return ARTransaction::where('transaction_type', 'payment')
            ->where('transaction_date', '<=', $asOfDate)
            ->where('status', 'posted') // Partial status may indicate unposted
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('journal_entry_lines')
                    ->whereColumn('journal_entry_lines.reference', 'ar_transactions.reference')
                    ->whereColumn('journal_entry_lines.credit', 'ar_transactions.credit');
            })
            ->select('id', 'customer_id', 'reference', 'credit as amount', 'transaction_date')
            ->limit(100)
            ->get()
            ->toArray();
    }
    
    /**
     * Find GL entries without subledger record
     */
    private function findGLEntriesWithoutSubledger(Carbon $asOfDate, int $controlAccountId): array
    {
        return JournalEntryLine::where('account_id', $controlAccountId)
            ->whereHas('journalEntry', function ($q) use ($asOfDate) {
                $q->where('posting_date', '<=', $asOfDate)
                  ->where('status', 'posted');
            })
            ->where(function ($q) {
                $q->whereNull('reference')
                  ->orWhereNotExists(function ($sub) {
                      $sub->select(DB::raw(1))
                          ->from('ar_transactions')
                          ->whereColumn('ar_transactions.reference', 'journal_entry_lines.reference');
                  });
            })
            ->select('id', 'journal_entry_id', 'reference', 'debit', 'credit', 'transaction_date')
            ->limit(100)
            ->get()
            ->toArray();
    }
    
    /**
     * Find timing differences between subledger and GL posting
     */
    private function findTimingDifferences(Carbon $asOfDate, int $controlAccountId): array
    {
        // Find AR transactions posted to GL in different period
        return DB::select("
            SELECT 
                ar.id as ar_transaction_id,
                ar.reference,
                ar.transaction_date as ar_date,
                jel.transaction_date as gl_date,
                ABS(DATEDIFF(ar.transaction_date, jel.transaction_date)) as days_difference
            FROM ar_transactions ar
            JOIN journal_entry_lines jel ON ar.reference = jel.reference
            JOIN journal_entries je ON jel.journal_entry_id = je.id
            WHERE ar.transaction_date <= ?
                AND jel.account_id = ?
                AND je.status = 'posted'
                AND ABS(DATEDIFF(ar.transaction_date, jel.transaction_date)) > 0
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
        $agingSummary = $this->getAgingSummary($asOfDate);
        
        // Get customer balances
        $customerBalances = $this->getCustomerBalances($asOfDate);
        
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
     * Get aging summary for AR
     */
    private function getAgingSummary(Carbon $asOfDate): array
    {
        return DB::select("
            SELECT 
                SUM(CASE WHEN due_date >= ? THEN balance ELSE 0 END) as current,
                SUM(CASE WHEN due_date BETWEEN ? - INTERVAL 30 DAY AND ? - INTERVAL 1 DAY THEN balance ELSE 0 END) as days_1_30,
                SUM(CASE WHEN due_date BETWEEN ? - INTERVAL 60 DAY AND ? - INTERVAL 31 DAY THEN balance ELSE 0 END) as days_31_60,
                SUM(CASE WHEN due_date BETWEEN ? - INTERVAL 90 DAY AND ? - INTERVAL 61 DAY THEN balance ELSE 0 END) as days_61_90,
                SUM(CASE WHEN due_date < ? - INTERVAL 90 DAY THEN balance ELSE 0 END) as days_90_plus,
                SUM(balance) as total
            FROM ar_transactions
            WHERE status IN ('sent', 'posted', 'partial', 'overdue')
                AND transaction_date <= ?
        ", array_fill(0, 11, $asOfDate->toDateString()));
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
            ->having('balance', '!=', 0)
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
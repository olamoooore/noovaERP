<?php

namespace App\Services\Reconciliation;

use App\Models\APTransaction;
use App\Models\JournalEntryLine;
use App\Models\ChartOfAccounts;
use App\Models\ReconciliationControlResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * APGLReconciliationService
 * 
 * Provides monthly reconciliation between AP subledger and GL control account.
 * Identifies discrepancies and generates control reports.
 */
class APGLReconciliationService
{
    /**
     * Run AP to GL reconciliation control
     * 
     * @param string $asOfDate Date to reconcile as of (YYYY-MM-DD)
     * @param int|null $controlAccountId Optional specific AP control account
     * @return ReconciliationControlResult
     */
    public function reconcile(string $asOfDate, ?int $controlAccountId = null): ReconciliationControlResult
    {
        $asOfDate = Carbon::parse($asOfDate);
        
        // Get AP control account if not specified
        if (!$controlAccountId) {
            $controlAccount = $this->getAPControlAccount();
            $controlAccountId = $controlAccount?->id;
        }
        
        if (!$controlAccountId) {
            throw new \Exception('AP control account not found');
        }
        
        // Get subledger balance (credit - debit for AP)
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
            'control_name' => 'ap_gl_reconciliation',
            'as_of_date' => $asOfDate->toDateString(),
            'subledger_balance' => $subledgerBalance,
            'gl_balance' => $glBalance,
            'difference' => $difference,
            'status' => abs($difference) < 0.01 ? 'passed' : 'failed',
            'discrepancies' => $discrepancies,
            'executed_by' => auth()->id(),
            'notes' => null,
        ]);
        
        Log::info('AP to GL reconciliation completed', [
            'as_of_date' => $asOfDate->toDateString(),
            'subledger_balance' => $subledgerBalance,
            'gl_balance' => $glBalance,
            'difference' => $difference,
            'status' => $result->status
        ]);
        
        return $result;
    }
    
    /**
     * Get AP control account from chart of accounts
     */
    private function getAPControlAccount(): ?ChartOfAccounts
    {
        return ChartOfAccounts::where('account_type', 'ap')
            ->orWhere('account_code', '2000')
            ->orWhere('name', 'like', '%Accounts Payable%')
            ->first();
    }
    
    /**
     * Get AP subledger balance as of date
     * Note: For AP, balance = credit - debit (liability)
     */
    private function getSubledgerBalance(Carbon $asOfDate): float
    {
        return APTransaction::whereIn('status', ['open', 'approved', 'partial', 'overdue'])
            ->where('transaction_date', '<=', $asOfDate)
            ->selectRaw('SUM(credit - debit) as balance')
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
            ->selectRaw('SUM(credit - debit) as balance')
            ->value('balance') ?? 0.0;
    }
    
    /**
     * Identify specific discrepancies between subledger and GL
     */
    private function identifyDiscrepancies(Carbon $asOfDate, int $controlAccountId): array
    {
        $discrepancies = [];
        
        // 1. Find unposted bills
        $unpostedBills = $this->findUnpostedBills($asOfDate);
        if (!empty($unpostedBills)) {
            $discrepancies[] = [
                'type' => 'unposted_bills',
                'description' => 'Bills in subledger but not posted to GL',
                'count' => count($unpostedBills),
                'total_amount' => array_sum(array_column($unpostedBills, 'amount')),
                'items' => $unpostedBills,
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
        
        // 4. Find timing differences
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
     * Find bills in subledger not posted to GL
     */
    private function findUnpostedBills(Carbon $asOfDate): array
    {
        return APTransaction::where('transaction_type', 'bill')
            ->where('transaction_date', '<=', $asOfDate)
            ->where('status', 'open') // Not posted
            ->select('id', 'vendor_id', 'reference', 'credit as amount', 'transaction_date')
            ->limit(100)
            ->get()
            ->toArray();
    }
    
    /**
     * Find payments in subledger not posted to GL
     */
    private function findUnpostedPayments(Carbon $asOfDate): array
    {
        return APTransaction::where('transaction_type', 'payment')
            ->where('transaction_date', '<=', $asOfDate)
            ->where('status', 'partial')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('journal_entry_lines')
                    ->whereColumn('journal_entry_lines.reference', 'ap_transactions.reference')
                    ->whereColumn('journal_entry_lines.debit', 'ap_transactions.debit');
            })
            ->select('id', 'vendor_id', 'reference', 'debit as amount', 'transaction_date')
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
                          ->from('ap_transactions')
                          ->whereColumn('ap_transactions.reference', 'journal_entry_lines.reference');
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
        return DB::select("
            SELECT 
                ap.id as ap_transaction_id,
                ap.reference,
                ap.transaction_date as ap_date,
                jel.transaction_date as gl_date,
                ABS(DATEDIFF(ap.transaction_date, jel.transaction_date)) as days_difference
            FROM ap_transactions ap
            JOIN journal_entry_lines jel ON ap.reference = jel.reference
            JOIN journal_entries je ON jel.journal_entry_id = je.id
            WHERE ap.transaction_date <= ?
                AND jel.account_id = ?
                AND je.status = 'posted'
                AND ABS(DATEDIFF(ap.transaction_date, jel.transaction_date)) > 0
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
        
        // Get vendor balances
        $vendorBalances = $this->getVendorBalances($asOfDate);
        
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
            'top_vendor_balances' => array_slice($vendorBalances, 0, 20),
            'discrepancies' => $result->discrepancies,
        ];
    }
    
    /**
     * Get aging summary for AP
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
            FROM ap_transactions
            WHERE status IN ('open', 'approved', 'partial', 'overdue')
                AND transaction_date <= ?
        ", array_fill(0, 11, $asOfDate->toDateString()));
    }
    
    /**
     * Get vendor balances
     */
    private function getVendorBalances(Carbon $asOfDate): array
    {
        return APTransaction::whereIn('status', ['open', 'approved', 'partial', 'overdue'])
            ->where('transaction_date', '<=', $asOfDate)
            ->selectRaw('vendor_id, SUM(credit - debit) as balance')
            ->groupBy('vendor_id')
            ->having('balance', '!=', 0)
            ->orderByDesc('balance')
            ->limit(100)
            ->with('vendor:id,name')
            ->get()
            ->toArray();
    }
}
<?php

namespace App\Services;

use App\Models\BankReconciliation;
use App\Models\ReconciliationItem;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Bank Reconciliation Service
 * Handles bank reconciliation process and transaction management
 */
class BankReconciliationService
{
    /**
     * Get unreconciled transactions for a bank account
     * 
     * @param int $bankAccountId
     * @param array $filters Optional filters (status, search)
     * @return array Transactions with opening balance
     */
    public function getUnreconciledTransactions(int $bankAccountId, array $filters = []): array
    {
        // Get the bank account
        $bankAccount = ChartOfAccount::findOrFail($bankAccountId);
        
        // Get the last reconciliation for this account
        $lastReconciliation = BankReconciliation::where('bank_account_id', $bankAccountId)
            ->where('status', 'completed')
            ->orderBy('statement_date', 'desc')
            ->first();
        
        $openingBalance = $lastReconciliation ? (float) $lastReconciliation->statement_balance : (float) $bankAccount->opening_balance;
        
        // Query journal entry lines for this bank account
        $query = JournalEntryLine::with(['journalEntry', 'account'])
            ->where('account_id', $bankAccountId)
            ->whereHas('journalEntry', function ($q) {
                $q->where('status', 'posted');
            });
        
        // Filter by reconciliation status
        if (isset($filters['status'])) {
            if ($filters['status'] === 'reconciled') {
                $query->whereNotNull('reconciliation_id');
            } elseif ($filters['status'] === 'unreconciled') {
                $query->whereNull('reconciliation_id');
            }
        } else {
            // Default: show only unreconciled
            $query->whereNull('reconciliation_id');
        }
        
        // Apply search filter
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('description', 'ILIKE', "%{$search}%")
                  ->orWhere('reference', 'ILIKE', "%{$search}%")
                  ->orWhereHas('journalEntry', function ($jeQuery) use ($search) {
                      $jeQuery->where('description', 'ILIKE', "%{$search}%")
                              ->orWhere('reference', 'ILIKE', "%{$search}%");
                  });
            });
        }
        
        // Get transactions ordered by date
        $lines = $query->orderBy('created_at', 'asc')->get();
        
        // Format transactions
        $transactions = [];
        foreach ($lines as $line) {
            $transactions[] = [
                'id' => $line->id,
                'date' => $line->journalEntry->entry_date->format('Y-m-d'),
                'description' => $line->description ?: $line->journalEntry->description,
                'reference' => $line->reference ?: $line->journalEntry->reference,
                'debit' => round((float) $line->debit, 2),
                'credit' => round((float) $line->credit, 2),
                'reconciled' => !is_null($line->reconciliation_id),
                'reconciliation_date' => $line->reconciliation_id ? 
                    ($line->reconciliation ? $line->reconciliation->statement_date->format('Y-m-d') : null) : 
                    null,
            ];
        }
        
        return [
            'data' => $transactions,
            'opening_balance' => round($openingBalance, 2),
        ];
    }
    
    /**
     * Complete reconciliation
     * 
     * @param array $data Reconciliation data
     * @return BankReconciliation
     * @throws \Exception
     */
    public function completeReconciliation(array $data): BankReconciliation
    {
        return DB::transaction(function () use ($data) {
            $bankAccountId = $data['bank_account_id'];
            $statementDate = Carbon::parse($data['statement_date']);
            $statementBalance = (float) $data['statement_balance'];
            $reconciledTransactionIds = $data['reconciled_transaction_ids'] ?? [];
            
            // Get opening balance
            $lastReconciliation = BankReconciliation::where('bank_account_id', $bankAccountId)
                ->where('status', 'completed')
                ->orderBy('statement_date', 'desc')
                ->first();
            
            $bankAccount = ChartOfAccount::findOrFail($bankAccountId);
            $openingBalance = $lastReconciliation ? 
                (float) $lastReconciliation->statement_balance : 
                (float) $bankAccount->opening_balance;
            
            // Calculate reconciled balance
            $reconciledBalance = $this->calculateReconciledBalance(
                $openingBalance,
                $reconciledTransactionIds
            );
            
            // Calculate difference
            $difference = $reconciledBalance - $statementBalance;
            
            // Create reconciliation record
            $reconciliation = BankReconciliation::create([
                'bank_account_id' => $bankAccountId,
                'statement_date' => $statementDate,
                'statement_balance' => $statementBalance,
                'opening_balance' => $openingBalance,
                'closing_balance' => $reconciledBalance,
                'difference' => $difference,
                'status' => 'draft',
            ]);
            
            // Create reconciliation items
            foreach ($reconciledTransactionIds as $transactionId) {
                ReconciliationItem::create([
                    'reconciliation_id' => $reconciliation->id,
                    'journal_entry_line_id' => $transactionId,
                ]);
            }
            
            // Update journal entry lines with reconciliation reference
            JournalEntryLine::whereIn('id', $reconciledTransactionIds)
                ->update([
                    'reconciliation_id' => $reconciliation->id,
                    'reconciliation_date' => $statementDate,
                ]);
            
            // Mark as completed if balanced
            if ($reconciliation->isBalanced()) {
                $reconciliation->complete(auth()->id());
            }
            
            return $reconciliation->fresh();
        });
    }
    
    /**
     * Add adjusting entry during reconciliation
     * 
     * @param array $data Transaction data
     * @return JournalEntryLine
     * @throws \Exception
     */
    public function addAdjustingEntry(array $data): JournalEntryLine
    {
        return DB::transaction(function () use ($data) {
            $bankAccountId = $data['bank_account_id'];
            $date = Carbon::parse($data['date']);
            $description = $data['description'];
            $amount = (float) $data['amount'];
            $type = $data['type']; // 'debit' or 'credit'
            
            // Get bank account
            $bankAccount = ChartOfAccount::findOrFail($bankAccountId);
            
            // Determine contra account (typically a bank fees or miscellaneous expense account)
            // For now, we'll use a generic expense account or allow it to be specified
            $contraAccountId = $data['contra_account_id'] ?? null;
            
            if (!$contraAccountId) {
                // Try to find a bank fees or miscellaneous expense account
                $contraAccount = ChartOfAccount::where('type', 'expense')
                    ->where('name', 'ILIKE', '%bank%fee%')
                    ->orWhere('name', 'ILIKE', '%miscellaneous%')
                    ->first();
                
                if (!$contraAccount) {
                    throw new \Exception('Contra account not specified and no default bank fees account found');
                }
                
                $contraAccountId = $contraAccount->id;
            }
            
            // Create journal entry
            $journalEntry = JournalEntry::create([
                'entry_number' => 'ADJ-' . now()->format('YmdHis'),
                'entry_date' => $date,
                'type' => 'adjusting',
                'reference' => $data['reference'] ?? null,
                'description' => $description,
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]);
            
            // Create journal entry lines
            if ($type === 'debit') {
                // Debit bank account, credit contra account
                $bankLine = JournalEntryLine::create([
                    'journal_entry_id' => $journalEntry->id,
                    'account_id' => $bankAccountId,
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => $description,
                    'reference' => $data['reference'] ?? null,
                ]);
                
                JournalEntryLine::create([
                    'journal_entry_id' => $journalEntry->id,
                    'account_id' => $contraAccountId,
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => $description,
                    'reference' => $data['reference'] ?? null,
                ]);
            } else {
                // Credit bank account, debit contra account
                $bankLine = JournalEntryLine::create([
                    'journal_entry_id' => $journalEntry->id,
                    'account_id' => $bankAccountId,
                    'debit' => 0,
                    'credit' => $amount,
                    'description' => $description,
                    'reference' => $data['reference'] ?? null,
                ]);
                
                JournalEntryLine::create([
                    'journal_entry_id' => $journalEntry->id,
                    'account_id' => $contraAccountId,
                    'debit' => $amount,
                    'credit' => 0,
                    'description' => $description,
                    'reference' => $data['reference'] ?? null,
                ]);
            }
            
            // Post the journal entry
            $journalEntry->post();
            
            return $bankLine->fresh();
        });
    }
    
    /**
     * Calculate reconciled balance
     * 
     * @param float $openingBalance
     * @param array $reconciledTransactionIds
     * @return float
     */
    public function calculateReconciledBalance(float $openingBalance, array $reconciledTransactionIds): float
    {
        if (empty($reconciledTransactionIds)) {
            return $openingBalance;
        }
        
        $transactions = JournalEntryLine::whereIn('id', $reconciledTransactionIds)->get();
        
        $balance = $openingBalance;
        foreach ($transactions as $transaction) {
            $balance += (float) $transaction->debit - (float) $transaction->credit;
        }
        
        return round($balance, 2);
    }
}

<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
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
    public function __construct(
        private PostingEngineInterface $postingEngine,
    ) {}
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
            
            // Determine contra account
            $contraAccountId = $data['contra_account_id'] ?? null;
            
            if (!$contraAccountId) {
                $contraAccount = ChartOfAccount::where('type', 'expense')
                    ->where('name', 'ILIKE', '%bank%fee%')
                    ->orWhere('name', 'ILIKE', '%miscellaneous%')
                    ->first();
                
                if (!$contraAccount) {
                    throw new \Exception('Contra account not specified and no default bank fees account found');
                }
                
                $contraAccountId = $contraAccount->id;
            }
            
            // Build posting lines
            $lines = [];

            if ($type === 'debit') {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'bank',
                    accountId: $bankAccountId,
                    debit: $amount,
                    credit: 0,
                    description: $description,
                    reference: $data['reference'] ?? null,
                );
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'general_expense',
                    accountId: $contraAccountId,
                    debit: 0,
                    credit: $amount,
                    description: $description,
                    reference: $data['reference'] ?? null,
                );
            } else {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'bank',
                    accountId: $bankAccountId,
                    debit: 0,
                    credit: $amount,
                    description: $description,
                    reference: $data['reference'] ?? null,
                );
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'general_expense',
                    accountId: $contraAccountId,
                    debit: $amount,
                    credit: 0,
                    description: $description,
                    reference: $data['reference'] ?? null,
                );
            }

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'bank_adjustment',
                documentId: $bankAccountId,
                postingDate: $date->format('Y-m-d'),
                description: $description,
                lines: $lines,
                reference: $data['reference'] ?? 'ADJ-' . now()->format('YmdHis'),
                userId: auth()->id(),
            ));

            if (!$result->success) {
                throw new \Exception($result->error);
            }

            // Return the bank account line from the posted entry
            $bankLine = $result->journalEntry->lines
                ->where('account_id', $bankAccountId)
                ->first();
            
            return $bankLine;
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

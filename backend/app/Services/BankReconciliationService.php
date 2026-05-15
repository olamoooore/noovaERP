<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\ReconciliationItem;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

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
        $bankAccount = BankAccount::with('glAccount')->findOrFail($bankAccountId);
        $glAccount = $bankAccount->glAccount;

        if (!$glAccount) {
            throw new \RuntimeException('Bank account is not linked to a GL account.');
        }
        
        // Get the last reconciliation for this account
        $lastReconciliation = BankReconciliation::where('bank_account_id', $bankAccountId)
            ->where('status', 'completed')
            ->orderBy('statement_date', 'desc')
            ->first();
        
        $openingBalance = $lastReconciliation
            ? (float) $lastReconciliation->statement_balance
            : (float) $glAccount->opening_balance;
        
        // Query journal entry lines for this bank account
        $query = JournalEntryLine::with(['journalEntry', 'account'])
            ->where('account_id', $bankAccount->gl_account_id)
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
        
        // Order by financial date (entry_date), then by line ID for stability.
        // Ordering by created_at was a bug — data entry order ≠ financial order.
        $lines = $query
            ->join('journal_entries as je', 'je.id', '=', 'journal_entry_lines.journal_entry_id')
            ->orderBy('je.entry_date', 'asc')
            ->orderBy('journal_entry_lines.id', 'asc')
            ->select('journal_entry_lines.*')
            ->get();
        
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
            'bank_account_id' => $bankAccount->id,
            'gl_account_id' => $bankAccount->gl_account_id,
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
            
            $bankAccount = BankAccount::with('glAccount')->findOrFail($bankAccountId);
            $glAccount = $bankAccount->glAccount;

            if (!$glAccount) {
                throw new \RuntimeException('Bank account is not linked to a GL account.');
            }

            $openingBalance = array_key_exists('opening_balance', $data)
                ? (float) $data['opening_balance']
                : ($lastReconciliation
                    ? (float) $lastReconciliation->statement_balance
                    : (float) $glAccount->opening_balance);
            
            // Workstream 2 (2026-04-15): Verify lines belong to this bank account's GL
            // BEFORE calculating balance or marking anything reconciled
            if (!empty($reconciledTransactionIds)) {
                $this->verifyLinesBelongToBankAccount(
                    $reconciledTransactionIds,
                    $bankAccount->gl_account_id
                );
            }
            
            // Calculate reconciled balance
            $reconciledBalance = $this->calculateReconciledBalance(
                $openingBalance,
                $reconciledTransactionIds
            );
            
            // Calculate difference
            $difference = $reconciledBalance - $statementBalance;
            
            // Workstream 2 (2026-04-15): Only mark lines reconciled if balanced
            // Check balance BEFORE marking any lines
            $isBalanced = abs($difference) < 0.01;
            
            if (!$isBalanced) {
                throw new \RuntimeException(
                    sprintf(
                        'Reconciliation is not balanced. Calculated balance: %.2f, Statement balance: %.2f, Difference: %.2f. ' .
                        'Cannot mark lines as reconciled until reconciliation is balanced.',
                        $reconciledBalance,
                        $statementBalance,
                        $difference
                    )
                );
            }
            
            // Create reconciliation record
            $reconciliation = BankReconciliation::create([
                'bank_account_id' => $bankAccount->id,
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
            
            // Workstream 2 (2026-04-15): Only mark lines reconciled AFTER balance check passes
            // Update journal entry lines with reconciliation reference
            JournalEntryLine::whereIn('id', $reconciledTransactionIds)
                ->update([
                    'reconciliation_id' => $reconciliation->id,
                    'reconciliation_date' => $statementDate,
                ]);
            
            // Mark as completed since balanced
            $reconciliation->complete(TenantUserHelper::resolveTenantUserId());
            
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
            $bankAccount = BankAccount::with('glAccount')->findOrFail($bankAccountId);
            $glAccount = $bankAccount->glAccount;

            if (!$glAccount) {
                throw new \RuntimeException('Bank account is not linked to a GL account.');
            }
            
            // contra_account_id is mandatory — heuristic name-based lookup was removed
            // because it could silently select the wrong account (e.g. any account
            // whose name contains 'bank' or 'miscellaneous').
            $contraAccountId = $data['contra_account_id'] ?? null;

            if (!$contraAccountId) {
                throw new \InvalidArgumentException(
                    'contra_account_id is required for adjusting entries. ' .
                    'Specify the GL account to use as the offsetting entry (e.g. bank fees expense, interest income).'
                );
            }

            // Validate the contra account exists
            $contraExists = ChartOfAccount::where('id', $contraAccountId)->exists();
            if (!$contraExists) {
                throw new \InvalidArgumentException(
                    "contra_account_id {$contraAccountId} does not exist in the chart of accounts."
                );
            }

            if ((int) $contraAccountId === (int) $glAccount->id) {
                throw new \InvalidArgumentException(
                    'contra_account_id cannot be the same as the bank account GL account.'
                );
            }
            
            // Build posting lines
            $lines = [];

            if ($type === 'debit') {
                $lines[] = new PostingRequestLine(
                    accountMappingKey: 'bank',
                    accountId: $glAccount->id,
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
                    accountId: $glAccount->id,
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
                userId: TenantUserHelper::resolveTenantUserId(),
            ));

            if (!$result->success) {
                throw new \Exception($result->error);
            }

            // Return the bank account line from the posted entry
            $bankLine = $result->journalEntry->lines
                ->where('account_id', $glAccount->id)
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

    /**
     * Verify that all journal entry lines belong to the specified bank account GL.
     * 
     * Workstream 2 (2026-04-15): Security check to prevent reconciling lines
     * from wrong accounts.
     * 
     * @param array $lineIds Journal entry line IDs to verify
     * @param int $expectedGlAccountId The GL account ID that lines must belong to
     * @throws \InvalidArgumentException If any line belongs to a different GL account
     */
    private function verifyLinesBelongToBankAccount(array $lineIds, int $expectedGlAccountId): void
    {
        if (empty($lineIds)) {
            return;
        }

        $lines = JournalEntryLine::whereIn('id', $lineIds)
            ->with('account')
            ->get();

        $wrongLines = $lines->filter(function ($line) use ($expectedGlAccountId) {
            return (int) $line->account_id !== $expectedGlAccountId;
        });

        if ($wrongLines->isNotEmpty()) {
            $wrongIds = $wrongLines->pluck('id')->implode(', ');
            $wrongAccounts = $wrongLines->map(function ($line) {
                return $line->account ? $line->account->code : 'unknown';
            })->unique()->implode(', ');

            throw new \InvalidArgumentException(
                sprintf(
                    'Cannot reconcile: %d line(s) (IDs: %s) belong to GL account(s) [%s] instead of expected bank account GL (ID: %d). ' .
                    'All reconciled lines must belong to the same bank account GL.',
                    $wrongLines->count(),
                    $wrongIds,
                    $wrongAccounts,
                    $expectedGlAccountId
                )
            );
        }

        // Also verify lines are not already reconciled
        $alreadyReconciled = $lines->filter(function ($line) {
            return $line->reconciliation_id !== null;
        });

        if ($alreadyReconciled->isNotEmpty()) {
            $reconciledIds = $alreadyReconciled->pluck('id')->implode(', ');
            throw new \InvalidArgumentException(
                sprintf(
                    'Cannot reconcile: %d line(s) (IDs: %s) are already reconciled.',
                    $alreadyReconciled->count(),
                    $reconciledIds
                )
            );
        }
    }
}

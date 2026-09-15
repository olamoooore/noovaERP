<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Services\AccountingAccessService;
use App\Services\BankReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Bank Reconciliation Controller
 * Handles bank reconciliation endpoints
 */
class BankReconciliationController extends Controller
{
    public function __construct(
        private BankReconciliationService $bankReconciliationService,
        private AccountingAccessService $accessService,
    ) {}

    /**
     * Get list of bank accounts available for reconciliation
     */
    public function getBankAccounts(): JsonResponse
    {
        try {
            $bankAccounts = BankAccount::with('glAccount')
                ->where('is_active', true)
                ->get();

            $data = [];
            foreach ($bankAccounts as $account) {
                // Get last reconciliation
                $lastReconciliation = BankReconciliation::where('bank_account_id', $account->id)
                    ->where('status', 'completed')
                    ->orderBy('statement_date', 'desc')
                    ->first();

                $data[] = [
                    'id' => $account->id,
                    'name' => $account->account_name,
                    'account_number' => $account->account_number,
                    'bank_name' => $account->bank_name,
                    'currency_code' => $account->currency_code,
                    'gl_account_id' => $account->gl_account_id,
                    'gl_account_name' => $account->glAccount?->name,
                    'last_reconciliation_date' => $lastReconciliation ?
                        $lastReconciliation->statement_date->format('Y-m-d') : null,
                    'last_reconciliation_balance' => $lastReconciliation ?
                        round((float) $lastReconciliation->statement_balance, 2) : null,
                    'current_balance' => round($account->getCurrentBalance(), 2),
                ];
            }

            return response()->json([
                'data' => $data,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve bank accounts',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get unreconciled transactions for a bank account
     *
     * @param  int  $accountId
     */
    public function getTransactions(Request $request, int $bankAccountId): JsonResponse
    {
        try {
            // Validate request parameters
            $validated = $request->validate([
                'status' => 'nullable|string|in:all,reconciled,unreconciled',
                'search' => 'nullable|string|max:255',
            ]);

            // Get transactions
            $result = $this->bankReconciliationService->getUnreconciledTransactions(
                $bankAccountId,
                $validated
            );

            return response()->json($result);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Bank account not found',
            ], 404);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve transactions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Complete bank reconciliation
     */
    public function complete(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user || ! $this->accessService->canCompleteReconciliation($user)) {
            return response()->json([
                'message' => 'You do not have permission to complete reconciliation.',
                'required_permission' => 'reconciliation.complete',
            ], 403);
        }

        try {
            // Validate request
            $validated = $request->validate([
                'bank_account_id' => 'required|integer|exists:bank_accounts,id',
                'statement_date' => 'required|date',
                'statement_balance' => 'required|numeric',
                'opening_balance' => 'nullable|numeric',
                'reconciled_transaction_ids' => 'required|array',
                'reconciled_transaction_ids.*' => 'integer|exists:journal_entry_lines,id',
            ]);

            \Log::info('Bank reconciliation complete request received', [
                'bank_account_id' => $validated['bank_account_id'],
                'statement_date' => $validated['statement_date'],
                'transaction_count' => count($validated['reconciled_transaction_ids']),
            ]);

            // Complete reconciliation
            $reconciliation = $this->bankReconciliationService->completeReconciliation($validated);

            \Log::info('Bank reconciliation complete request succeeded', [
                'reconciliation_id' => $reconciliation->id,
                'status' => $reconciliation->status,
            ]);

            return response()->json([
                'message' => 'Reconciliation completed successfully',
                'data' => [
                    'id' => $reconciliation->id,
                    'bank_account_id' => $reconciliation->bank_account_id,
                    'statement_date' => $reconciliation->statement_date->format('Y-m-d'),
                    'statement_balance' => round((float) $reconciliation->statement_balance, 2),
                    'opening_balance' => round((float) $reconciliation->opening_balance, 2),
                    'closing_balance' => round((float) $reconciliation->closing_balance, 2),
                    'difference' => round((float) $reconciliation->difference, 2),
                    'status' => $reconciliation->status,
                    'is_balanced' => $reconciliation->isBalanced(),
                ],
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            \Log::error('Bank reconciliation complete request failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to complete reconciliation',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add adjusting entry during reconciliation
     */
    public function addTransaction(Request $request): JsonResponse
    {
        $user = Auth::user();
        if (! $user || ! $this->accessService->canCompleteReconciliation($user)) {
            return response()->json([
                'message' => 'You do not have permission to add reconciliation transactions.',
                'required_permission' => 'reconciliation.complete',
            ], 403);
        }

        try {
            // Validate request
            $validated = $request->validate([
                'bank_account_id' => 'required|integer|exists:bank_accounts,id',
                'date' => 'required|date',
                'description' => 'required|string|max:255',
                'amount' => 'required|numeric|min:0.01',
                'type' => 'required|string|in:debit,credit',
                'reference' => 'nullable|string|max:255',
                'contra_account_id' => 'required|integer|exists:chart_of_accounts,id',
            ]);

            // Add adjusting entry
            $transaction = $this->bankReconciliationService->addAdjustingEntry($validated);

            return response()->json([
                'message' => 'Adjusting entry added successfully',
                'data' => [
                    'id' => $transaction->id,
                    'date' => $transaction->journalEntry->entry_date->format('Y-m-d'),
                    'description' => $transaction->description,
                    'reference' => $transaction->reference,
                    'debit' => round((float) $transaction->debit, 2),
                    'credit' => round((float) $transaction->credit, 2),
                    'reconciled' => false,
                ],
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);

        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => 'Failed to add adjusting entry',
                'error' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to add adjusting entry',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get reconciliation history for a bank account
     *
     * @param  int  $accountId
     */
    public function history(int $bankAccountId): JsonResponse
    {
        try {
            \Log::info('Fetching reconciliation history', [
                'bank_account_id' => $bankAccountId,
                'tenant_connection' => \DB::connection()->getDatabaseName(),
                'search_path' => \DB::select('SHOW search_path')[0]->search_path ?? 'unknown',
            ]);

            $allForAccount = BankReconciliation::where('bank_account_id', $bankAccountId)->get();
            \Log::info('All reconciliations for account', [
                'bank_account_id' => $bankAccountId,
                'total_count' => $allForAccount->count(),
                'statuses' => $allForAccount->pluck('status')->toArray(),
                'ids' => $allForAccount->pluck('id')->toArray(),
            ]);

            $reconciliations = BankReconciliation::with(['bankAccount', 'reversal'])
                ->where('bank_account_id', $bankAccountId)
                ->where('status', 'completed')
                ->orderBy('statement_date', 'desc')
                ->get();

            \Log::info('Completed reconciliations for account', [
                'bank_account_id' => $bankAccountId,
                'completed_count' => $reconciliations->count(),
            ]);

            $data = [];
            foreach ($reconciliations as $reconciliation) {
                $data[] = [
                    'id' => $reconciliation->id,
                    'statement_date' => $reconciliation->statement_date->format('Y-m-d'),
                    'statement_balance' => round((float) $reconciliation->statement_balance, 2),
                    'opening_balance' => round((float) $reconciliation->opening_balance, 2),
                    'closing_balance' => round((float) $reconciliation->closing_balance, 2),
                    'difference' => round((float) $reconciliation->difference, 2),
                    'bank_account_name' => $reconciliation->bankAccount?->account_name,
                    'reconciled_at' => $reconciliation->reconciled_at ?
                        $reconciliation->reconciled_at->format('Y-m-d H:i:s') : null,
                    'reconciled_by' => $reconciliation->reconciledBy ?
                        $reconciliation->reconciledBy->name : null,
                    'reversed' => $reconciliation->reversal?->status === 'reversed',
                    'reversal_id' => $reconciliation->reversal?->id,
                    'reversal_status' => $reconciliation->reversal?->status,
                    'reversal_requested_at' => $reconciliation->reversal?->requested_at?->format('Y-m-d H:i:s'),
                    'reversed_at' => $reconciliation->reversal?->reversed_at?->format('Y-m-d H:i:s'),
                    'reversal_reason' => $reconciliation->reversal?->reason,
                ];
            }

            return response()->json([
                'data' => $data,
            ]);

        } catch (\Exception $e) {
            \Log::error('Failed to retrieve reconciliation history', [
                'bank_account_id' => $bankAccountId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Failed to retrieve reconciliation history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

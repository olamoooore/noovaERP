<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BankReconciliationService;
use App\Models\ChartOfAccount;
use App\Models\BankReconciliation;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Bank Reconciliation Controller
 * Handles bank reconciliation endpoints
 */
class BankReconciliationController extends Controller
{
    public function __construct(
        private BankReconciliationService $bankReconciliationService
    ) {}

    /**
     * Get list of bank accounts available for reconciliation
     * 
     * @return JsonResponse
     */
    public function getBankAccounts(): JsonResponse
    {
        try {
            // Get all bank accounts (asset accounts with 'bank' in name or subtype)
            $bankAccounts = ChartOfAccount::where('type', 'asset')
                ->where('is_active', true)
                ->where(function($query) {
                    $query->where('subtype', 'ILIKE', '%bank%')
                          ->orWhere('name', 'ILIKE', '%bank%')
                          ->orWhere('code', 'ILIKE', '1020%'); // Common bank account code range
                })
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
                    'name' => $account->name,
                    'account_number' => $account->code,
                    'last_reconciliation_date' => $lastReconciliation ? 
                        $lastReconciliation->statement_date->format('Y-m-d') : null,
                    'last_reconciliation_balance' => $lastReconciliation ? 
                        round((float) $lastReconciliation->statement_balance, 2) : null,
                    'current_balance' => round($account->getBalance(), 2),
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
     * @param Request $request
     * @param int $accountId
     * @return JsonResponse
     */
    public function getTransactions(Request $request, int $accountId): JsonResponse
    {
        try {
            // Validate request parameters
            $validated = $request->validate([
                'status' => 'nullable|string|in:all,reconciled,unreconciled',
                'search' => 'nullable|string|max:255',
            ]);

            // Get transactions
            $result = $this->bankReconciliationService->getUnreconciledTransactions(
                $accountId,
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
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function complete(Request $request): JsonResponse
    {
        try {
            // Validate request
            $validated = $request->validate([
                'bank_account_id' => 'required|integer|exists:chart_of_accounts,id',
                'statement_date' => 'required|date',
                'statement_balance' => 'required|numeric',
                'reconciled_transaction_ids' => 'required|array',
                'reconciled_transaction_ids.*' => 'integer|exists:journal_entry_lines,id',
            ]);

            // Complete reconciliation
            $reconciliation = $this->bankReconciliationService->completeReconciliation($validated);

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
            return response()->json([
                'message' => 'Failed to complete reconciliation',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add adjusting entry during reconciliation
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function addTransaction(Request $request): JsonResponse
    {
        try {
            // Validate request
            $validated = $request->validate([
                'bank_account_id' => 'required|integer|exists:chart_of_accounts,id',
                'date' => 'required|date',
                'description' => 'required|string|max:255',
                'amount' => 'required|numeric|min:0.01',
                'type' => 'required|string|in:debit,credit',
                'reference' => 'nullable|string|max:255',
                'contra_account_id' => 'nullable|integer|exists:chart_of_accounts,id',
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
     * @param int $accountId
     * @return JsonResponse
     */
    public function history(int $accountId): JsonResponse
    {
        try {
            // Get reconciliation history
            $reconciliations = BankReconciliation::where('bank_account_id', $accountId)
                ->where('status', 'completed')
                ->orderBy('statement_date', 'desc')
                ->get();

            $data = [];
            foreach ($reconciliations as $reconciliation) {
                $data[] = [
                    'id' => $reconciliation->id,
                    'statement_date' => $reconciliation->statement_date->format('Y-m-d'),
                    'statement_balance' => round((float) $reconciliation->statement_balance, 2),
                    'opening_balance' => round((float) $reconciliation->opening_balance, 2),
                    'closing_balance' => round((float) $reconciliation->closing_balance, 2),
                    'difference' => round((float) $reconciliation->difference, 2),
                    'reconciled_at' => $reconciliation->reconciled_at ? 
                        $reconciliation->reconciled_at->format('Y-m-d H:i:s') : null,
                    'reconciled_by' => $reconciliation->reconciledBy ? 
                        $reconciliation->reconciledBy->name : null,
                ];
            }

            return response()->json([
                'data' => $data,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to retrieve reconciliation history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

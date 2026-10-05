<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountingAccessService;
use App\Services\BankReconciliationService;
use App\Services\Reconciliation\AutoMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * AutoMatchController
 *
 * API endpoints for auto-matching functionality in bank reconciliation.
 */
class AutoMatchController extends Controller
{
    public function __construct(
        private readonly AutoMatchingService $autoMatchingService,
        private readonly BankReconciliationService $bankReconciliationService,
        private readonly AccountingAccessService $accessService,
    ) {}

    /**
     * Issue #841 (BK9): auto-match writes are governed by
     * reconciliation.match — the same permission set as every other
     * reconciliation write endpoint.
     */
    private function denyUnlessMatchAllowed(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (! $user || ! $this->accessService->canMatchReconciliation($user)) {
            return response()->json([
                'message' => 'You do not have permission to run auto-matching.',
                'required_permission' => 'reconciliation.match',
            ], 403);
        }

        return null;
    }

    /**
     * Get auto-match suggestions for bank transactions
     *
     * POST /api/v1/reconciliation/auto-match/suggest
     */
    public function suggestMatches(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessMatchAllowed($request)) {
            return $denied;
        }

        try {
            $validated = $request->validate([
                'bank_account_id' => 'required|integer|exists:bank_accounts,id',
                'bank_transactions' => 'required|array|min:1',
                'bank_transactions.*.id' => 'required|string',
                'bank_transactions.*.date' => 'required|date',
                'bank_transactions.*.amount' => 'required|numeric',
                'bank_transactions.*.reference' => 'nullable|string',
                'bank_transactions.*.description' => 'nullable|string',
                'as_of_date' => 'nullable|date',
            ]);

            $suggestions = $this->autoMatchingService->findMatches(
                $validated['bank_account_id'],
                $validated['bank_transactions'],
                $validated['as_of_date'] ?? null
            );

            $statistics = $this->autoMatchingService->getMatchStatistics($suggestions);

            return response()->json([
                'success' => true,
                'data' => [
                    'suggestions' => $suggestions,
                    'statistics' => $statistics,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Auto-match suggestion failed', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate match suggestions',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Accept match suggestions and create reconciliation items
     *
     * POST /api/v1/reconciliation/auto-match/accept
     */
    public function acceptMatches(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessMatchAllowed($request)) {
            return $denied;
        }

        try {
            $validated = $request->validate([
                'bank_account_id' => 'required|integer|exists:bank_accounts,id',
                // Issue #168: optional — a draft reconciliation is started
                // automatically when the caller has none yet.
                'reconciliation_id' => 'nullable|integer|exists:bank_reconciliations,id',
                'matches' => 'required|array|min:1',
                'matches.*.bank_transaction_id' => 'required|string',
                'matches.*.gl_transaction_id' => 'required|integer|exists:journal_entry_lines,id',
                'matches.*.match_type' => 'required|string|in:exact,fuzzy,amount_only,manual',
                'matches.*.confidence' => 'required|numeric|min:0|max:100',
            ]);

            $results = $this->autoMatchingService->acceptMatches(
                $validated['bank_account_id'],
                $validated['reconciliation_id'] ?? null,
                $validated['matches']
            );

            return response()->json([
                'success' => true,
                'data' => $results,
                'message' => "Accepted {$results['accepted']} matches, {$results['failed']} failed",
            ]);

        } catch (\Exception $e) {
            Log::error('Auto-match acceptance failed', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to accept matches',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk accept high-confidence matches only
     *
     * POST /api/v1/reconciliation/auto-match/bulk-accept
     */
    public function bulkAcceptHighConfidence(Request $request): JsonResponse
    {
        if ($denied = $this->denyUnlessMatchAllowed($request)) {
            return $denied;
        }

        try {
            $validated = $request->validate([
                'bank_account_id' => 'required|integer|exists:bank_accounts,id',
                'reconciliation_id' => 'nullable|integer|exists:bank_reconciliations,id',
                'bank_transactions' => 'required|array',
                'min_confidence' => 'nullable|numeric|min:0|max:100|default:90',
            ]);

            // First get suggestions
            $suggestions = $this->autoMatchingService->findMatches(
                $validated['bank_account_id'],
                $validated['bank_transactions'],
                null
            );

            // Filter for high-confidence matches only
            $highConfidenceMatches = [];
            foreach ($suggestions as $suggestion) {
                if (! empty($suggestion['matches'])) {
                    $bestMatch = $suggestion['matches'][0]; // Already sorted by confidence

                    if ($bestMatch['confidence'] >= $validated['min_confidence']) {
                        $highConfidenceMatches[] = [
                            'bank_transaction_id' => $suggestion['bank_transaction']['id'],
                            'gl_transaction_id' => $bestMatch['gl_transaction_id'],
                            'match_type' => $bestMatch['match_type'],
                            'confidence' => $bestMatch['confidence'],
                        ];
                    }
                }
            }

            if (empty($highConfidenceMatches)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'accepted' => 0,
                        'failed' => 0,
                        'message' => 'No high-confidence matches found',
                    ],
                ]);
            }

            // Accept the high-confidence matches
            $results = $this->autoMatchingService->acceptMatches(
                $validated['bank_account_id'],
                $validated['reconciliation_id'] ?? null,
                $highConfidenceMatches
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'accepted' => $results['accepted'],
                    'failed' => $results['failed'],
                    'total_suggested' => count($highConfidenceMatches),
                    'errors' => $results['errors'],
                ],
                'message' => "Bulk accepted {$results['accepted']} high-confidence matches",
            ]);

        } catch (\Exception $e) {
            Log::error('Bulk auto-match acceptance failed', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to bulk accept matches',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get auto-matching statistics for analysis
     *
     * GET /api/v1/reconciliation/auto-match/statistics/{bankAccountId}
     */
    public function getStatistics(Request $request, int $bankAccountId): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $this->accessService->canViewReconciliation($user)) {
            return response()->json([
                'message' => 'You do not have permission to view reconciliation statistics.',
                'required_permission' => 'reconciliation.view',
            ], 403);
        }

        try {
            // Get unreconciled transactions.
            // Issue #101: getUnreconciledTransactions() takes (bankAccountId,
            // filters) and returns ['data' => ...] — the previous 4-argument
            // call and 'transactions' key both drifted out of sync and made
            // this endpoint throw on every request.
            $unreconciled = $this->bankReconciliationService->getUnreconciledTransactions($bankAccountId);

            // Convert to format for auto-matching
            $bankTransactions = collect($unreconciled['data'] ?? [])
                ->map(fn ($t) => [
                    'id' => (string) $t['id'],
                    'date' => $t['date'],
                    'amount' => (float) ($t['debit'] - $t['credit']),
                    'reference' => $t['reference'] ?? '',
                    'description' => $t['description'] ?? '',
                ])
                ->toArray();

            if (empty($bankTransactions)) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'message' => 'No unreconciled transactions to analyze',
                        'statistics' => null,
                    ],
                ]);
            }

            // Get suggestions and statistics
            $suggestions = $this->autoMatchingService->findMatches(
                $bankAccountId,
                $bankTransactions,
                null
            );

            $statistics = $this->autoMatchingService->getMatchStatistics($suggestions);

            return response()->json([
                'success' => true,
                'data' => $statistics,
            ]);

        } catch (\Exception $e) {
            Log::error('Auto-match statistics failed', [
                'error' => $e->getMessage(),
                'bank_account_id' => $bankAccountId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate statistics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reconciliation\APGLReconciliationService;
use App\Services\Reconciliation\ARControlAccountNotConfiguredException;
use App\Services\Reconciliation\ARGLReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SubledgerReconciliationController
 *
 * API endpoints for AR/AP to GL reconciliation controls.
 */
class SubledgerReconciliationController extends Controller
{
    public function __construct(
        private readonly ARGLReconciliationService $arReconciliationService,
        private readonly APGLReconciliationService $apReconciliationService,
    ) {}

    /**
     * Run AR to GL reconciliation
     *
     * POST /api/v1/accounting/reconciliation/ar-gl
     */
    public function reconcileAR(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'as_of_date' => 'required|date',
                'control_account_id' => 'nullable|integer|exists:chart_of_accounts,id',
                'legal_entity_id' => 'nullable|integer|exists:legal_entities,id',
                'tolerance' => 'nullable|numeric|min:0',
            ]);

            // Explicit request value first; otherwise the entity resolved by
            // ResolveEntityContext (X-Entity-Id / entity_id / user default).
            $legalEntityId = $validated['legal_entity_id']
                ?? $request->attributes->get('legal_entity_id');

            $result = $this->arReconciliationService->reconcile(
                $validated['as_of_date'],
                $validated['control_account_id'] ?? null,
                $legalEntityId ? (int) $legalEntityId : null,
                (float) ($validated['tolerance'] ?? 0.01),
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'control_result_id' => $result->id,
                    'legal_entity_id' => $result->legal_entity_id,
                    'as_of_date' => $result->as_of_date,
                    'subledger_balance' => $result->subledger_balance,
                    'gl_balance' => $result->gl_balance,
                    'difference' => $result->difference,
                    'status' => $result->status,
                    'discrepancies_count' => count($result->discrepancies ?? []),
                    'is_reconciled' => $result->isPassed(),
                ],
            ]);

        } catch (ARControlAccountNotConfiguredException $e) {
            // A missing mapping is a setup error, not a server failure — the
            // caller must fix the account mapping and re-run.
            Log::warning('AR reconciliation blocked: control account mapping missing', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('AR to GL reconciliation failed', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Reconciliation failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Run AP to GL reconciliation
     *
     * POST /api/v1/accounting/reconciliation/ap-gl
     */
    public function reconcileAP(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'as_of_date' => 'required|date',
                'control_account_id' => 'nullable|integer|exists:chart_of_accounts,id',
            ]);

            $result = $this->apReconciliationService->reconcile(
                $validated['as_of_date'],
                $validated['control_account_id'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'control_result_id' => $result->id,
                    'as_of_date' => $result->as_of_date,
                    'subledger_balance' => $result->subledger_balance,
                    'gl_balance' => $result->gl_balance,
                    'difference' => $result->difference,
                    'status' => $result->status,
                    'discrepancies_count' => count($result->discrepancies ?? []),
                    'is_reconciled' => $result->isPassed(),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('AP to GL reconciliation failed', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Reconciliation failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get AR to GL reconciliation report
     *
     * GET /api/v1/accounting/reports/ar-gl-reconciliation
     */
    public function getARReport(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'as_of_date' => 'required|date',
                'legal_entity_id' => 'nullable|integer|exists:legal_entities,id',
            ]);

            $legalEntityId = $validated['legal_entity_id']
                ?? $request->attributes->get('legal_entity_id');

            $report = $this->arReconciliationService->generateReport(
                $validated['as_of_date'],
                $legalEntityId ? (int) $legalEntityId : null,
            );

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);

        } catch (ARControlAccountNotConfiguredException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('AR reconciliation report failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate report',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get AP to GL reconciliation report
     *
     * GET /api/v1/accounting/reports/ap-gl-reconciliation
     */
    public function getAPReport(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'as_of_date' => 'required|date',
            ]);

            $report = $this->apReconciliationService->generateReport($validated['as_of_date']);

            return response()->json([
                'success' => true,
                'data' => $report,
            ]);

        } catch (\Exception $e) {
            Log::error('AP reconciliation report failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate report',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get reconciliation history
     *
     * GET /api/v1/accounting/reconciliation/history/{type}
     */
    public function getHistory(string $type, Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:100|default:12',
                'legal_entity_id' => 'nullable|integer|exists:legal_entities,id',
            ]);

            $controlName = match ($type) {
                'ar' => 'ar_gl_reconciliation',
                'ap' => 'ap_gl_reconciliation',
                default => throw new \InvalidArgumentException("Invalid reconciliation type: {$type}"),
            };

            // Only an explicit query filter — AP results are not entity-scoped
            // yet, so a middleware-resolved entity must not blank its history.
            $legalEntityId = $validated['legal_entity_id'] ?? null;

            $history = \App\Models\ReconciliationControlResult::forControl($controlName)
                ->when($legalEntityId, fn ($q) => $q->where('legal_entity_id', (int) $legalEntityId))
                ->orderByDesc('as_of_date')
                ->limit($validated['limit'])
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'type' => $type,
                    'history' => $history,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Reconciliation history failed', [
                'error' => $e->getMessage(),
                'type' => $type,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve history',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

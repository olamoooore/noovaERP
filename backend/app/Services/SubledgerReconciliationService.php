<?php

namespace App\Services;

use App\Services\Reconciliation\APGLReconciliationService;
use App\Services\Reconciliation\ARGLReconciliationService;

/**
 * SubledgerReconciliationService
 *
 * Unified facade for AR/AP subledger to GL reconciliation.
 *
 * Workstream 2 (2026-04-15): Refactored to use canonical reconciliation services
 * instead of heuristics. Previously used name/code patterns which could match
 * wrong accounts. Now delegates to ARGLReconciliationService and APGLReconciliationService
 * which use the account_mappings table for proper account resolution.
 *
 * @deprecated Use ARGLReconciliationService and APGLReconciliationService directly
 */
class SubledgerReconciliationService
{
    public function __construct(
        private readonly ARGLReconciliationService $arService,
        private readonly APGLReconciliationService $apService,
    ) {}

    /**
     * Reconcile AR subledger to GL.
     *
     * Delegates to ARGLReconciliationService which uses account_mappings table
     * for proper AR control account resolution.
     */
    public function reconcileAR(): array
    {
        $result = $this->arService->reconcile(now()->toDateString());

        return [
            'type' => 'AR',
            'subledger_total' => $result->subledger_balance,
            'gl_total' => $result->gl_balance,
            'difference' => $result->difference,
            'reconciled' => $result->status === 'passed',
            'reconciled_at' => $result->created_at?->toISOString() ?? now()->toISOString(),
            'discrepancies' => $result->discrepancies,
            'result_id' => $result->id,
            // Note: ar_accounts not available from canonical service (uses mapping)
        ];
    }

    /**
     * Reconcile AP subledger to GL.
     *
     * Delegates to APGLReconciliationService which uses account_mappings table
     * for proper AP control account resolution.
     */
    public function reconcileAP(): array
    {
        $result = $this->apService->reconcile(now()->toDateString());

        return [
            'type' => 'AP',
            'subledger_total' => $result->subledger_balance,
            'gl_total' => $result->gl_balance,
            'difference' => $result->difference,
            'reconciled' => $result->status === 'passed',
            'reconciled_at' => $result->created_at?->toISOString() ?? now()->toISOString(),
            'discrepancies' => $result->discrepancies,
            'result_id' => $result->id,
            // Note: ap_accounts not available from canonical service (uses mapping)
        ];
    }

    /**
     * Run both AR and AP reconciliation.
     */
    public function reconcileAll(): array
    {
        return [
            'ar' => $this->reconcileAR(),
            'ap' => $this->reconcileAP(),
        ];
    }
}

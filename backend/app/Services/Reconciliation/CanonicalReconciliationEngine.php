<?php

namespace App\Services\Reconciliation;

use App\Models\ReconciliationException;
use App\Models\ReconciliationControlResult;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class CanonicalReconciliationEngine
{
    public function __construct(
        private readonly PosPaymentGLReconciliationService $posPaymentService,
        private readonly ARGLReconciliationService $arService,
        private readonly APGLReconciliationService $apService,
        private readonly InventoryGLReconciliationService $inventoryService,
    ) {}

    public function reconcileAll(?string $startDate = null, ?string $endDate = null): array
    {
        $results = [
            'pos_payments' => $this->reconcilePosPayments($startDate, $endDate),
            'ar_gl' => $this->reconcileARGL($startDate, $endDate),
            'ap_gl' => $this->reconcileAPGL($startDate, $endDate),
            'inventory_gl' => $this->reconcileInventoryGL($startDate, $endDate),
        ];

        $totalExceptions = collect($results)->sum(fn($r) => count($r['exceptions'] ?? []));

        Log::info('Canonical reconciliation completed', [
            'total_exceptions' => $totalExceptions,
            'date_range' => ['start' => $startDate, 'end' => $endDate],
        ]);

        return [
            'success' => $totalExceptions === 0,
            'results' => $results,
            'total_exceptions' => $totalExceptions,
        ];
    }

    public function reconcilePosPayments(?string $startDate = null, ?string $endDate = null): array
    {
        $result = $this->posPaymentService->reconcile($startDate, $endDate);
        $this->persistExceptions($result['exceptions'], 'pos_payment_gl');
        return $result;
    }

    public function reconcileARGL(?string $asOfDate = null): array
    {
        $result = $this->arService->reconcile($asOfDate ?? now()->toDateString());
        $this->persistControlResult($result, 'ar_gl_reconciliation');
        return ['exceptions' => [], 'stats' => []];
    }

    public function reconcileAPGL(?string $asOfDate = null): array
    {
        $result = $this->apService->reconcile($asOfDate ?? now()->toDateString());
        $this->persistControlResult($result, 'ap_gl_reconciliation');
        return ['exceptions' => [], 'stats' => []];
    }

    public function reconcileInventoryGL(?string $asOfDate = null): array
    {
        $result = $this->inventoryService->reconcile($asOfDate ?? now()->toDateString());
        $this->persistControlResult($result, 'inventory_gl_reconciliation');
        return ['exceptions' => [], 'stats' => []];
    }

    private function persistExceptions(array $exceptions, string $source): void
    {
        foreach ($exceptions as $exception) {
            ReconciliationException::create([
                'exception_type' => $exception['type'] ?? 'unknown',
                'severity' => $exception['severity'] ?? 'medium',
                'entity_type' => $exception['entity'] ?? 'unknown',
                'entity_id' => $exception['entity_id'] ?? null,
                'source' => $source,
                'details' => $exception,
                'detected_at' => now(),
                'resolved_at' => null,
                'resolved_by' => null,
                'notes' => null,
            ]);
        }
    }

    private function persistControlResult($result, string $controlName): void
    {
        if ($result instanceof ReconciliationControlResult) {
            return;
        }
    }

    public function getOpenExceptions(?string $source = null): array
    {
        $query = ReconciliationException::whereNull('resolved_at');
        if ($source) {
            $query->where('source', $source);
        }
        return $query->orderBy('detected_at', 'desc')->get()->toArray();
    }

    public function resolveException(int $exceptionId, string $notes): ReconciliationException
    {
        $exception = ReconciliationException::findOrFail($exceptionId);
        $exception->update([
            'resolved_at' => now(),
            'resolved_by' => TenantUserHelper::requireTenantUserId(),
            'notes' => $notes,
        ]);
        return $exception;
    }
}
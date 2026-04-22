<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\CloseCycle;
use Modules\FinancialClose\Models\AuditLog;
use Modules\FinancialClose\Events\FinancialCloseCycleOpened;

class FinancialCloseCycleService
{
    /**
     * Get all close cycles for a tenant/entity
     */
    public function listCycles(string $tenantId, string $entityId, array $filters = []): array
    {
        $query = CloseCycle::where('tenant_id', $tenantId);

        if ($entityId !== '') {
            $query->where('entity_id', $entityId);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('created_at', 'desc')->get()->toArray();
    }

    /**
     * Get a single close cycle
     */
    public function getCycle(string $tenantId, string $cycleId): ?CloseCycle
    {
        return CloseCycle::where('tenant_id', $tenantId)
            ->where('id', $cycleId)
            ->first();
    }

    /**
     * Create a new close cycle
     */
    public function createCycle(string $tenantId, string $entityId, string $periodId, string $userId): ?CloseCycle
    {
        $existing = CloseCycle::where('tenant_id', $tenantId)
            ->where('entity_id', $entityId)
            ->where('period_id', $periodId)
            ->whereNotIn('status', ['closed', 'archived'])
            ->first();

        if ($existing) {
            Log::warning('FinancialClose: active cycle already exists for period', [
                'tenant_id' => $tenantId,
                'entity_id' => $entityId,
                'period_id' => $periodId,
            ]);
            return null;
        }

        return CloseCycle::create([
            'tenant_id' => $tenantId,
            'entity_id' => $entityId,
            'period_id' => $periodId,
            'status' => 'draft',
            'created_by' => $userId,
        ]);
    }

    /**
     * Open a close cycle
     */
    public function openCycle(string $tenantId, string $cycleId, string $userId): bool
    {
        $cycle = $this->getCycle($tenantId, $cycleId);

        if (!$cycle || $cycle->status !== 'draft') {
            return false;
        }

        $cycle->update([
            'status' => 'opened',
            'opened_at' => now(),
        ]);

        event(new FinancialCloseCycleOpened(
            $tenantId,
            $cycleId,
            $cycle->entity_id,
            $cycle->period_id,
            $userId,
        ));

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $userId,
            'action' => 'cycle_opened',
            'entity_type' => 'close_cycle',
            'entity_id' => $cycleId,
        ]);

        return true;
    }

    /**
     * Close a cycle
     */
    public function closeCycle(string $tenantId, string $cycleId, string $userId): bool
    {
        $cycle = $this->getCycle($tenantId, $cycleId);

        if (!$cycle || $cycle->status !== 'ready_to_close') {
            Log::warning('FinancialClose: cycle not ready to close', [
                'cycle_id' => $cycleId,
                'status' => $cycle?->status,
            ]);
            return false;
        }

        $cycle->update([
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $userId,
            'action' => 'cycle_closed',
            'entity_type' => 'close_cycle',
            'entity_id' => $cycleId,
        ]);

        return true;
    }
}
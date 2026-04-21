<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\GroupCloseCycle;
use Modules\FinancialClose\Models\GroupCycleDependency;
use Modules\FinancialClose\Models\ConsolidationTask;
use Modules\FinancialClose\Models\IntercompanyCloseDependency;
use Modules\FinancialClose\Models\CloseCycle;
use Modules\FinancialClose\Models\AuditLog;

class GroupCloseOrchestrationService
{
    /**
     * Create a group close cycle
     */
    public function createGroupCycle(string $tenantId, string $groupId, string $periodId, string $userId): ?GroupCloseCycle
    {
        $existing = GroupCloseCycle::where('tenant_id', $tenantId)
            ->where('group_id', $groupId)
            ->where('period_id', $periodId)
            ->whereNotIn('status', ['closed', 'archived'])
            ->first();

        if ($existing) {
            Log::warning('FinancialClose: active group cycle already exists', [
                'group_id' => $groupId,
                'period_id' => $periodId,
            ]);
            return null;
        }

        return GroupCloseCycle::create([
            'tenant_id' => $tenantId,
            'group_id' => $groupId,
            'period_id' => $periodId,
            'status' => 'draft',
            'created_by' => $userId,
        ]);
    }

    /**
     * Add a dependency between group cycles
     * Group readiness depends on child cycles
     */
    public function addGroupCycleDependency(string $tenantId, string $groupCycleId, string $dependsOnCycleId, string $type = 'hard'): ?GroupCycleDependency
    {
        $existing = GroupCycleDependency::where('tenant_id', $tenantId)
            ->where('group_cycle_id', $groupCycleId)
            ->where('depends_on_cycle_id', $dependsOnCycleId)
            ->first();

        if ($existing) {
            return $existing;
        }

        return GroupCycleDependency::create([
            'tenant_id' => $tenantId,
            'group_cycle_id' => $groupCycleId,
            'depends_on_cycle_id' => $dependsOnCycleId,
            'dependency_type' => $type,
        ]);
    }

    /**
     * Check if all child cycles are ready before group can close
     */
    public function isGroupReadyToClose(string $tenantId, string $groupCycleId): bool
    {
        $dependencies = GroupCycleDependency::where('tenant_id', $tenantId)
            ->where('group_cycle_id', $groupCycleId)
            ->where('dependency_type', 'hard')
            ->get();

        foreach ($dependencies as $dependency) {
            $childCycle = CloseCycle::where('tenant_id', $tenantId)
                ->where('id', $dependency->depends_on_cycle_id)
                ->first();

            if (!$childCycle || $childCycle->status !== 'closed') {
                Log::warning('FinancialClose: group close blocked by unresolved child cycle', [
                    'group_cycle_id' => $groupCycleId,
                    'depends_on_cycle_id' => $dependency->depends_on_cycle_id,
                    'child_status' => $childCycle?->status,
                ]);
                return false;
            }
        }

        return true;
    }

    /**
     * Create a consolidation task for a group cycle
     */
    public function createConsolidationTask(string $tenantId, string $groupCycleId, array $data): ?ConsolidationTask
    {
        return ConsolidationTask::create([
            'tenant_id' => $tenantId,
            'group_cycle_id' => $groupCycleId,
            'title' => $data['title'],
            'status' => 'created',
            'assigned_to' => $data['assigned_to'] ?? null,
            'due_date' => $data['due_date'] ?? null,
        ]);
    }

    /**
     * Add an intercompany close dependency
     */
    public function addIntercompanyDependency(string $tenantId, string $groupCycleId, string $fromEntityId, string $toEntityId, string $type = 'hard'): ?IntercompanyCloseDependency
    {
        return IntercompanyCloseDependency::create([
            'tenant_id' => $tenantId,
            'group_cycle_id' => $groupCycleId,
            'from_entity_id' => $fromEntityId,
            'to_entity_id' => $toEntityId,
            'dependency_type' => $type,
            'status' => 'pending',
        ]);
    }
}
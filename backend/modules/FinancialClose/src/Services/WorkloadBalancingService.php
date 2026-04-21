<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\UserCapacityProfile;
use Modules\FinancialClose\Models\AssignmentWorkloadSnapshot;
use Modules\FinancialClose\Models\AbsenceWindow;
use Modules\FinancialClose\Models\CloseTask;

class WorkloadBalancingService
{
    /**
     * Get capacity profile for a user
     */
    public function getCapacityProfile(string $tenantId, string $userId): ?UserCapacityProfile
    {
        return UserCapacityProfile::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * Check if a user has capacity for more tasks
     */
    public function hasCapacity(string $tenantId, string $userId): bool
    {
        $profile = $this->getCapacityProfile($tenantId, $userId);

        if (!$profile) {
            return true;
        }

        return $profile->current_tasks < $profile->max_tasks;
    }

    /**
     * Check if a user is absent on a given date
     */
    public function isUserAbsent(string $tenantId, string $userId, string $date): bool
    {
        return AbsenceWindow::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('is_approved', true)
            ->where('start_date', '<=', $date)
            ->where('end_date', '>=', $date)
            ->exists();
    }

    /**
     * Suggest the best available user for a task based on capacity
     */
    public function suggestAssignee(string $tenantId, array $candidateUserIds, string $date): ?string
    {
        foreach ($candidateUserIds as $userId) {
            if ($this->isUserAbsent($tenantId, $userId, $date)) {
                continue;
            }

            if ($this->hasCapacity($tenantId, $userId)) {
                return $userId;
            }
        }

        Log::warning('FinancialClose: no available assignee found', [
            'tenant_id' => $tenantId,
            'candidates' => $candidateUserIds,
            'date' => $date,
        ]);

        return null;
    }

    /**
     * Generate a workload snapshot for a cycle
     */
    public function generateWorkloadSnapshot(string $tenantId, string $cycleId): void
    {
        $tasks = CloseTask::where('tenant_id', $tenantId)
            ->where('cycle_id', $cycleId)
            ->whereNotNull('assigned_to')
            ->get();

        $grouped = $tasks->groupBy('assigned_to');

        foreach ($grouped as $userId => $userTasks) {
            $assigned = $userTasks->count();
            $completed = $userTasks->where('status', 'completed')->count();
            $overdue = $userTasks->where('status', '!=', 'completed')
                ->where('due_date', '<', now())
                ->count();

            AssignmentWorkloadSnapshot::create([
                'tenant_id' => $tenantId,
                'cycle_id' => $cycleId,
                'user_id' => $userId,
                'assigned_tasks' => $assigned,
                'completed_tasks' => $completed,
                'overdue_tasks' => $overdue,
                'workload_score' => $assigned > 0 ? round(($completed / $assigned) * 100, 2) : 0,
                'generated_at' => now(),
            ]);
        }
    }
}
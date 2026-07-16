<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\FinanceRole;
use Modules\FinancialClose\Models\FinanceRoleAssignment;
use Modules\FinancialClose\Models\TaskDelegation;

class FinanceOrgHierarchyService
{
    /**
     * Get all finance roles for a tenant
     */
    public function listRoles(string $tenantId): array
    {
        return FinanceRole::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get()
            ->toArray();
    }

    /**
     * Get all role assignments for a user
     */
    public function getUserRoles(string $tenantId, string $userId): array
    {
        return FinanceRoleAssignment::where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where(function ($query) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', now());
            })
            ->where(function ($query) {
                $query->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', now());
            })
            ->get()
            ->toArray();
    }

    /**
     * Assign a role to a user
     */
    public function assignRole(string $tenantId, string $userId, string $roleId, ?string $entityId = null, ?string $effectiveFrom = null, ?string $effectiveTo = null): ?FinanceRoleAssignment
    {
        return FinanceRoleAssignment::create([
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'user_id' => $userId,
            'entity_id' => $entityId,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
        ]);
    }

    /**
     * Create a task delegation
     */
    public function createDelegation(string $tenantId, string $taskId, string $delegatedFrom, string $delegatedTo, ?string $effectiveFrom = null, ?string $effectiveTo = null, ?string $reason = null): ?TaskDelegation
    {
        // Enforce effective dates
        if ($effectiveTo && $effectiveFrom && $effectiveTo < $effectiveFrom) {
            Log::warning('FinancialClose: delegation effective_to cannot be before effective_from', [
                'task_id' => $taskId,
            ]);

            return null;
        }

        return TaskDelegation::create([
            'tenant_id' => $tenantId,
            'task_id' => $taskId,
            'delegated_from' => $delegatedFrom,
            'delegated_to' => $delegatedTo,
            'effective_from' => $effectiveFrom,
            'effective_to' => $effectiveTo,
            'status' => 'active',
            'reason' => $reason,
        ]);
    }

    /**
     * Get active delegations for a user
     */
    public function getActiveDelegations(string $tenantId, string $userId): array
    {
        return TaskDelegation::where('tenant_id', $tenantId)
            ->where('delegated_to', $userId)
            ->where('status', 'active')
            ->where(function ($query) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', now());
            })
            ->where(function ($query) {
                $query->whereNull('effective_from')
                    ->orWhere('effective_from', '<=', now());
            })
            ->get()
            ->toArray();
    }
}

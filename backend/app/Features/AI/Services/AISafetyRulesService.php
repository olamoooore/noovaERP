<?php

namespace App\Features\AI\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * AI Safety Rules Service
 *
 * Enforces safety boundaries for AI operations:
 * - Data access scope
 * - Approval requirements
 * - Cost controls
 * - Audit trail
 */
class AISafetyRulesService
{
    private const CACHE_TTL = 300;

    /**
     * Check if an AI action is allowed
     *
     * @param string $action The AI action being attempted
     * @param User $user The user requesting the action
     * @param array $context Additional context (module, entity, etc.)
     * @return array{allowed: bool, reason: string|null, requires_approval: bool}
     */
    public function checkActionAllowed(string $action, User $user, array $context = []): array
    {
        // 1. Check if user has AI access permission
        if (!$this->userHasAIPermission($user)) {
            return [
                'allowed' => false,
                'reason' => 'User does not have AI access permission',
                'requires_approval' => false,
            ];
        }

        // 2. Check data scope boundaries
        $scopeCheck = $this->checkDataScope($action, $user, $context);
        if (!$scopeCheck['allowed']) {
            return $scopeCheck;
        }

        // 3. Check cost limits
        $costCheck = $this->checkCostLimits($user, $context);
        if (!$costCheck['allowed']) {
            return $costCheck;
        }

        // 4. Check if approval is required
        $requiresApproval = $this->requiresApproval($action, $context);

        return [
            'allowed' => true,
            'reason' => null,
            'requires_approval' => $requiresApproval,
        ];
    }

    /**
     * Check if user has AI access permission
     */
    private function userHasAIPermission(User $user): bool
    {
        // Super admin always has access
        if ($user->is_super_admin) {
            return true;
        }

        // Check explicit permission
        if (method_exists($user, 'hasPermissionTo')) {
            try {
                return $user->hasPermissionTo('ai.access');
            } catch (\Exception $e) {
                // Permission doesn't exist, fall through
            }
        }

        // Check role-based access
        return $user->hasRole(['admin', 'ai_user', 'analyst']);
    }

    /**
     * Check data scope boundaries
     */
    private function checkDataScope(string $action, User $user, array $context): array
    {
        $module = $context['module'] ?? null;
        $entityType = $context['entity_type'] ?? null;
        $entityId = $context['entity_id'] ?? null;

        // AI cannot access data outside user's tenant
        $tenantId = $context['tenant_id'] ?? tenant('id');
        if ($tenantId === null) {
            return [
                'allowed' => false,
                'reason' => 'No tenant context for AI operation',
                'requires_approval' => false,
            ];
        }

        // Check if user has access to the specific module
        if ($module && !$this->userHasModuleAccess($user, $module)) {
            return [
                'allowed' => false,
                'reason' => "User does not have access to module '{$module}'",
                'requires_approval' => false,
            ];
        }

        // Check if user has access to the specific entity
        if ($entityType && $entityId && !$this->userHasEntityAccess($user, $entityType, $entityId)) {
            return [
                'allowed' => false,
                'reason' => 'User does not have access to this specific record',
                'requires_approval' => false,
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'requires_approval' => false,
        ];
    }

    /**
     * Check cost limits
     */
    private function checkCostLimits(User $user, array $context): array
    {
        $tenantId = $context['tenant_id'] ?? tenant('id');

        // Get daily usage
        $dailyKey = "ai_usage:{$tenantId}:" . date('Y-m-d');
        $dailyUsage = Cache::get($dailyKey, 0);

        // Get daily limit from config
        $dailyLimit = config('ai.limits.daily_cost', 100.00);

        if ($dailyUsage >= $dailyLimit) {
            return [
                'allowed' => false,
                'reason' => "Daily AI cost limit reached (\${$dailyUsage}/\${$dailyLimit})",
                'requires_approval' => false,
            ];
        }

        // Get monthly usage
        $monthlyKey = "ai_usage:{$tenantId}:" . date('Y-m');
        $monthlyUsage = Cache::get($monthlyKey, 0);

        // Get monthly limit from config
        $monthlyLimit = config('ai.limits.monthly_cost', 2000.00);

        if ($monthlyUsage >= $monthlyLimit) {
            return [
                'allowed' => false,
                'reason' => "Monthly AI cost limit reached (\${$monthlyUsage}/\${$monthlyLimit})",
                'requires_approval' => false,
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'requires_approval' => false,
        ];
    }

    /**
     * Check if action requires approval
     */
    private function requiresApproval(string $action, array $context): bool
    {
        // High-risk actions always require approval
        $highRiskActions = [
            'ai.delete_record',
            'ai.modify_financial_data',
            'ai.execute_transaction',
            'ai.access_sensitive_data',
        ];

        if (in_array($action, $highRiskActions)) {
            return true;
        }

        // Actions on financial entities require approval
        $financialEntities = ['Invoice', 'Payment', 'JournalEntry', 'Bill'];
        $entityType = $context['entity_type'] ?? null;

        if ($entityType && in_array($entityType, $financialEntities)) {
            return true;
        }

        return false;
    }

    /**
     * Check if user has access to a module
     */
    private function userHasModuleAccess(User $user, string $module): bool
    {
        // Super admin has access to all modules
        if ($user->is_super_admin) {
            return true;
        }

        // Check feature gate
        $tenantId = tenant('id');
        if ($tenantId) {
            $featureGate = app('feature_gate');
            $check = $featureGate->check((string) $tenantId, $module);
            return $check['is_entitled'];
        }

        return false;
    }

    /**
     * Check if user has access to a specific entity
     */
    private function userHasEntityAccess(User $user, string $entityType, $entityId): bool
    {
        // Super admin has access to all entities
        if ($user->is_super_admin) {
            return true;
        }

        // Check entity-level permissions if available
        if (method_exists($user, 'hasAccessTo')) {
            return $user->hasAccessTo($entityType, $entityId);
        }

        // Default to true (entity access checked at query level)
        return true;
    }

    /**
     * Record AI usage for cost tracking
     */
    public function recordUsage(string $tenantId, float $cost, array $metadata = []): void
    {
        // Daily usage
        $dailyKey = "ai_usage:{$tenantId}:" . date('Y-m-d');
        Cache::increment($dailyKey, $cost);
        Cache::expire($dailyKey, 86400 * 2); // 2 days

        // Monthly usage
        $monthlyKey = "ai_usage:{$tenantId}:" . date('Y-m');
        Cache::increment($monthlyKey, $cost);
        Cache::expire($monthlyKey, 86400 * 35); // 35 days

        // Log for audit
        Log::info('AI usage recorded', [
            'tenant_id' => $tenantId,
            'cost' => $cost,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Get usage statistics for a tenant
     */
    public function getUsageStats(string $tenantId): array
    {
        $dailyKey = "ai_usage:{$tenantId}:" . date('Y-m-d');
        $monthlyKey = "ai_usage:{$tenantId}:" . date('Y-m');

        return [
            'daily_usage' => Cache::get($dailyKey, 0),
            'daily_limit' => config('ai.limits.daily_cost', 100.00),
            'monthly_usage' => Cache::get($monthlyKey, 0),
            'monthly_limit' => config('ai.limits.monthly_cost', 2000.00),
        ];
    }
}

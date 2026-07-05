<?php

namespace App\Platform\Workflow;

use App\Platform\Workflow\Contracts\StatusTransitionContract;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WorkflowEngine — platform-wide shared state machine.
 *
 * Modules register status schemas (entity_type → transitions).
 * The engine evaluates transitions, enforces guards, and logs results.
 *
 * Usage:
 *   $engine = app(WorkflowEngine::class);
 *   $engine->registerSchema('invoice', 'draft', [...transitions...]);
 *   $engine->canTransition('invoice', 'INV-001', 'submit', $tenantId, $userId);
 *   $engine->transition('invoice', 'INV-001', 'submit', $tenantId, $userId);
 *   $engine->getAvailableActions('invoice', 'INV-001', $tenantId, $userId);
 */
class WorkflowEngine
{
    /**
     * Register a status schema for an entity type.
     */
    public function registerSchema(
        string $entityType,
        string $initialStatus,
        array $transitions,
        string $tenantId,
        ?string $description = null,
    ): WorkflowSchema {
        $transitionData = array_map(fn(StatusTransitionContract $t) => [
            'from' => $t->getFromStatus(),
            'to' => $t->getToStatus(),
            'action' => $t->getAction(),
            'label' => $t->getLabel(),
            'permission' => $t->getRequiredPermission(),
            'role' => $t->getRequiredRole(),
            'conditions' => $t->getConditions(),
            'events' => $t->getEventsToFire(),
        ], $transitions);

        return WorkflowSchema::updateOrCreate(
            ['tenant_id' => $tenantId, 'entity_type' => $entityType],
            [
                'id' => Str::uuid(),
                'initial_status' => $initialStatus,
                'transitions' => $transitionData,
                'description' => $description,
                'is_active' => true,
            ]
        );
    }

    /**
     * Check if a transition is allowed.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    public function canTransition(
        string $entityType,
        string $entityId,
        string $action,
        string $tenantId,
        ?string $userId = null,
        ?string $currentStatus = null,
        ?array $entityData = null,
    ): array {
        $schema = $this->getSchema($entityType, $tenantId);
        if (!$schema) {
            return ['allowed' => false, 'reason' => "No workflow schema for entity type '{$entityType}'"];
        }

        if (!$currentStatus) {
            return ['allowed' => false, 'reason' => 'Current status is required'];
        }

        $transition = $this->findTransition($schema, $currentStatus, $action);
        if (!$transition) {
            return ['allowed' => false, 'reason' => "Transition '{$action}' not available from status '{$currentStatus}'"];
        }

        // Check conditions
        if (!empty($transition['conditions']) && $entityData) {
            foreach ($transition['conditions'] as $condition => $expectedValue) {
                $actualValue = $entityData[$condition] ?? null;
                if ($actualValue != $expectedValue) {
                    return ['allowed' => false, 'reason' => "Condition '{$condition}' not met (expected {$expectedValue}, got {$actualValue})"];
                }
            }
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Execute a status transition.
     *
     * @return array{success: bool, new_status: string|null, reason: string|null}
     */
    public function transition(
        string $entityType,
        string $entityId,
        string $action,
        string $tenantId,
        ?string $userId = null,
        ?string $currentStatus = null,
        ?array $entityData = null,
        array $metadata = [],
    ): array {
        $check = $this->canTransition($entityType, $entityId, $action, $tenantId, $userId, $currentStatus, $entityData);
        if (!$check['allowed']) {
            $this->logTransition($tenantId, $entityType, $entityId, $currentStatus, $currentStatus, $action, 'rejected', $userId, $check['reason'], $metadata);
            return ['success' => false, 'new_status' => null, 'reason' => $check['reason']];
        }

        $schema = $this->getSchema($entityType, $tenantId);
        $transition = $this->findTransition($schema, $currentStatus, $action);
        $newStatus = $transition['to'];

        $this->logTransition($tenantId, $entityType, $entityId, $currentStatus, $newStatus, $action, 'applied', $userId, null, $metadata);

        // Dispatch events
        foreach ($transition['events'] ?? [] as $eventName) {
            Log::info("Workflow event dispatched: {$eventName}", [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_status' => $currentStatus,
                'new_status' => $newStatus,
            ]);
        }

        return ['success' => true, 'new_status' => $newStatus, 'reason' => null];
    }

    /**
     * Get all available actions for a given status.
     *
     * @return array<array{action: string, label: string, to_status: string, permission: string|null}>
     */
    public function getAvailableActions(
        string $entityType,
        string $currentStatus,
        string $tenantId,
        ?string $userId = null,
    ): array {
        $schema = $this->getSchema($entityType, $tenantId);
        if (!$schema) return [];

        $actions = [];
        foreach ($schema->transitions as $transition) {
            if ($transition['from'] === $currentStatus || $transition['from'] === '*') {
                $actions[] = [
                    'action' => $transition['action'],
                    'label' => $transition['label'],
                    'to_status' => $transition['to'],
                    'permission' => $transition['permission'] ?? null,
                ];
            }
        }

        return $actions;
    }

    /**
     * Get the full status schema for an entity type.
     */
    public function getSchema(string $entityType, string $tenantId): ?WorkflowSchema
    {
        return WorkflowSchema::forTenant($tenantId)
            ->forEntity($entityType)
            ->active()
            ->first();
    }

    /**
     * Get transition log for an entity.
     *
     * @return WorkflowLog[]
     */
    public function getLog(string $entityType, string $entityId, string $tenantId): array
    {
        return WorkflowLog::forTenant($tenantId)
            ->forEntity($entityType, $entityId)
            ->orderBy('created_at')
            ->get()
            ->toArray();
    }

    // ─── Private ──────────────────────────────────────────────────

    private function findTransition(WorkflowSchema $schema, string $currentStatus, string $action): ?array
    {
        foreach ($schema->transitions as $transition) {
            if ($transition['action'] === $action && ($transition['from'] === $currentStatus || $transition['from'] === '*')) {
                return $transition;
            }
        }
        return null;
    }

    private function logTransition(
        string $tenantId,
        string $entityType,
        string $entityId,
        ?string $oldStatus,
        ?string $newStatus,
        string $action,
        string $result,
        ?string $userId,
        ?string $reason,
        array $metadata,
    ): void {
        WorkflowLog::create([
            'id' => Str::uuid(),
            'tenant_id' => $tenantId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'action' => $action,
            'result' => $result,
            'user_id' => $userId,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }
}

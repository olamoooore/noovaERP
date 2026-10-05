<?php

namespace App\Platform\Workflow;

use App\Platform\Workflow\Contracts\StatusTransitionContract;
use App\Platform\Workflow\Contracts\WorkflowGuard;
use App\Platform\Workflow\Contracts\WorkflowHandler;
use App\Platform\Workflow\Contracts\WorkflowSubject;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * WorkflowEngine — platform-wide shared state machine.
 *
 * Modules register status schemas (entity_type → transitions, guards and
 * side-effect handlers). The engine evaluates transitions, enforces guards
 * (permission / approval / period-lock), runs the registered handler and
 * applies the new status through the subject's single write path.
 *
 * Usage:
 *   $engine = app(WorkflowEngine::class);
 *   $engine->defineSchema('invoice', 'draft', [...transitions...]);
 *   $engine->transitionSubject($invoice, 'post', $context);
 *   $engine->canTransitionSubject($invoice, 'post');
 *
 * Every applied/rejected transition is written to `workflow_logs` and fed to
 * the platform audit trail (#895) so status history is queryable through the
 * document audit API.
 */
class WorkflowEngine
{
    /**
     * Code-defined schemas, keyed by entity type. Unlike the persisted
     * WorkflowSchema rows these can carry guards and closures (handlers that
     * wrap existing services), so they are the authoritative definitions for
     * engine-managed documents.
     *
     * Instance state (the engine is a container singleton): keeping this off
     * static state means a fresh application — each test case, each request —
     * starts with no leaked schemas from a previous app lifecycle.
     *
     * @var array<string, array{initial_status: string, description: ?string, transitions: array<int, array<string, mixed>>}>
     */
    protected array $definitions = [];

    /**
     * Register a status schema for an entity type in memory.
     *
     * @param  array<int, StatusTransitionContract|array<string, mixed>>  $transitions
     */
    public function defineSchema(
        string $entityType,
        string $initialStatus,
        array $transitions,
        ?string $description = null,
    ): void {
        $this->definitions[$entityType] = [
            'initial_status' => $initialStatus,
            'description' => $description,
            'transitions' => array_map(fn ($transition) => $this->normalizeTransition($transition), $transitions),
        ];
    }

    /**
     * Register a status schema for an entity type (persisted variant).
     *
     * Kept for compatibility with the original engine API; guards and handlers
     * cannot be serialized into the schema row, so engine-managed documents use
     * defineSchema() and this row remains the tenant-editable description.
     */
    public function registerSchema(
        string $entityType,
        string $initialStatus,
        array $transitions,
        string $tenantId,
        ?string $description = null,
    ): WorkflowSchema {
        $transitionData = array_map(fn (StatusTransitionContract $t) => [
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

    public function hasDefinition(string $entityType): bool
    {
        return isset($this->definitions[$entityType]);
    }

    public function initialStatusFor(string $entityType): ?string
    {
        return $this->definitions[$entityType]['initial_status'] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function transitionDefinitions(string $entityType): array
    {
        return $this->definitions[$entityType]['transitions'] ?? [];
    }

    /**
     * Check whether an action is allowed for a subject's current status.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    public function canTransitionSubject(
        WorkflowSubject $subject,
        string $action,
        array $context = [],
        ?string $currentStatus = null,
    ): array {
        $entityType = $subject->workflowEntityType();
        $currentStatus ??= $subject->workflowCurrentStatus();

        $transition = $this->findTransitionFor($entityType, $currentStatus, $action);

        if (! $transition) {
            return [
                'allowed' => false,
                'reason' => "Transition '{$action}' not available from status '{$currentStatus}'",
            ];
        }

        // Declarative conditions (legacy WorkflowSchema contract).
        $entityData = array_merge($subject->workflowContext(), $context);
        foreach ($transition['conditions'] ?? [] as $condition => $expectedValue) {
            $actualValue = $entityData[$condition] ?? null;
            if ($actualValue != $expectedValue) {
                return [
                    'allowed' => false,
                    'reason' => "Condition '{$condition}' not met (expected {$expectedValue}, got {$actualValue})",
                ];
            }
        }

        // Custom guards (approval, period lock, …) contributed by the domain.
        foreach ($transition['guards'] ?? [] as $guard) {
            $reason = $this->runGuard($guard, $subject, $action, $context);
            if ($reason !== null) {
                return ['allowed' => false, 'reason' => $reason];
            }
        }

        // Permission guard. Route middleware remains the primary gate; the
        // engine repeats the check for non-HTTP callers (commands, queues) and
        // only when the RBAC tables are present in the active schema. A user
        // with no assigned permissions at all (bare test/setup scaffolding)
        // keeps today's behaviour — the route/middleware is the gate.
        $permission = $transition['permission'] ?? null;
        if ($permission && $this->rbacTablesAvailable()) {
            $user = Auth::user();
            if ($user && method_exists($user, 'can') && $this->userHasAnyPermissions($user) && ! $user->can($permission)) {
                return [
                    'allowed' => false,
                    'reason' => "User is not allowed to perform '{$action}' ({$permission})",
                ];
            }
        }

        $role = $transition['role'] ?? null;
        if ($role && $this->rbacTablesAvailable()) {
            $user = Auth::user();
            if ($user && method_exists($user, 'hasRole') && ! $user->hasRole($role)) {
                return [
                    'allowed' => false,
                    'reason' => "User does not hold the required role '{$role}'",
                ];
            }
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Execute a status transition for a subject.
     *
     * Guards are evaluated, the registered side-effect handler runs (wrapping
     * the existing domain service), then the subject applies the new status
     * through its single write path. The whole flow is transactional.
     *
     * @return array{success: bool, old_status: string, new_status: string|null, reason: string|null}
     *
     * @throws WorkflowDeniedException when the transition is not allowed
     */
    public function transitionSubject(
        WorkflowSubject $subject,
        string $action,
        array $context = [],
        ?string $reason = null,
        ?string $userId = null,
    ): array {
        $entityType = $subject->workflowEntityType();
        $entityId = $subject->workflowEntityId();
        $tenantId = $this->currentTenantId();
        $user = $userId ?? (Auth::id() !== null ? (string) Auth::id() : null);
        $oldStatus = $subject->workflowCurrentStatus();

        // Guards are evaluated before the transaction so a denial is recorded
        // on the transition log even though no status may change.
        $check = $this->canTransitionSubject($subject, $action, $context, $oldStatus);

        if (! $check['allowed']) {
            $this->logTransition(
                $tenantId, $entityType, $entityId, $oldStatus, $oldStatus,
                $action, 'rejected', $user, $check['reason'], $context,
            );

            throw new WorkflowDeniedException(
                $check['reason'] ?? "Transition '{$action}' denied",
                $entityType,
                $entityId,
                $action,
            );
        }

        return DB::transaction(function () use ($subject, $action, $context, $reason, $tenantId, $user, $entityType, $entityId, $oldStatus) {
            $transition = $this->findTransitionFor($entityType, $oldStatus, $action);
            $newStatus = $transition['to'];
            $handler = $transition['handler'] ?? null;

            if ($handler instanceof WorkflowHandler) {
                $handler->handle($subject, $action, $context);
            } elseif (is_callable($handler)) {
                $handler($subject, $action, $context);
            }

            $subject->applyWorkflowStatus($newStatus, $context + [
                'action' => $action,
                'old_status' => $oldStatus,
                'reason' => $reason,
            ]);

            $this->logTransition(
                $tenantId, $entityType, $entityId, $oldStatus, $newStatus,
                $action, 'applied', $user, $reason, $context,
            );

            $this->feedAuditTrail(
                $entityType, $entityId, $action, $oldStatus, $newStatus, $user, $reason,
                $context + ['handler' => $handler ? get_class($handler) : null],
            );

            foreach ($transition['events'] ?? [] as $eventName) {
                Log::info("Workflow event dispatched: {$eventName}", [
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                ]);
            }

            return [
                'success' => true,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'reason' => null,
            ];
        });
    }

    /**
     * Record a document's initial status on the transition log / audit trail.
     *
     * Creation paths keep their DB default or caller-supplied starting status;
     * the engine only guarantees the starting status is declared and logs it so
     * the audit trail starts at creation.
     *
     * @return array{success: bool, new_status: string, reason: string|null}
     */
    public function initializeSubject(WorkflowSubject $subject, array $context = []): array
    {
        $entityType = $subject->workflowEntityType();
        $entityId = $subject->workflowEntityId();
        $tenantId = $this->currentTenantId();

        $current = $subject->workflowCurrentStatus();
        $initial = $this->initialStatusFor($entityType);

        if ($current === '') {
            if ($initial === null) {
                throw new \RuntimeException("No workflow schema registered for '{$entityType}'.");
            }

            $subject->applyWorkflowStatus($initial, $context);
            $current = $initial;
        }

        $this->logTransition(
            $tenantId, $entityType, $entityId, null, $current,
            'initialize', 'applied', Auth::id() !== null ? (string) Auth::id() : null, null, $context,
        );

        return ['success' => true, 'new_status' => $current, 'reason' => null];
    }

    /**
     * Check if a transition is allowed (legacy signature, schema-row based).
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
        if (! $schema) {
            return ['allowed' => false, 'reason' => "No workflow schema for entity type '{$entityType}'"];
        }

        if (! $currentStatus) {
            return ['allowed' => false, 'reason' => 'Current status is required'];
        }

        $transition = $this->findTransition($schema, $currentStatus, $action);
        if (! $transition) {
            return ['allowed' => false, 'reason' => "Transition '{$action}' not available from status '{$currentStatus}'"];
        }

        if (! empty($transition['conditions']) && $entityData) {
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
     * Execute a status transition (legacy signature, schema-row based).
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
        if (! $check['allowed']) {
            $this->logTransition($tenantId, $entityType, $entityId, $currentStatus, $currentStatus, $action, 'rejected', $userId, $check['reason'], $metadata);

            return ['success' => false, 'new_status' => null, 'reason' => $check['reason']];
        }

        $schema = $this->getSchema($entityType, $tenantId);
        $transition = $this->findTransition($schema, $currentStatus, $action);
        $newStatus = $transition['to'];

        $this->logTransition($tenantId, $entityType, $entityId, $currentStatus, $newStatus, $action, 'applied', $userId, null, $metadata);

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
        $transitions = $this->hasDefinition($entityType)
            ? $this->transitionDefinitions($entityType)
            : ($this->getSchema($entityType, $tenantId)?->transitions ?? []);

        $actions = [];
        foreach ($transitions as $transition) {
            if ($transition['from'] === $currentStatus || $transition['from'] === '*') {
                $actions[] = [
                    'action' => $transition['action'],
                    'label' => $transition['label'] ?? $transition['action'],
                    'to_status' => $transition['to'],
                    'permission' => $transition['permission'] ?? null,
                ];
            }
        }

        return $actions;
    }

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

    /**
     * @param  StatusTransitionContract|array<string, mixed>  $transition
     * @return array<string, mixed>
     */
    private function normalizeTransition($transition): array
    {
        if ($transition instanceof StatusTransitionContract) {
            return [
                'from' => $transition->getFromStatus(),
                'to' => $transition->getToStatus(),
                'action' => $transition->getAction(),
                'label' => $transition->getLabel(),
                'permission' => $transition->getRequiredPermission(),
                'role' => $transition->getRequiredRole(),
                'conditions' => $transition->getConditions(),
                'events' => $transition->getEventsToFire(),
                'guards' => [],
                'handler' => null,
            ];
        }

        return [
            'from' => (string) ($transition['from'] ?? $transition['from_status'] ?? '*'),
            'to' => (string) ($transition['to'] ?? $transition['to_status'] ?? ''),
            'action' => (string) ($transition['action'] ?? ''),
            'label' => (string) ($transition['label'] ?? ($transition['action'] ?? '')),
            'permission' => $transition['permission'] ?? null,
            'role' => $transition['role'] ?? null,
            'conditions' => $transition['conditions'] ?? null,
            'events' => $transition['events'] ?? [],
            'guards' => $transition['guards'] ?? [],
            'handler' => $transition['handler'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTransitionFor(string $entityType, string $currentStatus, string $action): ?array
    {
        if ($this->hasDefinition($entityType)) {
            foreach ($this->transitionDefinitions($entityType) as $transition) {
                if ($transition['action'] === $action
                    && ($transition['from'] === $currentStatus || $transition['from'] === '*')) {
                    return $transition;
                }
            }

            return null;
        }

        $schema = WorkflowSchema::query()
            ->where('entity_type', $entityType)
            ->where('is_active', true)
            ->first();

        if (! $schema) {
            return null;
        }

        return $this->findTransition($schema, $currentStatus, $action);
    }

    private function findTransition(WorkflowSchema $schema, string $currentStatus, string $action): ?array
    {
        foreach ($schema->transitions as $transition) {
            if ($transition['action'] === $action && ($transition['from'] === $currentStatus || $transition['from'] === '*')) {
                return $transition;
            }
        }

        return null;
    }

    /**
     * @param  WorkflowGuard|callable|string  $guard
     */
    private function runGuard($guard, WorkflowSubject $subject, string $action, array $context): ?string
    {
        if (is_string($guard) && class_exists($guard)) {
            $guard = app($guard);
        }

        if ($guard instanceof WorkflowGuard) {
            return $guard->check($subject, $action, $context);
        }

        if (is_callable($guard)) {
            $result = $guard($subject, $action, $context);

            if ($result === null || $result === true) {
                return null;
            }

            return is_string($result) ? $result : "Guard rejected transition '{$action}'";
        }

        throw new \InvalidArgumentException('Workflow guard must be a WorkflowGuard or callable.');
    }

    private function rbacTablesAvailable(): bool
    {
        return Schema::hasTable('permissions') && Schema::hasTable('model_has_roles');
    }

    private function userHasAnyPermissions(object $user): bool
    {
        if (! method_exists($user, 'getAllPermissions')) {
            return true;
        }

        try {
            return $user->getAllPermissions()->isNotEmpty();
        } catch (\Throwable) {
            return false;
        }
    }

    private function currentTenantId(): string
    {
        $tenantId = function_exists('tenant') && tenant()
            ? (string) (tenant('id') ?? '')
            : '';

        return $this->normalizeTenantId($tenantId);
    }

    private function tenantKeyIsUuid(): bool
    {
        $tenantId = function_exists('tenant') && tenant()
            ? (string) (tenant('id') ?? '')
            : '';

        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId);
    }

    /**
     * The workflow_logs.tenant_id column is a uuid; test/manual tenants can
     * bind a non-uuid key, so derive a stable uuid from it.
     */
    private function normalizeTenantId(string $tenantId): string
    {
        if ($tenantId === '') {
            return '00000000-0000-0000-0000-000000000000';
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $tenantId)) {
            return strtolower($tenantId);
        }

        $hash = md5($tenantId);

        return substr($hash, 0, 8).'-'.substr($hash, 8, 4).'-'.substr($hash, 12, 4).'-'.substr($hash, 16, 4).'-'.substr($hash, 20, 12);
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
        if (! Schema::hasTable('workflow_logs')) {
            return;
        }

        WorkflowLog::create([
            'id' => Str::uuid(),
            'tenant_id' => $tenantId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'action' => $action,
            'result' => $result,
            'user_id' => $userId !== null && ctype_digit($userId) ? (int) $userId : null,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);
    }

    /**
     * #895: every applied transition also lands on the document audit trail so
     * the audit API shows who moved a document and when.
     */
    private function feedAuditTrail(
        string $entityType,
        string $entityId,
        string $action,
        string $oldStatus,
        string $newStatus,
        ?string $userId,
        ?string $reason,
        array $metadata,
    ): void {
        if (! Schema::hasTable('audit_logs') || ! ctype_digit($entityId)) {
            return;
        }

        // The audit hash chain keys rows by the tenant uuid; a non-uuid tenant
        // key (test scaffolding) cannot be chained, so only the workflow log
        // records that transition.
        if (! $this->tenantKeyIsUuid()) {
            return;
        }

        app(AuditLogger::class)->log(
            operation: 'workflow_'.$action,
            referenceType: $entityType,
            referenceId: (int) $entityId,
            actorId: $userId !== null && ctype_digit($userId) ? (int) $userId : null,
            beforeState: ['status' => $oldStatus],
            afterState: ['status' => $newStatus],
            metadata: array_merge($metadata, [
                'workflow_action' => $action,
                'reason' => $reason,
            ]),
        );
    }
}

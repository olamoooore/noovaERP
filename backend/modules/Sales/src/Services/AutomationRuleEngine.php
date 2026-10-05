<?php

namespace Modules\Sales\Services;

use App\Models\Customer;
use App\Platform\EventBus\Contracts\DomainEventContract;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\Sales\Models\AutomationExecution;
use Modules\Sales\Models\AutomationRule;
use Modules\Sales\Support\AutomationCatalog;

/**
 * AutomationRuleEngine — evaluates #913 automation rules against #901 Sales
 * domain events and executes their actions.
 *
 * Evaluation is a pure function of (conditions, payload) so the dry-run
 * endpoint can reuse it without side effects. Live execution is idempotent
 * per (rule, event): the automation_executions row keyed by the event's
 * `event_id` is the processed marker, so an at-least-once redelivery of the
 * same event never re-runs a rule's actions.
 *
 * Condition fields are whitelisted (AutomationCatalog) and comparison
 * operators are whitelisted; a rule can never reach arbitrary payload keys.
 * `customer_group` and `days_overdue` are virtual fields resolved from the
 * triggering customer / the event's due_date.
 */
class AutomationRuleEngine
{
    public function __construct(
        private readonly AutomationActionExecutor $actions,
    ) {}

    /**
     * Evaluate conditions (all must match) against one event payload.
     *
     * @param  array<int, array<string, mixed>>  $conditions
     * @param  array<string, mixed>  $payload
     * @return array{matched: bool, conditions: array<int, array<string, mixed>>}
     */
    public function evaluate(array $conditions, array $payload): array
    {
        $evaluations = [];
        $matched = true;

        foreach ($conditions as $index => $condition) {
            $field = (string) ($condition['field'] ?? '');
            $operator = (string) ($condition['operator'] ?? '');
            $expected = $condition['value'] ?? null;

            $actual = $this->resolveField($field, $payload);

            $ok = in_array($operator, AutomationCatalog::OPERATORS, true)
                && $this->compare($actual, $operator, $expected);

            $evaluations[] = [
                'index' => $index,
                'field' => $field,
                'operator' => $operator,
                'expected' => $expected,
                'actual' => $actual,
                'matched' => $ok,
            ];

            if (! $ok) {
                $matched = false;
            }
        }

        return ['matched' => $matched, 'conditions' => $evaluations];
    }

    /**
     * Execute a rule for a live event. Creates (or reuses) the execution row
     * and runs every action, recording each action's own result. Never throws
     * for an action failure — the failure is persisted on the execution row.
     */
    public function process(AutomationRule $rule, DomainEventContract $event): AutomationExecution
    {
        $payload = $event->getPayload();
        $eventId = (string) ($payload['event_id'] ?? $event->getCorrelationId());

        $execution = $this->claim($rule, $event, $eventId);

        // Already evaluated (same rule + same event id): idempotent no-op.
        if ($execution->finished_at !== null) {
            return $execution;
        }

        $evaluation = $this->evaluate($rule->conditions ?? [], $payload);

        if (! $evaluation['matched']) {
            $execution->update([
                'status' => AutomationExecution::STATUS_SKIPPED,
                'actions' => [],
                'error' => 'Conditions not matched.',
                'finished_at' => now(),
            ]);

            return $execution;
        }

        $results = [];

        foreach ($rule->actions as $action) {
            $results[] = $this->actions->execute($action, $rule, $event, $execution);
        }

        $failed = array_values(array_filter($results, fn (array $r) => ($r['status'] ?? null) === 'failed'));
        $ok = array_values(array_filter($results, fn (array $r) => in_array(($r['status'] ?? null), ['success', 'skipped'], true)));
        $allSkipped = $results !== []
            && count(array_filter($results, fn (array $r) => ($r['status'] ?? null) === 'skipped')) === count($results);

        $status = match (true) {
            $failed !== [] && $ok === [] => AutomationExecution::STATUS_FAILED,
            $failed !== [] => AutomationExecution::STATUS_PARTIAL,
            $allSkipped => AutomationExecution::STATUS_SKIPPED,
            default => AutomationExecution::STATUS_SUCCESS,
        };

        $execution->update([
            'status' => $status,
            'actions' => $results,
            'error' => $failed === []
                ? null
                : implode(' | ', array_map(fn (array $r) => (string) (($r['type'] ?? 'action').': '.($r['error'] ?? 'failed')), $failed)),
            'finished_at' => now(),
        ]);

        if ($failed !== []) {
            Log::warning('Sales automation rule action failed', [
                'rule_id' => $rule->id,
                'event_id' => $eventId,
                'execution_id' => $execution->id,
                'status' => $status,
                'errors' => array_map(fn (array $r) => $r['error'] ?? null, $failed),
            ]);
        }

        return $execution;
    }

    /**
     * Preview the actions a rule would take for a payload. No side effects.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    public function previewActions(AutomationRule $rule, array $payload, bool $matched): array
    {
        $preview = [];

        foreach ($rule->actions as $action) {
            $type = (string) ($action['type'] ?? 'unknown');

            $preview[] = [
                'type' => $type,
                'would_execute' => $matched,
                'config' => $action,
                'description' => $this->describeAction($type),
            ];
        }

        return $preview;
    }

    /**
     * Resolve a whitelisted condition field for a payload. Virtual fields are
     * resolved from context; payload fields are read directly.
     */
    public function resolveField(string $field, array $payload): mixed
    {
        return match ($field) {
            'customer_group' => $this->customerGroup($payload),
            'days_overdue' => $this->daysOverdue($payload),
            default => $payload[$field] ?? null,
        };
    }

    /**
     * Compare an actual value with a whitelisted operator.
     */
    public function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        if (in_array($operator, AutomationCatalog::VALUELESS_OPERATORS, true)) {
            return match ($operator) {
                AutomationCatalog::OPERATOR_IS_NULL => $actual === null,
                AutomationCatalog::OPERATOR_IS_NOT_NULL => $actual !== null,
                default => false,
            };
        }

        if (in_array($operator, AutomationCatalog::NUMERIC_OPERATORS, true)) {
            if (! is_numeric($actual) || ! is_numeric($expected)) {
                return false;
            }

            $a = (float) $actual;
            $b = (float) $expected;

            return match ($operator) {
                AutomationCatalog::OPERATOR_GT => $a > $b,
                AutomationCatalog::OPERATOR_GTE => $a >= $b,
                AutomationCatalog::OPERATOR_LT => $a < $b,
                AutomationCatalog::OPERATOR_LTE => $a <= $b,
                default => false,
            };
        }

        return match ($operator) {
            AutomationCatalog::OPERATOR_EQ => $this->looselyEquals($actual, $expected),
            AutomationCatalog::OPERATOR_NEQ => ! $this->looselyEquals($actual, $expected),
            AutomationCatalog::OPERATOR_IN => is_array($expected) && $this->inList($actual, $expected),
            AutomationCatalog::OPERATOR_NOT_IN => is_array($expected) && ! $this->inList($actual, $expected),
            AutomationCatalog::OPERATOR_CONTAINS => $actual !== null
                && $expected !== null
                && str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            AutomationCatalog::OPERATOR_NOT_CONTAINS => $actual === null
                || $expected === null
                || ! str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            default => false,
        };
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function claim(AutomationRule $rule, DomainEventContract $event, string $eventId): AutomationExecution
    {
        $payload = $event->getPayload();

        return AutomationExecution::firstOrCreate(
            [
                'rule_id' => $rule->id,
                'event_id' => $eventId,
            ],
            [
                'tenant_id' => (string) ($payload['tenant_id'] ?? $event->getTenantId()),
                'rule_version' => $rule->version,
                'event_name' => $event->getEventName(),
                'legal_entity_id' => isset($payload['legal_entity_id']) && $payload['legal_entity_id'] !== null
                    ? (int) $payload['legal_entity_id']
                    : null,
                'document_type' => isset($payload['document_type']) ? (string) $payload['document_type'] : null,
                'document_id' => isset($payload['document_id']) ? (string) $payload['document_id'] : null,
                'status' => AutomationExecution::STATUS_MATCHED,
                'actions' => [],
                'started_at' => now(),
            ]
        );
    }

    private function customerGroup(array $payload): ?string
    {
        $customerId = $payload['customer_id'] ?? null;

        if (! $customerId || ! Schema::hasTable('customers')) {
            return null;
        }

        $group = Customer::query()->whereKey($customerId)->value('customer_group');

        return $group !== null ? (string) $group : null;
    }

    private function daysOverdue(array $payload): ?int
    {
        if (isset($payload['days_overdue']) && is_numeric($payload['days_overdue'])) {
            return max(0, (int) $payload['days_overdue']);
        }

        $dueDate = $payload['due_date'] ?? null;

        if (! $dueDate) {
            return null;
        }

        $due = Carbon::parse((string) $dueDate)->startOfDay();
        $reference = ! empty($payload['occurred_at'])
            ? Carbon::parse((string) $payload['occurred_at'])
            : now();
        $reference = $reference->startOfDay();

        return max(0, (int) floor($due->diffInDays($reference, false)));
    }

    private function looselyEquals(mixed $actual, mixed $expected): bool
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return abs((float) $actual - (float) $expected) < 0.00001;
        }

        if (is_bool($actual) || is_bool($expected)) {
            return (bool) $actual === (bool) $expected;
        }

        if (is_string($actual) && is_string($expected)) {
            return strcasecmp($actual, $expected) === 0;
        }

        return $actual == $expected;
    }

    private function inList(mixed $actual, array $expected): bool
    {
        foreach ($expected as $candidate) {
            if ($this->looselyEquals($actual, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private function describeAction(string $type): string
    {
        return match ($type) {
            AutomationCatalog::ACTION_NOTIFY => 'Notify selected users/roles.',
            AutomationCatalog::ACTION_WHATSAPP_CUSTOMER => 'WhatsApp the customer.',
            AutomationCatalog::ACTION_CREATE_TASK => 'Create a chatter task.',
            AutomationCatalog::ACTION_HOLD_CUSTOMER => 'Put the customer on hold (blocks new order confirmations).',
            AutomationCatalog::ACTION_WEBHOOK => 'POST the event to a webhook.',
            default => 'Unknown action.',
        };
    }
}

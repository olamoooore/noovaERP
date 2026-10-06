<?php

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Sales\Models\AutomationRule;
use Modules\Sales\Models\AutomationRuleVersion;
use Modules\Sales\Models\SalesEventOutboxEvent;

/**
 * AutomationRuleService — CRUD, versioning and dry-run orchestration for
 * #913 automation rules.
 *
 * Every write appends an immutable snapshot to automation_rule_versions and
 * bumps `automation_rules.version`, so history is never rewritten. The
 * dry-run path reuses the engine's pure evaluation and preview — it never
 * creates an execution row and never runs an action.
 */
class AutomationRuleService
{
    public function __construct(
        private readonly AutomationRuleEngine $engine,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?int $actorId): AutomationRule
    {
        return DB::transaction(function () use ($data, $actorId) {
            $rule = AutomationRule::create([
                'tenant_id' => $this->tenantId(),
                'legal_entity_id' => (int) $data['legal_entity_id'],
                'name' => $data['name'],
                'event_name' => $data['event_name'],
                'conditions' => array_values($data['conditions'] ?? []),
                'actions' => array_values($data['actions'] ?? []),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'version' => 1,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);

            $this->snapshot($rule, $actorId);

            return $rule;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(AutomationRule $rule, array $data, ?int $actorId): AutomationRule
    {
        return DB::transaction(function () use ($rule, $data, $actorId) {
            $rule->fill([
                'name' => $data['name'] ?? $rule->name,
                'event_name' => $data['event_name'] ?? $rule->event_name,
                'conditions' => array_values($data['conditions'] ?? $rule->conditions ?? []),
                'actions' => array_values($data['actions'] ?? $rule->actions ?? []),
                'is_active' => array_key_exists('is_active', $data) ? (bool) $data['is_active'] : $rule->is_active,
                'updated_by' => $actorId,
            ]);

            $rule->version = (int) $rule->version + 1;
            $rule->save();

            $this->snapshot($rule, $actorId);

            return $rule;
        });
    }

    /**
     * Dry-run: evaluate the rule against a supplied payload, or against the
     * latest emitted event of the rule's type/entity when none is supplied.
     * No execution row, no action side effects.
     *
     * @return array<string, mixed>
     */
    public function dryRun(AutomationRule $rule, ?array $payload): array
    {
        $payload ??= $this->latestPayload($rule);

        if ($payload === null) {
            throw ValidationException::withMessages([
                'payload' => 'No payload supplied and no event of this type has been emitted for this entity yet.',
            ]);
        }

        $evaluation = $this->engine->evaluate($rule->conditions ?? [], $payload);

        return [
            'rule_id' => (int) $rule->id,
            'rule_version' => (int) $rule->version,
            'event_name' => $rule->event_name,
            'matched' => $evaluation['matched'],
            'conditions' => $evaluation['conditions'],
            'actions' => $this->engine->previewActions($rule, $payload, $evaluation['matched']),
            'event' => $payload,
            'side_effects' => false,
        ];
    }

    private function snapshot(AutomationRule $rule, ?int $actorId): AutomationRuleVersion
    {
        return AutomationRuleVersion::create([
            'rule_id' => $rule->id,
            'version' => $rule->version,
            'name' => $rule->name,
            'event_name' => $rule->event_name,
            'conditions' => $rule->conditions ?? [],
            'actions' => $rule->actions ?? [],
            'is_active' => (bool) $rule->is_active,
            'changed_by' => $actorId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestPayload(AutomationRule $rule): ?array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('sales_event_outbox')) {
            return null;
        }

        $rows = SalesEventOutboxEvent::query()
            ->where('event_name', $rule->event_name)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        foreach ($rows as $row) {
            $payload = $row->payload ?? [];
            $eventEntity = $payload['legal_entity_id'] ?? null;

            if ($eventEntity !== null && (int) $eventEntity === (int) $rule->legal_entity_id) {
                return $payload;
            }
        }

        return null;
    }

    private function tenantId(): string
    {
        return function_exists('tenant') && tenant()
            ? (string) (tenant('id') ?? '')
            : '00000000-0000-0000-0000-000000000000';
    }
}

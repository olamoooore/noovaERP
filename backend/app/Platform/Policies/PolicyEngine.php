<?php

namespace App\Platform\Policies;

use App\Platform\Policies\Contracts\PolicyEngineContract;
use Illuminate\Support\Str;

class PolicyEngine implements PolicyEngineContract
{
    public function register(string $entityType, string $action, array $rules, int $priority = 0): object
    {
        $rule = PolicyRule::create([
            'id' => Str::uuid(),
            'tenant_id' => tenant()->id,
            'entity_type' => $entityType,
            'action' => $action,
            'rules' => $rules,
            'priority' => $priority,
            'is_active' => true,
        ]);

        return (object) $rule->toArray();
    }

    public function evaluate(string $entityType, string $action, array $context): PolicyResult
    {
        $rules = PolicyRule::where('tenant_id', tenant()->id)
            ->active()
            ->forEntity($entityType)
            ->where('action', $action)
            ->orderBy('priority', 'desc')
            ->get();

        foreach ($rules as $rule) {
            if ($this->evaluateRule($rule->rules, $context)) {
                $effect = $rule->rules['effect'] ?? 'deny';
                if ($effect === 'deny') {
                    return PolicyResult::deny(
                        $rule->rules['message'] ?? 'Denied by policy',
                        ['rule_id' => $rule->id, 'priority' => $rule->priority]
                    );
                }
            }
        }

        return PolicyResult::allow();
    }

    public function discover(string $entityType): array
    {
        return PolicyRule::where('tenant_id', tenant()->id)
            ->forEntity($entityType)
            ->orderBy('priority', 'desc')
            ->get()
            ->toArray();
    }

    public function deactivate($ruleId): bool
    {
        $rule = PolicyRule::where('tenant_id', tenant()->id)
            ->findOrFail((string) $ruleId);

        return $rule->update(['is_active' => false]);
    }

    private function evaluateRule(array $rules, array $context): bool
    {
        $conditions = $rules['conditions'] ?? [];

        foreach ($conditions as $condition) {
            $field = $condition['field'] ?? '';
            $operator = $condition['operator'] ?? 'equals';
            $value = $condition['value'] ?? null;

            $contextValue = $context[$field] ?? null;

            switch ($operator) {
                case 'equals':
                    if ($contextValue !== $value) {
                        return false;
                    }
                    break;
                case 'not_equals':
                    if ($contextValue === $value) {
                        return false;
                    }
                    break;
                case 'in':
                    if (! is_array($value) || ! in_array($contextValue, $value)) {
                        return false;
                    }
                    break;
                case 'greater_than':
                    if ($contextValue <= $value) {
                        return false;
                    }
                    break;
                case 'less_than':
                    if ($contextValue >= $value) {
                        return false;
                    }
                    break;
            }
        }

        return true;
    }
}

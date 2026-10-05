<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AutomationRule — a no-code rule over the #901 Sales event catalogue
 * (issue #913).
 *
 * A rule is scoped to one legal entity, matches one catalogue event name and
 * carries an ordered list of conditions (all must match) plus an ordered list
 * of actions. Edits never mutate history: `version` is bumped and the new
 * state is snapshotted into automation_rule_versions.
 *
 * @property array<int, array{field: string, operator: string, value?: mixed}> $conditions
 * @property array<int, array{type: string, ...}> $actions
 */
class AutomationRule extends Model
{
    protected $table = 'automation_rules';

    protected $guarded = [];

    protected $casts = [
        'conditions' => 'array',
        'actions' => 'array',
        'is_active' => 'boolean',
        'version' => 'integer',
        'legal_entity_id' => 'integer',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(AutomationRuleVersion::class, 'rule_id')->orderByDesc('version');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AutomationExecution::class, 'rule_id')->orderByDesc('created_at');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForEvent($query, string $eventName)
    {
        return $query->where('event_name', $eventName);
    }

    public function scopeForEntity($query, int $legalEntityId)
    {
        return $query->where('legal_entity_id', $legalEntityId);
    }
}

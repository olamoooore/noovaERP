<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AutomationRuleVersion — immutable snapshot of an AutomationRule at one
 * version (issue #913). The live rule plus this history satisfy the
 * "versioned" acceptance criterion and let the UI show what changed.
 */
class AutomationRuleVersion extends Model
{
    protected $table = 'automation_rule_versions';

    protected $guarded = [];

    protected $casts = [
        'conditions' => 'array',
        'actions' => 'array',
        'is_active' => 'boolean',
        'version' => 'integer',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }
}

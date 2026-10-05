<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AutomationExecution — the execution log entry for one (rule, event)
 * evaluation (issue #913).
 *
 * The unique (rule_id, event_id) is the consumer-side idempotency key: an
 * at-least-once redelivery of the same sales event never executes the rule's
 * actions twice. `status` records the terminal outcome; `actions` keeps each
 * action's own result so a failed WhatsApp/webhook leg is visible instead of
 * being swallowed.
 *
 * @property array<int, array<string, mixed>>|null $actions
 */
class AutomationExecution extends Model
{
    public const STATUS_MATCHED = 'matched';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'automation_executions';

    protected $guarded = [];

    protected $casts = [
        'actions' => 'array',
        'rule_version' => 'integer',
        'legal_entity_id' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }
}

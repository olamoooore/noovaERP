<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EscalationEvent extends Model
{
    use HasUuids;

    protected $table = 'fco_escalation_events';

    protected $fillable = [
        'tenant_id',
        'task_id',
        'escalation_level',
        'status',
        'escalated_to',
        'reason',
        'resolved_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(CloseTask::class, 'task_id');
    }
}
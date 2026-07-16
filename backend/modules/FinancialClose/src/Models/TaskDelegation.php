<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskDelegation extends Model
{
    use HasUuids;

    protected $table = 'close_task_delegations';

    protected $fillable = [
        'tenant_id',
        'task_id',
        'delegated_from',
        'delegated_to',
        'effective_from',
        'effective_to',
        'status',
        'reason',
    ];

    protected $casts = [
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(CloseTask::class, 'task_id');
    }
}

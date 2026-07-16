<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskAssignment extends Model
{
    use HasUuids;

    protected $table = 'fco_task_assignments';

    protected $fillable = [
        'tenant_id',
        'task_id',
        'assigned_to',
        'assigned_by',
        'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(CloseTask::class, 'task_id');
    }
}

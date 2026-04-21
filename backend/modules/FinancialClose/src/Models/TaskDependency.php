<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskDependency extends Model
{
    use HasUuids;

    protected $table = 'fco_task_dependencies';

    protected $fillable = [
        'tenant_id',
        'task_id',
        'depends_on_task_id',
        'dependency_type',
    ];

    protected $casts = [];

    public function task(): BelongsTo
    {
        return $this->belongsTo(CloseTask::class, 'task_id');
    }

    public function dependsOnTask(): BelongsTo
    {
        return $this->belongsTo(CloseTask::class, 'depends_on_task_id');
    }
}
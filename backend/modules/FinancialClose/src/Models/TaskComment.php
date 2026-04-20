<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskComment extends Model
{
    use HasUuids;

    protected $table = 'fco_task_comments';

    protected $fillable = [
        'tenant_id',
        'task_id',
        'author_id',
        'body',
    ];

    protected $casts = [];

    public function task(): BelongsTo
    {
        return $this->belongsTo(CloseTask::class, 'task_id');
    }
}
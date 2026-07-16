<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CloseTask extends Model
{
    use HasUuids;

    protected $table = 'fco_tasks';

    protected $fillable = [
        'tenant_id',
        'cycle_id',
        'title',
        'status',
        'priority',
        'assigned_to',
        'due_date',
        'is_critical',
        'metadata',
    ];

    protected $casts = [
        'is_critical' => 'boolean',
        'metadata' => 'array',
        'due_date' => 'datetime',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(CloseCycle::class, 'cycle_id');
    }

    public function dependencies(): HasMany
    {
        return $this->hasMany(TaskDependency::class, 'task_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class, 'task_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TaskComment::class, 'task_id');
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(EscalationEvent::class, 'task_id');
    }
}

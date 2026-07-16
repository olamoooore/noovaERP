<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentWorkloadSnapshot extends Model
{
    use HasUuids;

    protected $table = 'fco_assignment_workload_snapshots';

    protected $fillable = [
        'tenant_id',
        'cycle_id',
        'user_id',
        'assigned_tasks',
        'completed_tasks',
        'overdue_tasks',
        'workload_score',
        'generated_at',
    ];

    protected $casts = [
        'assigned_tasks' => 'integer',
        'completed_tasks' => 'integer',
        'overdue_tasks' => 'integer',
        'workload_score' => 'float',
        'generated_at' => 'datetime',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(CloseCycle::class, 'cycle_id');
    }
}

<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompletionSnapshot extends Model
{
    use HasUuids;

    protected $table = 'fco_completion_snapshots';

    protected $fillable = [
        'tenant_id',
        'cycle_id',
        'total_tasks',
        'completed_tasks',
        'blocked_tasks',
        'overdue_tasks',
        'completion_percentage',
        'metadata',
        'generated_at',
    ];

    protected $casts = [
        'total_tasks' => 'integer',
        'completed_tasks' => 'integer',
        'blocked_tasks' => 'integer',
        'overdue_tasks' => 'integer',
        'completion_percentage' => 'float',
        'metadata' => 'array',
        'generated_at' => 'datetime',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(CloseCycle::class, 'cycle_id');
    }
}
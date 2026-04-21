<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsolidationTask extends Model
{
    use HasUuids;

    protected $table = 'fco_consolidation_tasks';

    protected $fillable = [
        'tenant_id',
        'group_cycle_id',
        'title',
        'status',
        'assigned_to',
        'due_date',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'due_date' => 'datetime',
    ];

    public function groupCycle(): BelongsTo
    {
        return $this->belongsTo(GroupCloseCycle::class, 'group_cycle_id');
    }
}
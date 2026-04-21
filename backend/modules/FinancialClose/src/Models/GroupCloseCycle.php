<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupCloseCycle extends Model
{
    use HasUuids;

    protected $table = 'fco_group_close_cycles';

    protected $fillable = [
        'tenant_id',
        'group_id',
        'period_id',
        'status',
        'created_by',
        'opened_at',
        'closed_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function dependencies(): HasMany
    {
        return $this->hasMany(GroupCycleDependency::class, 'group_cycle_id');
    }

    public function consolidationTasks(): HasMany
    {
        return $this->hasMany(ConsolidationTask::class, 'group_cycle_id');
    }

    public function intercompanyDependencies(): HasMany
    {
        return $this->hasMany(IntercompanyCloseDependency::class, 'group_cycle_id');
    }
}
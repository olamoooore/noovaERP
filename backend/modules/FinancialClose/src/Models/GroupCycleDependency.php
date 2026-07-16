<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupCycleDependency extends Model
{
    use HasUuids;

    protected $table = 'fco_group_cycle_dependencies';

    protected $fillable = [
        'tenant_id',
        'group_cycle_id',
        'depends_on_cycle_id',
        'dependency_type',
    ];

    protected $casts = [];

    public function groupCycle(): BelongsTo
    {
        return $this->belongsTo(GroupCloseCycle::class, 'group_cycle_id');
    }

    public function dependsOnCycle(): BelongsTo
    {
        return $this->belongsTo(CloseCycle::class, 'depends_on_cycle_id');
    }
}

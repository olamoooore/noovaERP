<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntercompanyCloseDependency extends Model
{
    use HasUuids;

    protected $table = 'fco_intercompany_close_dependencies';

    protected $fillable = [
        'tenant_id',
        'group_cycle_id',
        'from_entity_id',
        'to_entity_id',
        'dependency_type',
        'status',
    ];

    protected $casts = [];

    public function groupCycle(): BelongsTo
    {
        return $this->belongsTo(GroupCloseCycle::class, 'group_cycle_id');
    }
}
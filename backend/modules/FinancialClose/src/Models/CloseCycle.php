<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CloseCycle extends Model
{
    use HasUuids;

    protected $table = 'fco_close_cycles';

    protected $fillable = [
        'tenant_id',
        'entity_id',
        'period_id',
        'template_id',
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

    public function tasks(): HasMany
    {
        return $this->hasMany(CloseTask::class, 'cycle_id');
    }

    public function completionSnapshots(): HasMany
    {
        return $this->hasMany(CompletionSnapshot::class, 'cycle_id');
    }
}
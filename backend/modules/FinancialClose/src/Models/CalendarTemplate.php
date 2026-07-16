<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarTemplate extends Model
{
    use HasUuids;

    protected $table = 'close_calendar_templates';

    protected $fillable = [
        'tenant_id',
        'name',
        'status',
        'calendar_mode',
        'milestone_definitions',
        'metadata',
    ];

    protected $casts = [
        'milestone_definitions' => 'array',
        'metadata' => 'array',
    ];

    public function milestones(): HasMany
    {
        return $this->hasMany(CalendarMilestone::class, 'calendar_template_id');
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(CalendarOverride::class, 'calendar_template_id');
    }
}

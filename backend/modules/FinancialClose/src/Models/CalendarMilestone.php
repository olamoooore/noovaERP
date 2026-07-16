<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarMilestone extends Model
{
    use HasUuids;

    protected $table = 'close_calendar_milestones';

    protected $fillable = [
        'tenant_id',
        'calendar_template_id',
        'name',
        'offset_days',
        'milestone_type',
        'is_critical',
        'metadata',
    ];

    protected $casts = [
        'offset_days' => 'integer',
        'is_critical' => 'boolean',
        'metadata' => 'array',
    ];

    public function calendarTemplate(): BelongsTo
    {
        return $this->belongsTo(CalendarTemplate::class, 'calendar_template_id');
    }
}

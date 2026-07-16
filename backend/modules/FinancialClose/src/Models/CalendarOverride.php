<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarOverride extends Model
{
    use HasUuids;

    protected $table = 'close_calendar_overrides';

    protected $fillable = [
        'tenant_id',
        'calendar_template_id',
        'override_type',
        'override_date',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'override_date' => 'date',
    ];

    public function calendarTemplate(): BelongsTo
    {
        return $this->belongsTo(CalendarTemplate::class, 'calendar_template_id');
    }
}

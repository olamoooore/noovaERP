<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDailySummary extends Model
{
    protected $table = 'hrm_attendance_daily_summaries';

    protected $fillable = [
        'employee_id',
        'date',
        'first_check_in',
        'last_check_out',
        'worked_minutes',
        'status',
        'has_exception',
        'exception_type',
    ];

    protected $casts = [
        'date'          => 'date',
        'worked_minutes' => 'integer',
        'has_exception'  => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

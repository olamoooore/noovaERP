<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceEvent extends Model
{
    protected $table = 'hrm_attendance_events';

    protected $fillable = [
        'employee_id',
        'subject_id',
        'device_id',
        'location_id',
        'event_type',
        'captured_at',
        'source_event_id',
        'status',
        'meta',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
        'meta' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(EmployeeBiometricSubject::class, 'subject_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }
}

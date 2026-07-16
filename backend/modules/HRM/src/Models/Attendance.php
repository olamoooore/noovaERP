<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HRM\Database\Factories\AttendanceFactory;

class Attendance extends Model
{
    use HasFactory;

    protected static function newFactory(): AttendanceFactory
    {
        return AttendanceFactory::new();
    }

    protected $table = 'hrm_attendances';

    protected $fillable = [
        'employee_id',
        'date',
        'check_in',
        'check_out',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

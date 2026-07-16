<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HRM\Database\Factories\LeaveRequestFactory;

class LeaveRequest extends Model
{
    use HasFactory;

    protected static function newFactory(): LeaveRequestFactory
    {
        return LeaveRequestFactory::new();
    }

    protected $table = 'hrm_leave_requests';

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'employee_id',
        'start_date',
        'end_date',
        'leave_type',
        'status',
        'message',
        'feedback',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeBiometricSubject extends Model
{
    use SoftDeletes;

    protected $table = 'hrm_employee_biometric_subjects';

    protected $fillable = [
        'employee_id',
        'subject_code',
        'type',
        'status',
    ];

    protected $casts = [
        'deleted_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}

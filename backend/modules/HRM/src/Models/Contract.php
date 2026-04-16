<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\HRM\Database\Factories\ContractFactory;

class Contract extends Model
{
    use HasFactory, SoftDeletes;

    protected static function newFactory(): ContractFactory
    {
        return ContractFactory::new();
    }

    protected $table = 'hrm_contracts';

    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = [
        'employee_id',
        'job_title',
        'employment_type',
        'status',
        'base_monthly_salary',
        'overtime_eligible',
        'timeshift_id',
        'fixed_working_days',
        'paid_leaves',
        'start_date',
        'end_date',
        'termination_reason',
    ];

    protected $casts = [
        'base_monthly_salary' => 'decimal:2',
        'overtime_eligible'   => 'boolean',
        'fixed_working_days'  => 'integer',
        'paid_leaves'         => 'integer',
        'start_date'          => 'date',
        'end_date'            => 'date',
        'deleted_at'          => 'datetime',
    ];

    // Relations
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function timeshift(): BelongsTo
    {
        return $this->belongsTo(Timeshift::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}

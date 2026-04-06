<?php

namespace Modules\HRM\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payroll extends Model
{
    use SoftDeletes;

    protected $table = 'hrm_payrolls';

    protected $fillable = [
        'payroll_run_id',
        'employee_id',
        'contract_id',
        'period_start',
        'period_end',
        'base_monthly_salary',
        'net_pay',
        'policy_snapshot',
        'status',
        'generated_by',
    ];

    protected $casts = [
        'period_start'        => 'date',
        'period_end'          => 'date',
        'base_monthly_salary' => 'decimal:2',
        'net_pay'             => 'decimal:2',
        'policy_snapshot'     => 'array',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class, 'payroll_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}

<?php

namespace Modules\Contact\src\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HrStaffProfile extends Model
{
    use HasFactory;

    protected $table = 'hr_staff_profiles';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';

    protected $fillable = [
        'id', 'tenant_id', 'contact_id', 'employee_id', 'employment_status',
        'employment_type', 'hire_date', 'termination_date', 'department_id',
        'position_id', 'manager_id', 'work_location', 'salary',
        'salary_currency', 'pay_frequency', 'emergency_contacts',
        'bank_details', 'metadata',
    ];

    protected $casts = [
        'hire_date' => 'date',
        'termination_date' => 'date',
        'salary' => 'decimal:2',
        'emergency_contacts' => 'array',
        'bank_details' => 'array',
        'metadata' => 'array',
    ];

    public function contact()
    {
        return $this->belongsTo(CoreContact::class, 'contact_id');
    }
}

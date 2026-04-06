<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;
use App\Models\Department;
use App\Models\Location;
use Modules\HRM\Database\Factories\EmployeeFactory;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected static function newFactory(): EmployeeFactory
    {
        return EmployeeFactory::new();
    }

    protected $table = 'hrm_employees';

    protected $fillable = [
        'employee_number',
        'user_id',
        'department_id',
        'location_id',
        'manager_id',
        'name',
        'email',
        'contact_number',
        'status',
    ];

    protected $casts = [
        'deleted_at' => 'datetime',
    ];

    // Auto-generate employee_number before creating
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Employee $employee) {
            if (empty($employee->employee_number)) {
                $last = static::withTrashed()->max('id') ?? 0;
                $employee->employee_number = 'E' . str_pad($last + 1, 4, '0', STR_PAD_LEFT);
            }
        });
    }

    // Relations
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_id');
    }

    public function directReports(): HasMany
    {
        return $this->hasMany(Employee::class, 'manager_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'employee_id');
    }

    public function userLinks(): HasMany
    {
        return $this->hasMany(EmployeeUserLink::class, 'employee_id');
    }

    public function biometricSubjects(): HasMany
    {
        return $this->hasMany(EmployeeBiometricSubject::class, 'employee_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}

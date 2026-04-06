<?php

namespace Modules\HRM\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeUserLink extends Model
{
    protected $table = 'hrm_employee_user_links';

    protected $fillable = [
        'employee_id',
        'user_id',
        'type',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

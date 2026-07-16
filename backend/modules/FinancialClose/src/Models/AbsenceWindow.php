<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AbsenceWindow extends Model
{
    use HasUuids;

    protected $table = 'fco_absence_windows';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'start_date',
        'end_date',
        'absence_type',
        'is_approved',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_approved' => 'boolean',
    ];
}

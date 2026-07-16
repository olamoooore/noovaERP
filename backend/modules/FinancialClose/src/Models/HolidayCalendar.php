<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HolidayCalendar extends Model
{
    use HasUuids;

    protected $table = 'close_holiday_calendars';

    protected $fillable = [
        'tenant_id',
        'name',
        'country_code',
        'holidays',
    ];

    protected $casts = [
        'holidays' => 'array',
    ];
}

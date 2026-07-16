<?php

namespace Modules\HRM\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\HRM\Database\Factories\TimeshiftFactory;

class Timeshift extends Model
{
    use HasFactory;

    protected static function newFactory(): TimeshiftFactory
    {
        return TimeshiftFactory::new();
    }

    protected $table = 'hrm_timeshifts';

    protected $fillable = [
        'name',
        'schedule',
        'is_default',
    ];

    protected $casts = [
        'schedule' => 'array',
        'is_default' => 'boolean',
    ];

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}

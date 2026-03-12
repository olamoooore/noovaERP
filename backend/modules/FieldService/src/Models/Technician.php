<?php

namespace Modules\FieldService\Models;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Technician extends Model
{
    protected $table = 'fs_technicians';

    protected $fillable = [
        'user_id',
        'team_id',
        'skills',
        'certifications',
        'default_warehouse_id',
        'hourly_rate',
        'vehicle_registration',
        'is_active',
    ];

    protected $casts = [
        'skills'         => 'array',
        'certifications' => 'array',
        'hourly_rate'    => 'decimal:2',
        'is_active'      => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function defaultWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_warehouse_id');
    }
}

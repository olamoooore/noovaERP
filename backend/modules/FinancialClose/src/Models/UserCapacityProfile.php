<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class UserCapacityProfile extends Model
{
    use HasUuids;

    protected $table = 'fco_user_capacity_profiles';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'max_tasks',
        'current_tasks',
        'entity_id',
        'metadata',
    ];

    protected $casts = [
        'max_tasks' => 'integer',
        'current_tasks' => 'integer',
        'metadata' => 'array',
    ];
}
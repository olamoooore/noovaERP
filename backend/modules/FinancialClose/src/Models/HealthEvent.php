<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HealthEvent extends Model
{
    use HasUuids;

    protected $table = 'fco_health_events';

    protected $fillable = [
        'tenant_id',
        'event_type',
        'severity',
        'status',
        'description',
        'metadata',
        'resolved_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'resolved_at' => 'datetime',
    ];
}

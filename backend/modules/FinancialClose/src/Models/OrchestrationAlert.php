<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class OrchestrationAlert extends Model
{
    use HasUuids;

    protected $table = 'fco_orchestration_alerts';

    protected $fillable = [
        'tenant_id',
        'alert_type',
        'severity',
        'status',
        'message',
        'metadata',
        'resolved_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'resolved_at' => 'datetime',
    ];
}
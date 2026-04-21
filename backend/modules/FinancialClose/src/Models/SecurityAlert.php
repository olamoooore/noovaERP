<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class SecurityAlert extends Model
{
    use HasUuids;

    protected $table = 'fco_security_alerts';

    protected $fillable = [
        'tenant_id',
        'alert_type',
        'severity',
        'status',
        'triggered_by',
        'description',
        'metadata',
        'resolved_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'resolved_at' => 'datetime',
    ];
}
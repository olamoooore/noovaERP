<?php

declare(strict_types=1);

namespace App\SuperAdmin\Domain;

use Illuminate\Database\Eloquent\Model;

class TenantHealthSnapshot extends Model
{
    public $timestamps = false;

    protected $table = 'tenant_health_snapshots';

    protected $fillable = [
        'tenant_id',
        'db_connected',
        'storage_usage_mb',
        'last_login_at',
        'error_rate_percent',
        'response_time_ms',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'db_connected' => 'boolean',
        'metadata' => 'array',
        'last_login_at' => 'datetime',
        'created_at' => 'datetime',
        'error_rate_percent' => 'decimal:2',
    ];
}

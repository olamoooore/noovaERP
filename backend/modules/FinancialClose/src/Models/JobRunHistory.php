<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class JobRunHistory extends Model
{
    use HasUuids;

    protected $table = 'fco_job_run_history';

    protected $fillable = [
        'tenant_id',
        'job_type',
        'status',
        'started_at',
        'completed_at',
        'duration_ms',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_ms' => 'integer',
        'metadata' => 'array',
    ];
}
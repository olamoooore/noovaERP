<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AccessDecisionLog extends Model
{
    use HasUuids;

    protected $table = 'fco_access_decision_log';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'action',
        'resource_type',
        'resource_id',
        'decision',
        'denial_reason',
        'metadata',
    ];

    protected $casts = [
        'decision' => 'boolean',
        'metadata' => 'array',
    ];
}

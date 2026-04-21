<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class CloseReadinessSnapshot extends Model
{
    use HasUuids;

    protected $table = 'fci_close_readiness_snapshots';

    protected $fillable = [
        'tenant_id',
        'entity_id',
        'period_id',
        'readiness_score',
        'total_accounts',
        'reviewed_accounts',
        'signed_off_accounts',
        'open_blockers',
        'open_findings',
        'section_breakdown',
        'metadata',
        'generated_at',
    ];

    protected $casts = [
        'readiness_score' => 'float',
        'total_accounts' => 'integer',
        'reviewed_accounts' => 'integer',
        'signed_off_accounts' => 'integer',
        'open_blockers' => 'integer',
        'open_findings' => 'integer',
        'section_breakdown' => 'array',
        'metadata' => 'array',
        'generated_at' => 'datetime',
    ];
}
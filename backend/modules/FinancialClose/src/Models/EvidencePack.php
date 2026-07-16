<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EvidencePack extends Model
{
    use HasUuids;

    protected $table = 'fci_evidence_packs';

    protected $fillable = [
        'tenant_id',
        'entity_id',
        'period_id',
        'status',
        'generated_by',
        'generated_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'generated_at' => 'datetime',
    ];
}

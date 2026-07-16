<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceFreezeSnapshot extends Model
{
    use HasUuids;

    protected $table = 'fco_evidence_freeze_snapshots';

    protected $fillable = [
        'tenant_id',
        'signoff_id',
        'evidence_id',
        'frozen_by',
        'frozen_at',
        'snapshot',
    ];

    protected $casts = [
        'frozen_at' => 'datetime',
        'snapshot' => 'array',
    ];

    public function signoff(): BelongsTo
    {
        return $this->belongsTo(Signoff::class, 'signoff_id');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(SupportingEvidence::class, 'evidence_id');
    }
}

<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvidenceVersionHistory extends Model
{
    use HasUuids;

    protected $table = 'fco_evidence_version_history';

    protected $fillable = [
        'tenant_id',
        'evidence_id',
        'version_number',
        'file_path',
        'uploaded_by',
        'metadata',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'metadata' => 'array',
    ];

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(SupportingEvidence::class, 'evidence_id');
    }
}

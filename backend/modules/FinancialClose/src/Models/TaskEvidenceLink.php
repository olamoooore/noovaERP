<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskEvidenceLink extends Model
{
    use HasUuids;

    protected $table = 'fco_task_evidence_links';

    protected $fillable = [
        'tenant_id',
        'task_id',
        'evidence_id',
    ];

    protected $casts = [];

    public function task(): BelongsTo
    {
        return $this->belongsTo(CloseTask::class, 'task_id');
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(SupportingEvidence::class, 'evidence_id');
    }
}
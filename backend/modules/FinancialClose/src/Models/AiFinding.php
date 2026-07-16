<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AiFinding extends Model
{
    use HasUuids;

    protected $table = 'fci_ai_findings';

    protected $fillable = [
        'tenant_id',
        'review_id',
        'entity_id',
        'account_code',
        'finding_type',
        'status',
        'risk_status',
        'confidence',
        'description',
        'structured_output',
        'accepted_by',
        'accepted_at',
        'dismissed_by',
        'dismissed_at',
        'metadata',
    ];

    protected $casts = [
        'confidence' => 'float',
        'structured_output' => 'array',
        'metadata' => 'array',
        'accepted_at' => 'datetime',
        'dismissed_at' => 'datetime',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(AccountReview::class, 'review_id');
    }

    public function feedback(): HasMany
    {
        return $this->hasMany(AiFeedback::class, 'finding_id');
    }
}

<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportingEvidence extends Model
{
    use HasUuids;

    protected $table = 'fci_supporting_evidence';

    protected $fillable = [
        'tenant_id',
        'review_id',
        'file_name',
        'file_path',
        'status',
        'uploaded_by',
        'expires_at',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'expires_at' => 'datetime',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(AccountReview::class, 'review_id');
    }
}
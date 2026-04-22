<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentCorrectionLink extends Model
{
    protected $fillable = [
        'tenant_id',
        'document_type',
        'document_id',
        'related_document_type',
        'related_document_id',
        'relation_type',
        'created_by',
        'reason_code',
        'reason_text',
        'correction_group_id',
    ];

    protected $casts = [
        'document_id' => 'integer',
        'related_document_id' => 'integer',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
<?php

namespace App\Platform\Numbering;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * NumberingSequence — stores the current sequence counter per prefix.
 *
 * Each document type (invoice, purchase order, etc.) has a sequence
 * with: prefix, padding, current number, and optional reset rules.
 */
class NumberingSequence extends Model
{
    use HasFactory;

    protected $table = 'numbering_sequences';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $primaryKey = 'id';
    protected $guarded = [];

    protected $casts = [
        'current_number' => 'integer',
        'padding' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForDocumentType($query, string $documentType)
    {
        return $query->where('document_type', $documentType);
    }
}

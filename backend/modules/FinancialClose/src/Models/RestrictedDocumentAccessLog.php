<?php

namespace Modules\FinancialClose\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class RestrictedDocumentAccessLog extends Model
{
    use HasUuids;

    protected $table = 'fco_restricted_document_access_log';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'document_type',
        'document_id',
        'action',
        'was_authorized',
        'denial_reason',
        'metadata',
    ];

    protected $casts = [
        'was_authorized' => 'boolean',
        'metadata' => 'array',
    ];
}
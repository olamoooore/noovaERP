<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditQueryResponse extends Model
{
    use HasFactory;

    protected $table = 'audit_query_responses';

    protected $fillable = [
        'query_id',
        'responder_id',
        'responder_role',
        'response',
        'evidence_urls',
    ];

    protected $casts = [
        'evidence_urls' => 'array',
    ];

    public function auditQuery(): BelongsTo
    {
        return $this->belongsTo(AuditQuery::class, 'query_id');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responder_id');
    }
}

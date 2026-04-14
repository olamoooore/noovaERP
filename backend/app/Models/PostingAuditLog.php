<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostingAuditLog extends Model
{
    /**
     * Disable the updated_at column (audit logs are immutable — never updated).
     * Eloquent will still manage created_at automatically.
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'document_type',
        'document_id',
        'journal_entry_id',
        'action',
        'actor_user_id',
        'approval_user_id',
        'reason_code',
        'payload_snapshot',
        // created_at is intentionally excluded from $fillable:
        // Eloquent sets it automatically via timestamps; callers must not override it.
    ];

    protected $casts = [
        'payload_snapshot' => 'array',
        'created_at'       => 'datetime',
    ];
}

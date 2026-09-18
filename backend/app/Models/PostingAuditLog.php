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

    /**
     * Issue #91 (CRITICAL, AUDIT-1): The audit trail for every financial
     * posting/reversal/correction was previously unprotected — a plain
     * Eloquent ->update() or ->delete() succeeded silently. The model's
     * doc-comment claimed "immutable" by convention only.
     *
     * This boot() guard enforces immutability at the Eloquent layer (matching
     * the sibling App\Models.AuditLog pattern). Defence-in-depth via a DB-level
     * trigger is added in migration
     * 2026_09_18_000001_posting_audit_logs_db_level_immutability.php
     * (defence in depth: an attacker bypassing Eloquent via raw DB::table()
     * or direct SQL is still rejected at the DB layer).
     */
    public static function boot()
    {
        parent::boot();

        static::updating(function ($model) {
            throw new \RuntimeException(
                'PostingAuditLog records are immutable and cannot be updated. '
                .'Create a new audit entry instead.'
            );
        });

        static::deleting(function ($model) {
            throw new \RuntimeException(
                'PostingAuditLog records are immutable and cannot be deleted.'
            );
        });
    }

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
        'created_at' => 'datetime',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PostingAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'document_type',
        'document_id',
        'journal_entry_id',
        'action',
        'actor_user_id',
        'approval_user_id',
        'reason_code',
        'payload_snapshot',
        'created_at',
    ];

    protected $casts = [
        'payload_snapshot' => 'array',
        'created_at' => 'datetime',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InboundEmail extends Model
{
    protected $table = 'inbound_emails';

    protected $fillable = [
        'tenant_id',
        'message_id',
        'in_reply_to',
        'from_address',
        'from_name',
        'subject',
        'body_plain',
        'body_html',
        'headers',
        'matched_model',
        'matched_record_id',
        'match_strategy',
        'communication_message_id',
        'received_at',
    ];

    protected $casts = [
        'headers' => 'array',
        'received_at' => 'datetime',
    ];

    public const STRATEGY_SUBJECT_TOKEN = 'subject_token';
    public const STRATEGY_IN_REPLY_TO = 'in_reply_to';
    public const STRATEGY_MANUAL = 'manual';
}

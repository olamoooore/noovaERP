<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Issue #606 — one row per weekly sales-digest send attempt
 * (user + channel + period). `sections` records which lines were included and
 * the exact figures they carried, so the digest can be audited.
 */
class SalesDigestSendLog extends Model
{
    protected $table = 'sales_digest_send_logs';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'period_start',
        'period_end',
        'channel',
        'status',
        'recipient',
        'subject',
        'body',
        'sections',
        'error',
        'sent_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'sections' => 'array',
        'sent_at' => 'datetime',
    ];
}

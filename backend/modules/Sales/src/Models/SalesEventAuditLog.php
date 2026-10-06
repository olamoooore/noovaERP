<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SalesEventAuditLog — read model written by the SalesEventAuditConsumer
 * (issue #901, PC7).
 *
 * The unique `event_id` is the consumer-side processed marker: replaying an
 * event with the same id (at-least-once publisher delivery) is a no-op, so the
 * consumer is idempotent without the bus needing exactly-once transport.
 */
class SalesEventAuditLog extends Model
{
    protected $table = 'sales_event_audit_log';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];
}

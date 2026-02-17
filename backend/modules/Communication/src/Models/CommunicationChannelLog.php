<?php

namespace Modules\Communication\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * CommunicationChannelLog — Delivery tracking and audit log.
 *
 * @property int $id
 * @property string|null $tenant_id
 * @property int|null $outbox_id
 * @property int|null $message_id
 * @property string $channel
 * @property string|null $provider
 * @property string $event
 * @property array|null $event_data
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Carbon\Carbon $occurred_at
 */
class CommunicationChannelLog extends Model
{
    protected $table = 'communication_channels_log';

    protected $fillable = [
        'tenant_id',
        'outbox_id',
        'message_id',
        'channel',
        'provider',
        'event',
        'event_data',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    protected $casts = [
        'event_data' => 'array',
        'occurred_at' => 'datetime',
    ];
}

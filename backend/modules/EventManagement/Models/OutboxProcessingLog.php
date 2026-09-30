<?php

namespace Modules\EventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Processing trail for outbox events (table shipped with the module long
 * before this model existed — OutboxService::logProcessing referenced the
 * missing class and fataled on every consumer log write; P-04e test work
 * surfaced it).
 */
class OutboxProcessingLog extends Model
{
    protected $table = 'outbox_processing_log';

    public $timestamps = false;

    protected $fillable = [
        'outbox_event_id',
        'consumer_name',
        'action',
        'message',
        'context',
        'logged_at',
    ];

    protected $casts = [
        'context' => 'array',
        'logged_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $log): void {
            $log->logged_at ??= now();
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(OutboxEvent::class, 'outbox_event_id');
    }
}

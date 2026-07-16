<?php

namespace App\Platform\EventBus\Events;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * EventRecord — persisted domain event for audit trail and replay.
 *
 * Every event dispatched through the Domain Event Bus is stored here.
 * Enables: event replay, audit trail, debugging, cross-module correlation.
 */
class EventRecord extends Model
{
    use HasFactory;

    protected $table = 'domain_event_records';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'metadata' => 'array',
        'processed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeUnhandled($query)
    {
        return $query->whereNull('processed_at')->whereNull('failed_at');
    }

    public function scopeFailed($query)
    {
        return $query->whereNotNull('failed_at');
    }
}

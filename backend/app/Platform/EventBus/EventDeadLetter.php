<?php

namespace App\Platform\EventBus;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * EventDeadLetter — failed events that require manual intervention.
 *
 * When an event handler fails after retries, the event is moved here
 * for inspection, retry, or archival.
 */
class EventDeadLetter extends Model
{
    use HasFactory;

    protected $table = 'domain_event_dead_letters';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'error' => 'array',
        'retry_count' => 'integer',
        'retried_at' => 'datetime',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }
}

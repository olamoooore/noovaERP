<?php

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SalesEventOutboxEvent — transactional outbox row for a Sales domain event
 * (issue #901, PC7).
 *
 * Written in the same transaction as the sales transition it describes. The
 * `sales:publish-outbox` publisher (or the post-commit hook registered by the
 * emitter) dispatches it onto the platform Domain Event Bus and marks it
 * published. Rows failing publication as a whole accumulate attempts and are
 * dead-lettered explicitly once the budget is spent; consumer failures are
 * dead-lettered by the bus itself (domain_event_dead_letters).
 */
class SalesEventOutboxEvent extends Model
{
    protected $table = 'sales_event_outbox';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'published_at' => 'datetime',
        'dead_lettered_at' => 'datetime',
        'publish_attempts' => 'integer',
    ];

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopePending($query)
    {
        return $query->whereNull('published_at')->whereNull('dead_lettered_at');
    }

    public function scopeDeadLettered($query)
    {
        return $query->whereNotNull('dead_lettered_at');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isDeadLettered(): bool
    {
        return $this->dead_lettered_at !== null;
    }

    public function markPublished(): void
    {
        $this->update([
            'published_at' => now(),
            'last_publish_error' => null,
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'publish_attempts' => $this->publish_attempts + 1,
            'last_publish_error' => $error,
        ]);
    }

    public function markDeadLettered(): void
    {
        $this->update([
            'dead_lettered_at' => now(),
        ]);
    }
}

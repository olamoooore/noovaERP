<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Accounting outbox event for reliable event publication.
 * ADR-005 §62 — state mutation and required outbox event creation must be atomic.
 *
 * Events are written to the outbox within the same transaction as the state mutation.
 * Dispatch may occur asynchronously after commit.
 */
class AccountingOutboxEvent extends Model
{
    protected $table = 'accounting_outbox_events';

    protected $fillable = [
        'tenant_id',
        'event_type',
        'event_version',
        'aggregate_type',
        'aggregate_id',
        'aggregate_version',
        'source_capability',
        'correlation_id',
        'causation_id',
        'event_payload',
        'correlation_id',
        'published_at',
        'publish_attempts',
        'last_publish_error',
    ];

    protected $casts = [
        'event_payload' => 'array',
        'published_at' => 'datetime',
        'publish_attempts' => 'integer',
    ];

    // ── Scopes ────────────────────────────────────────

    public function scopeUnpublished($query)
    {
        return $query->whereNull('published_at');
    }

    public function scopeFailed($query)
    {
        return $query->whereNotNull('last_publish_error')
            ->where('publish_attempts', '>=', 3);
    }

    public function scopeForTenant($query, string $tenantId)
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function scopeForAggregate($query, string $type, string $id)
    {
        return $query->where('aggregate_type', $type)->where('aggregate_id', $id);
    }

    // ── Helpers ─────────────────────────────────────────

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

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function canRetry(): bool
    {
        return !$this->isPublished() && $this->publish_attempts < 5;
    }
}

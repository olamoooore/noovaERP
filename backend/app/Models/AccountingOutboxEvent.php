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
        // Issue #62 (HR62-01): explicit dead-letter disposition + recovery provenance.
        'dead_lettered_at',
        'requeued_at',
        'requeue_count',
        'publish_failure_history',
    ];

    protected $casts = [
        'event_payload' => 'array',
        'published_at' => 'datetime',
        'publish_attempts' => 'integer',
        'dead_lettered_at' => 'datetime',
        'requeued_at' => 'datetime',
        'requeue_count' => 'integer',
        'publish_failure_history' => 'array',
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

    /**
     * Issue #62 (HR62-01): explicitly dead-lettered rows are queryable
     * (operational visibility) instead of being silently excluded.
     */
    public function scopeDeadLettered($query)
    {
        return $query->whereNotNull('dead_lettered_at');
    }

    /**
     * Rows eligible for the publisher: unpublished and not dead-lettered.
     */
    public function scopePending($query)
    {
        return $query->whereNull('published_at')->whereNull('dead_lettered_at');
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
        $history = $this->publish_failure_history ?? [];
        $history[] = [
            'attempt' => $this->publish_attempts + 1,
            'error' => $error,
            'at' => now()->toIso8601String(),
        ];

        $this->update([
            'publish_attempts' => $this->publish_attempts + 1,
            'last_publish_error' => $error,
            'publish_failure_history' => $history,
        ]);
    }

    /**
     * Issue #62 (HR62-01): terminal publisher-failure disposition.
     */
    public function markDeadLettered(): void
    {
        $this->update([
            'dead_lettered_at' => now(),
        ]);
    }

    /**
     * Issue #62 (HR62-01): governed requeue of a dead-lettered row. Attempts
     * reset so the publisher picks it up again; the attempt/error history and
     * the requeue provenance stay recorded.
     */
    public function requeue(): void
    {
        $this->update([
            'dead_lettered_at' => null,
            'requeued_at' => now(),
            'requeue_count' => $this->requeue_count + 1,
            'publish_attempts' => 0,
        ]);
    }

    public function isDeadLettered(): bool
    {
        return $this->dead_lettered_at !== null;
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

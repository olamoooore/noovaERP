<?php

namespace App\Services;

use App\Models\AccountingOutboxEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * G-12 (#274): publishes ACC-009 tax lifecycle events through the shared
 * accounting outbox (same store PostingEngine uses for JournalPosted).
 *
 * No tenant context (unit tests / console without tenancy) = no-op; failures
 * are logged, never allowed to break the underlying fiscal operation.
 */
class TaxOutboxPublisher
{
    public function publish(
        string $eventType,
        string $aggregateId,
        int $version,
        array $payload,
        ?string $correlationId = null,
    ): void {
        if (! function_exists('tenant') || tenant() === null) {
            return;
        }

        $correlation = $correlationId ?? $aggregateId;

        if (! Str::isUuid($correlation)) {
            // correlation_id is a uuid column; composite aggregate keys are not.
            $correlation = null;
        }

        try {
            AccountingOutboxEvent::create([
                'tenant_id' => (string) tenant('id'),
                'event_type' => $eventType,
                'event_version' => '1.0',
                'aggregate_type' => 'TaxDeterminationResult',
                'aggregate_id' => $aggregateId,
                'aggregate_version' => $version,
                'source_capability' => 'ACC-009',
                'correlation_id' => $correlation,
                'event_payload' => $payload,
            ]);
        } catch (\Throwable $e) {
            Log::warning('tax.outbox.publish.failed', [
                'event_type' => $eventType,
                'aggregate_id' => $aggregateId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

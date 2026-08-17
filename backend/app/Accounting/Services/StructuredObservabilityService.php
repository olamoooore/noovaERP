<?php

namespace App\Accounting\Services;

use Illuminate\Support\Facades\Log;

/**
 * Structured observability for Finance domain operations.
 * ADR-005 §65 — every material command and event must support correlation across capabilities.
 */
class StructuredObservabilityService
{
    /**
     * Log a material command with structured telemetry fields.
     */
    public function logCommand(array $context): void
    {
        Log::channel('accounting')->info('Finance command executed', [
            'correlation_id' => $context['correlation_id'] ?? null,
            'causation_id' => $context['causation_id'] ?? null,
            'command_id' => $context['command_id'] ?? null,
            'tenant_id' => $context['tenant_id'] ?? null,
            'entity_id' => $context['entity_id'] ?? null,
            'capability' => $context['capability'] ?? null,
            'aggregate_type' => $context['aggregate_type'] ?? null,
            'aggregate_id' => $context['aggregate_id'] ?? null,
            'actor_id' => $context['actor_id'] ?? null,
            'result' => $context['result'] ?? null,
            'reason_code' => $context['reason_code'] ?? null,
            'duration_ms' => $context['duration_ms'] ?? null,
        ]);
    }

    /**
     * Log a material event with structured telemetry fields.
     */
    public function logEvent(array $context): void
    {
        Log::channel('accounting')->info('Finance event published', [
            'correlation_id' => $context['correlation_id'] ?? null,
            'event_id' => $context['event_id'] ?? null,
            'event_type' => $context['event_type'] ?? null,
            'tenant_id' => $context['tenant_id'] ?? null,
            'entity_id' => $context['entity_id'] ?? null,
            'capability' => $context['capability'] ?? null,
            'aggregate_type' => $context['aggregate_type'] ?? null,
            'aggregate_id' => $context['aggregate_id'] ?? null,
            'actor_id' => $context['actor_id'] ?? null,
            'result' => $context['result'] ?? null,
        ]);
    }

    /**
     * Log a posting operation.
     */
    public function logPosting(array $context): void
    {
        Log::channel('accounting')->info('Posting executed', [
            'correlation_id' => $context['correlation_id'] ?? null,
            'document_type' => $context['document_type'] ?? null,
            'document_id' => $context['document_id'] ?? null,
            'journal_entry_id' => $context['journal_entry_id'] ?? null,
            'tenant_id' => $context['tenant_id'] ?? null,
            'actor_id' => $context['actor_id'] ?? null,
            'result' => $context['result'] ?? null,
            'duration_ms' => $context['duration_ms'] ?? null,
        ]);
    }

    /**
     * Log a reversal operation.
     */
    public function logReversal(array $context): void
    {
        Log::channel('accounting')->info('Reversal executed', [
            'correlation_id' => $context['correlation_id'] ?? null,
            'original_entry_id' => $context['original_entry_id'] ?? null,
            'reversal_entry_id' => $context['reversal_entry_id'] ?? null,
            'mode' => $context['mode'] ?? null,
            'tenant_id' => $context['tenant_id'] ?? null,
            'actor_id' => $context['actor_id'] ?? null,
            'result' => $context['result'] ?? null,
        ]);
    }

    /**
     * Log an authority gate evaluation.
     */
    public function logGateEvaluation(array $context): void
    {
        Log::channel('accounting')->info('Authority gate evaluated', [
            'correlation_id' => $context['correlation_id'] ?? null,
            'snapshot_id' => $context['snapshot_id'] ?? null,
            'requested_action' => $context['requested_action'] ?? null,
            'record_type' => $context['record_type'] ?? null,
            'record_id' => $context['record_id'] ?? null,
            'final_result' => $context['final_result'] ?? null,
            'blocking_reason' => $context['blocking_reason'] ?? null,
            'tenant_id' => $context['tenant_id'] ?? null,
        ]);
    }
}

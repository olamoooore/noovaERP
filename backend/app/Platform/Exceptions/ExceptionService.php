<?php

namespace App\Platform\Exceptions;

use App\Platform\Exceptions\Contracts\ExceptionServiceContract;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExceptionService implements ExceptionServiceContract
{
    public function __construct(
        private ?ExceptionRetryRegistry $retryRegistry = null,
    ) {}

    public function log(string $module, string $exceptionType, string $severity, string $message, ?array $context = null): object
    {
        $record = ExceptionQueue::create([
            'id' => Str::uuid(),
            'tenant_id' => tenant()->id,
            'module' => $module,
            'exception_type' => $exceptionType,
            'severity' => $severity,
            'message' => $message,
            'context' => $context,
            'status' => 'open',
            'retry_count' => 0,
            'max_retries' => 3,
        ]);

        return (object) $record->toArray();
    }

    /**
     * Issue #902: persist a structured, typed business failure with the
     * affected document, legal entity, payload snapshot, owner role and —
     * where the operation is safely retryable — a retry-handler key.
     *
     * @throws \Throwable when the queue write itself fails
     */
    public function raise(ExceptionReport $report): object
    {
        $record = ExceptionQueue::create([
            'id' => Str::uuid(),
            'tenant_id' => tenant()->id,
            'module' => $report->module(),
            'exception_type' => $report->type->value,
            'document_type' => $report->documentType,
            'document_id' => $report->documentId !== null ? (string) $report->documentId : null,
            'legal_entity_id' => $report->legalEntityId,
            'severity' => $report->severity(),
            'owner_role' => $report->ownerRole(),
            'message' => $report->message,
            'context' => $report->payload === [] ? null : $report->payload,
            'retry_handler' => $report->retryHandler(),
            'status' => 'open',
            'retry_count' => 0,
            'max_retries' => 3,
        ]);

        return (object) $record->toArray();
    }

    /**
     * Best-effort variant used inside operational catch blocks: a failure to
     * write the queue row must never replace the operation's own error
     * handling or fail the request that already completed its work.
     */
    public function report(ExceptionReport $report): ?object
    {
        try {
            return $this->raise($report);
        } catch (\Throwable $e) {
            Log::error('Platform exception queue write failed', [
                'exception_type' => $report->type->value,
                'document_type' => $report->documentType,
                'document_id' => $report->documentId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function assign($exceptionId, int $userId): object
    {
        $record = ExceptionQueue::where('tenant_id', tenant()->id)
            ->findOrFail((string) $exceptionId);

        $record->update([
            'assigned_to' => $userId,
            'status' => 'in_progress',
        ]);

        return (object) $record->fresh()->toArray();
    }

    public function resolve($exceptionId, int $userId, ?string $resolution = null): object
    {
        $record = ExceptionQueue::where('tenant_id', tenant()->id)
            ->findOrFail((string) $exceptionId);

        $record->update([
            'status' => 'resolved',
            'resolved_by' => $userId,
            'resolved_at' => now(),
            'resolution' => $resolution,
        ]);

        return (object) $record->fresh()->toArray();
    }

    /**
     * Retry a queued exception. A record without a retry handler keeps the
     * historical behaviour (count the attempt, schedule the backoff). A
     * record with a registered handler actually re-runs the failed operation:
     * success resolves the exception, failure records the error and reopens it.
     */
    public function retry($exceptionId): object
    {
        $record = ExceptionQueue::where('tenant_id', tenant()->id)
            ->findOrFail((string) $exceptionId);

        $retryCount = $record->retry_count + 1;
        $record->update([
            'retry_count' => $retryCount,
            'status' => 'retrying',
            'next_retry_at' => now()->addMinutes(pow(2, $retryCount)),
        ]);

        $registry = $this->retryRegistry ?? app(ExceptionRetryRegistry::class);

        if (! $record->retry_handler || ! $registry->has((string) $record->retry_handler)) {
            return (object) $record->fresh()->toArray();
        }

        try {
            $registry->run((string) $record->retry_handler, $record->fresh());

            $record->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolution' => 'Retried successfully via '.$record->retry_handler,
            ]);
        } catch (\Throwable $e) {
            $context = $record->context ?? [];
            $context['last_retry_error'] = $e->getMessage();

            $record->update([
                'status' => 'open',
                'context' => $context,
            ]);
        }

        return (object) $record->fresh()->toArray();
    }

    public function getStats(): array
    {
        $records = ExceptionQueue::where('tenant_id', tenant()->id)->get();

        return [
            'total' => $records->count(),
            'by_module' => $records->groupBy('module')->map(fn ($items) => $items->count())->toArray(),
            'by_severity' => $records->groupBy('severity')->map(fn ($items) => $items->count())->toArray(),
            'by_status' => $records->groupBy('status')->map(fn ($items) => $items->count())->toArray(),
        ];
    }
}

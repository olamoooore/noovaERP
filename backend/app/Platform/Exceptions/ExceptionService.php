<?php

namespace App\Platform\Exceptions;

use App\Platform\Exceptions\Contracts\ExceptionServiceContract;
use Illuminate\Support\Str;

class ExceptionService implements ExceptionServiceContract
{
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

<?php

namespace App\Features\AI\Services;

use App\Http\Middleware\CorrelationIdMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AI Reasoning Record Service
 *
 * Records and tracks AI reasoning for audit trail:
 * - What the AI was asked
 * - What data it accessed
 * - What reasoning it applied
 * - What output it produced
 * - What actions it took
 */
class AIReasoningRecordService
{
    /**
     * Record an AI reasoning trace
     *
     * @param array $data The reasoning record data
     * @return int The record ID
     */
    public function recordReasoning(array $data): int
    {
        $encode = static function ($value): ?string {
            if ($value === null) {
                return null;
            }
            return is_array($value) ? json_encode($value) : (string) $value;
        };

        $recordId = DB::table('ai_reasoning_records')->insertGetId([
            'tenant_id' => $data['tenant_id'] ?? tenant('id'),
            'user_id' => $data['user_id'] ?? auth()->id(),
            'session_id' => $data['session_id'] ?? null,
            'correlation_id' => $data['correlation_id']
                ?? (request()?->header(CorrelationIdMiddleware::HEADER))
                ?? null,
            'module' => $data['module'] ?? null,
            'action' => $data['action'] ?? null,
            'input_prompt' => $data['input_prompt'] ?? null,
            'data_accessed' => $encode($data['data_accessed'] ?? null),
            'reasoning_steps' => $encode($data['reasoning_steps'] ?? null),
            'output' => $data['output'] ?? null,
            'confidence_score' => $data['confidence_score'] ?? null,
            'actions_taken' => $encode($data['actions_taken'] ?? null),
            'duration_ms' => $data['duration_ms'] ?? null,
            'model_used' => $data['model_used'] ?? null,
            'tokens_used' => $data['tokens_used'] ?? null,
            'cost' => $data['cost'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Log::info('AI reasoning recorded', [
            'record_id' => $recordId,
            'module' => $data['module'] ?? null,
            'action' => $data['action'] ?? null,
        ]);

        return $recordId;
    }

    /**
     * Update a reasoning record with output
     *
     * @param int $recordId
     * @param array $data
     * @return bool
     */
    public function updateRecord(int $recordId, array $data): bool
    {
        $updateData = array_filter([
            'output' => $data['output'] ?? null,
            'confidence_score' => $data['confidence_score'] ?? null,
            'actions_taken' => $data['actions_taken'] ?? null,
            'duration_ms' => $data['duration_ms'] ?? null,
            'tokens_used' => $data['tokens_used'] ?? null,
            'cost' => $data['cost'] ?? null,
            'error' => $data['error'] ?? null,
            'updated_at' => now(),
        ], fn($v) => $v !== null);

        return DB::table('ai_reasoning_records')
            ->where('id', $recordId)
            ->update($updateData) > 0;
    }

    /**
     * Get reasoning records for a user
     *
     * @param int $userId
     * @param int $limit
     * @return array
     */
    public function getUserRecords(int $userId, int $limit = 50): array
    {
        return DB::table('ai_reasoning_records')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get reasoning records for a tenant
     *
     * @param string $tenantId
     * @param int $limit
     * @return array
     */
    public function getTenantRecords(string $tenantId, int $limit = 100): array
    {
        return DB::table('ai_reasoning_records')
            ->where('tenant_id', $tenantId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }

    /**
     * Get reasoning statistics for a tenant
     *
     * @param string $tenantId
     * @param string $period 'day', 'week', 'month'
     * @return array
     */
    public function getStats(string $tenantId, string $period = 'day'): array
    {
        $startDate = match ($period) {
            'day' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            default => now()->startOfDay(),
        };

        $records = DB::table('ai_reasoning_records')
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $startDate)
            ->get();

        return [
            'total_requests' => $records->count(),
            'total_tokens' => $records->sum('tokens_used'),
            'total_cost' => $records->sum('cost'),
            'avg_confidence' => $records->avg('confidence_score'),
            'avg_duration_ms' => $records->avg('duration_ms'),
            'success_rate' => $records->whereNull('error')->count() / max($records->count(), 1) * 100,
        ];
    }

    /**
     * Check if an action was previously taken for an entity
     *
     * @param string $entityType
     * @param int|string $entityId
     * @param string $action
     * @return bool
     */
    public function actionPreviouslyTaken(string $entityType, $entityId, string $action): bool
    {
        return DB::table('ai_reasoning_records')
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('action', $action)
            ->where('status', 'completed')
            ->exists();
    }
}

<?php

namespace Modules\OCRBookkeeping\Services;

use App\Services\TenantCache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Batch Notification Service
 * 
 * Tracks batch operations and sends single notification when complete
 */
class BatchNotificationService
{
    /**
     * Register a batch operation
     * 
     * @param string $batchId Unique batch identifier
     * @param int $totalItems Total number of items in batch
     * @param int $userId User to notify
     * @return void
     */
    public function registerBatch(string $batchId, int $totalItems, int $userId): void
    {
        $cacheKey = "batch:{$batchId}";
        
        TenantCache::put($cacheKey, [
            'total' => $totalItems,
            'completed' => 0,
            'failed' => 0,
            'user_id' => $userId,
            'started_at' => now()->toIso8601String(),
        ], now()->addHours(24));
        
        Log::info("Batch registered", [
            'batch_id' => $batchId,
            'total_items' => $totalItems,
            'user_id' => $userId,
        ]);
    }

    /**
     * Mark an item as completed
     * 
     * @param string $batchId
     * @param bool $success Whether the item succeeded
     * @return void
     */
    public function markItemComplete(string $batchId, bool $success = true): void
    {
        $cacheKey = "batch:{$batchId}";
        $batch = TenantCache::get($cacheKey);
        
        if (!$batch) {
            Log::warning("Batch not found", ['batch_id' => $batchId]);
            return;
        }

        if ($success) {
            $batch['completed']++;
        } else {
            $batch['failed']++;
        }

        $totalProcessed = $batch['completed'] + $batch['failed'];
        
        // Update cache
        TenantCache::put($cacheKey, $batch, now()->addHours(24));
        
        // Check if batch is complete
        if ($totalProcessed >= $batch['total']) {
            $this->sendBatchCompleteNotification($batchId, $batch);
            TenantCache::forget($cacheKey);
        }
    }

    /**
     * Send batch complete notification
     * 
     * @param string $batchId
     * @param array $batch
     * @return void
     */
    private function sendBatchCompleteNotification(string $batchId, array $batch): void
    {
        $user = \App\Models\User::find($batch['user_id']);
        
        if (!$user) {
            Log::warning("User not found for batch notification", [
                'batch_id' => $batchId,
                'user_id' => $batch['user_id'],
            ]);
            return;
        }

        $message = sprintf(
            "Batch processing complete: %d succeeded, %d failed out of %d total",
            $batch['completed'],
            $batch['failed'],
            $batch['total']
        );

        // TODO: Send actual notification (email, SMS, in-app, etc.)
        // For now, just log it
        Log::info("Batch complete notification", [
            'batch_id' => $batchId,
            'user_id' => $user->id,
            'message' => $message,
            'completed' => $batch['completed'],
            'failed' => $batch['failed'],
            'total' => $batch['total'],
        ]);

        // Example: Send in-app notification
        // $user->notify(new BatchCompleteNotification($batchId, $batch));
    }

    /**
     * Get batch status
     * 
     * @param string $batchId
     * @return array|null
     */
    public function getBatchStatus(string $batchId): ?array
    {
        $cacheKey = "batch:{$batchId}";
        $batch = TenantCache::get($cacheKey);
        
        if (!$batch) {
            return null;
        }

        $totalProcessed = $batch['completed'] + $batch['failed'];
        $progress = $batch['total'] > 0 ? ($totalProcessed / $batch['total']) * 100 : 0;

        return [
            'batch_id' => $batchId,
            'total' => $batch['total'],
            'completed' => $batch['completed'],
            'failed' => $batch['failed'],
            'progress' => round($progress, 2),
            'is_complete' => $totalProcessed >= $batch['total'],
            'started_at' => $batch['started_at'],
        ];
    }
}

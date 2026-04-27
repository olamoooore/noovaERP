<?php

namespace Modules\POS\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\POS\Models\PosOfflineQueue;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class PosSyncService
{
    /**
     * Queue an offline operation
     */
    public function queueOperation(
        string $operationType,
        array $payload,
        string $idempotencyKey,
        ?int $terminalId = null,
        ?int $sessionId = null,
        ?array $metadata = null
    ): PosOfflineQueue {
        return DB::transaction(function () use (
            $operationType,
            $payload,
            $idempotencyKey,
            $terminalId,
            $sessionId,
            $metadata
        ) {
            // Check for existing queue item with same idempotency key
            $existing = PosOfflineQueue::where('idempotency_key', $idempotencyKey)->first();
            
            if ($existing) {
                Log::info('Offline operation already queued', [
                    'queue_id' => $existing->id,
                    'queue_number' => $existing->queue_number,
                    'idempotency_key' => $idempotencyKey,
                ]);
                return $existing;
            }

            $queueItem = PosOfflineQueue::create([
                'operation_type' => $operationType,
                'status' => 'pending',
                'terminal_id' => $terminalId,
                'session_id' => $sessionId,
                'payload' => $payload,
                'metadata' => $metadata,
                'idempotency_key' => $idempotencyKey,
                'created_by' => TenantUserHelper::resolveTenantUserId(),
                'queued_at' => now(),
            ]);

            Log::info('Offline operation queued', [
                'queue_id' => $queueItem->id,
                'queue_number' => $queueItem->queue_number,
                'operation_type' => $operationType,
            ]);

            return $queueItem;
        });
    }

    /**
     * Sync a queued operation
     */
    public function syncOperation(int $queueId): array
    {
        return DB::transaction(function () use ($queueId) {
            $queueItem = PosOfflineQueue::lockForUpdate()->findOrFail($queueId);

            if ($queueItem->isSynced()) {
                return [
                    'success' => true,
                    'message' => 'Already synced',
                    'entity_type' => $queueItem->synced_entity_type,
                    'entity_id' => $queueItem->synced_entity_id,
                ];
            }

            // Update status to syncing
            $queueItem->update([
                'status' => 'syncing',
                'last_sync_attempt' => now(),
                'sync_attempts' => $queueItem->sync_attempts + 1,
            ]);

            try {
                // Process based on operation type
                $result = match ($queueItem->operation_type) {
                    'create_order' => $this->syncCreateOrder($queueItem),
                    'payment' => $this->syncPayment($queueItem),
                    'void' => $this->syncVoid($queueItem),
                    'refund' => $this->syncRefund($queueItem),
                    default => throw new \Exception("Unknown operation type: {$queueItem->operation_type}"),
                };

                // Mark as synced
                $queueItem->update([
                    'status' => 'synced',
                    'synced_at' => now(),
                    'synced_entity_type' => $result['entity_type'],
                    'synced_entity_id' => $result['entity_id'],
                    'sync_error' => null,
                ]);

                Log::info('Offline operation synced successfully', [
                    'queue_id' => $queueItem->id,
                    'queue_number' => $queueItem->queue_number,
                    'entity_type' => $result['entity_type'],
                    'entity_id' => $result['entity_id'],
                ]);

                return [
                    'success' => true,
                    'message' => 'Synced successfully',
                    'entity_type' => $result['entity_type'],
                    'entity_id' => $result['entity_id'],
                ];
            } catch (\Exception $e) {
                // Check if it's a conflict
                $isConflict = str_contains($e->getMessage(), 'already exists') ||
                              str_contains($e->getMessage(), 'duplicate');

                $queueItem->update([
                    'status' => $isConflict ? 'conflict' : 'failed',
                    'sync_error' => $e->getMessage(),
                    'conflict_data' => $isConflict ? ['error' => $e->getMessage()] : null,
                ]);

                Log::error('Offline operation sync failed', [
                    'queue_id' => $queueItem->id,
                    'queue_number' => $queueItem->queue_number,
                    'error' => $e->getMessage(),
                    'is_conflict' => $isConflict,
                ]);

                return [
                    'success' => false,
                    'message' => $e->getMessage(),
                    'is_conflict' => $isConflict,
                ];
            }
        });
    }

    /**
     * Sync create order operation
     */
    private function syncCreateOrder(PosOfflineQueue $queueItem): array
    {
        $payload = $queueItem->payload;

        // Check for existing order with same external_id (idempotency)
        if (isset($payload['external_id'])) {
            $existing = PosOrder::where('external_id', $payload['external_id'])->first();
            if ($existing) {
                return [
                    'entity_type' => 'PosOrder',
                    'entity_id' => $existing->id,
                ];
            }
        }

        // Create order using standard controller logic
        // This would call the same logic as PosOrderController::store()
        $order = PosOrder::create([
            'external_id' => $payload['external_id'] ?? null,
            'order_number' => $payload['order_number'] ?? PosOrder::generateOrderNumber(),
            'status' => $payload['status'] ?? 'draft',
            'session_id' => $queueItem->session_id,
            'terminal_id' => $queueItem->terminal_id,
            'customer_id' => $payload['customer_id'] ?? null,
            'subtotal' => $payload['subtotal'] ?? 0,
            'tax_amount' => $payload['tax_amount'] ?? 0,
            'total' => $payload['total'] ?? 0,
            'discount_amount' => $payload['discount_amount'] ?? 0,
            'created_by' => $queueItem->created_by,
        ]);

        // Create order items
        if (isset($payload['items'])) {
            foreach ($payload['items'] as $item) {
                $order->items()->create($item);
            }
        }

        return [
            'entity_type' => 'PosOrder',
            'entity_id' => $order->id,
        ];
    }

    /**
     * Sync payment operation
     */
    private function syncPayment(PosOfflineQueue $queueItem): array
    {
        $payload = $queueItem->payload;

        $order = PosOrder::findOrFail($payload['pos_order_id']);

        // Check if payment already exists (idempotency)
        $existing = PosPayment::where('pos_order_id', $order->id)
            ->where('method', $payload['method'])
            ->where('amount', $payload['amount'])
            ->first();

        if ($existing) {
            return [
                'entity_type' => 'PosPayment',
                'entity_id' => $existing->id,
            ];
        }

        // Process payment using standard service
        $paymentService = app(PosPaymentCompletionService::class);
        $order = $paymentService->complete(
            $order,
            $payload['amount'],
            $payload['method'],
            $payload['reference'] ?? null,
            $queueItem->created_by
        );

        $payment = $order->payments()->latest()->first();

        return [
            'entity_type' => 'PosPayment',
            'entity_id' => $payment->id,
        ];
    }

    /**
     * Sync void operation
     * NOTE: Offline voids MUST still go through approval workflow for audit trail
     */
    private function syncVoid(PosOfflineQueue $queueItem): array
    {
        $payload = $queueItem->payload;
        $order = PosOrder::findOrFail($payload['pos_order_id']);

        if ($order->status === 'void') {
            // Already voided
            return [
                'entity_type' => 'PosOrder',
                'entity_id' => $order->id,
            ];
        }

        // CRITICAL: Offline voids must still require approval
        // Check if approval was granted offline
        if (empty($payload['approval_request_id'])) {
            throw new \Exception('Void operation requires approval. Cannot sync without approval_request_id.');
        }

        $approvalRequest = \App\Models\ApprovalRequest::findOrFail($payload['approval_request_id']);
        
        if ($approvalRequest->status !== 'approved') {
            throw new \Exception('Void operation not approved. Current status: ' . $approvalRequest->status);
        }

        // Process void with approval tracking
        $order->update([
            'status' => 'void',
            'voided_by' => $queueItem->created_by,
            'voided_at' => now(),
            'void_approved_by' => $approvalRequest->reviewed_by,
            'void_reason' => $payload['reason'] ?? 'Offline void',
        ]);

        // Create refund transaction if not exists
        $refundService = app(\App\Services\RefundService::class);
        $refundService->createRefundRequest(
            'pos_order',
            $order->id,
            'full',
            $order->total,
            $order->total,
            [],
            $payload['reason'] ?? 'Offline void',
            null,
            $queueItem->created_by,
            "offline_void_{$order->id}"
        );

        return [
            'entity_type' => 'PosOrder',
            'entity_id' => $order->id,
        ];
    }

    /**
     * Sync refund operation
     * NOTE: Offline refunds MUST still go through approval workflow for audit trail
     */
    private function syncRefund(PosOfflineQueue $queueItem): array
    {
        $payload = $queueItem->payload;
        $order = PosOrder::findOrFail($payload['pos_order_id']);

        // Check if refund already processed
        $existingRefund = $order->returns()->first();
        if ($existingRefund) {
            return [
                'entity_type' => 'PosOrder',
                'entity_id' => $existingRefund->id,
            ];
        }

        // CRITICAL: Offline refunds must still require approval
        if (empty($payload['approval_request_id'])) {
            throw new \Exception('Refund operation requires approval. Cannot sync without approval_request_id.');
        }

        $approvalRequest = \App\Models\ApprovalRequest::findOrFail($payload['approval_request_id']);
        
        if ($approvalRequest->status !== 'approved') {
            throw new \Exception('Refund operation not approved. Current status: ' . $approvalRequest->status);
        }

        // Process refund through controller logic (not simplified)
        // This ensures proper GL reversal, stock restoration, and loyalty reversal
        $controller = app(\Modules\POS\Http\Controllers\PosOrderController::class);
        
        // Note: This is a simplified sync. In production, you would need to:
        // 1. Create RefundTransaction
        // 2. Reverse GL entries
        // 3. Restore stock
        // 4. Reverse loyalty points
        // 5. Create refund payment
        
        throw new \Exception('Offline refund sync not fully implemented. Use online refund workflow.');
    }

    /**
     * Get pending queue items
     */
    public function getPendingItems(?int $terminalId = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = PosOfflineQueue::where('status', 'pending')
            ->with(['terminal', 'session', 'createdBy'])
            ->orderBy('queued_at', 'asc');

        if ($terminalId) {
            $query->where('terminal_id', $terminalId);
        }

        return $query->get();
    }

    /**
     * Retry failed sync
     */
    public function retrySync(int $queueId): array
    {
        $queueItem = PosOfflineQueue::findOrFail($queueId);

        if (!$queueItem->hasFailed() && !$queueItem->hasConflict()) {
            throw new \Exception("Queue item is {$queueItem->status}, cannot retry");
        }

        // Reset status to pending
        $queueItem->update([
            'status' => 'pending',
            'sync_error' => null,
            'conflict_data' => null,
        ]);

        // Attempt sync
        return $this->syncOperation($queueId);
    }

    /**
     * Resolve conflict manually
     */
    public function resolveConflict(int $queueId, string $resolution, ?array $resolutionData = null): PosOfflineQueue
    {
        return DB::transaction(function () use ($queueId, $resolution, $resolutionData) {
            $queueItem = PosOfflineQueue::lockForUpdate()->findOrFail($queueId);

            if (!$queueItem->hasConflict()) {
                throw new \Exception("Queue item is not in conflict state");
            }

            $queueItem->update([
                'status' => $resolution === 'skip' ? 'synced' : 'failed',
                'metadata' => array_merge($queueItem->metadata ?? [], [
                    'conflict_resolution' => $resolution,
                    'resolution_data' => $resolutionData,
                    'resolved_at' => now()->toIso8601String(),
                ]),
            ]);

            Log::info('Conflict resolved', [
                'queue_id' => $queueItem->id,
                'resolution' => $resolution,
            ]);

            return $queueItem;
        });
    }
}

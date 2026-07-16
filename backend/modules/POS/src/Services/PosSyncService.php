<?php

namespace Modules\POS\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;
use Modules\POS\Models\PosOfflineQueue;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosPayment;
use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosSetting;
use Modules\POS\Models\PosTerminal;

class PosSyncService
{
    private const REQUIRED_ENVELOPE_KEYS = [
        'tenant_id',
        'offline_device_id',
        'offline_session_id',
        'offline_event_id',
        'idempotency_key',
        'source_module',
        'source_document_type',
        'source_document_id',
        'source_line_id',
        'user_id_at_capture',
        'warehouse_id_at_capture',
        'captured_at_device_time',
        'captured_at_server_last_seen_time',
        'client_sequence_number',
        'app_version',
        'capability_snapshot_version',
        'permission_snapshot_version',
        'offline_policy_tier',
        'payload_hash',
        'signature_or_auth_proof',
        'local_cache_encrypted',
        'local_cache_visibility_scope',
    ];

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
            $metadata = $this->buildSyncEnvelope(
                payload: $payload,
                idempotencyKey: $idempotencyKey,
                terminalId: $terminalId,
                sessionId: $sessionId,
                metadata: $metadata,
                operationType: $operationType,
            );

            $this->logOfflineEvent('queue.accepted', [
                'operation_type' => $operationType,
                'idempotency_key' => $idempotencyKey,
                'terminal_id' => $terminalId,
                'session_id' => $sessionId,
            ]);

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

            $this->logOfflineEvent('queue.persisted', [
                'queue_id' => $queueItem->id,
                'queue_number' => $queueItem->queue_number,
                'operation_type' => $operationType,
            ]);

            return $queueItem;
        });
    }

    private function buildSyncEnvelope(
        array $payload,
        string $idempotencyKey,
        ?int $terminalId,
        ?int $sessionId,
        ?array $metadata,
        string $operationType,
    ): array {
        $metadata = $metadata ?? [];
        $tenantId = (string) (function_exists('tenant') && tenant() ? tenant('id') : 'unknown');
        $deviceId = $metadata['offline_device_id'] ?? ($terminalId ? (string) $terminalId : null);
        $offlineEventId = $metadata['offline_event_id'] ?? null;
        $capturedAtDevice = $metadata['captured_at_device_time'] ?? null;
        $capturedAtServer = $metadata['captured_at_server_last_seen_time'] ?? now()->toIso8601String();

        $envelope = [
            'tenant_id' => $tenantId,
            'offline_device_id' => $deviceId,
            'offline_session_id' => $metadata['offline_session_id'] ?? ($sessionId ? (string) $sessionId : null),
            'offline_event_id' => $offlineEventId,
            'idempotency_key' => $idempotencyKey,
            'source_module' => $metadata['source_module'] ?? 'pos',
            'source_document_type' => $metadata['source_document_type'] ?? $operationType,
            'source_document_id' => $metadata['source_document_id'] ?? ($payload['pos_order_id'] ?? $payload['external_id'] ?? null),
            'source_line_id' => $metadata['source_line_id'] ?? null,
            'user_id_at_capture' => $metadata['user_id_at_capture'] ?? TenantUserHelper::resolveTenantUserId(),
            'warehouse_id_at_capture' => $metadata['warehouse_id_at_capture'] ?? null,
            'captured_at_device_time' => $capturedAtDevice,
            'captured_at_server_last_seen_time' => $capturedAtServer,
            'client_sequence_number' => $metadata['client_sequence_number'] ?? null,
            'app_version' => $metadata['app_version'] ?? null,
            'capability_snapshot_version' => $metadata['capability_snapshot_version'] ?? null,
            'permission_snapshot_version' => $metadata['permission_snapshot_version'] ?? null,
            'offline_policy_tier' => $metadata['offline_policy_tier'] ?? null,
            'payload_hash' => $metadata['payload_hash'] ?? hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'signature_or_auth_proof' => $metadata['signature_or_auth_proof'] ?? null,
            'local_cache_encrypted' => $metadata['local_cache_encrypted'] ?? null,
            'local_cache_visibility_scope' => $metadata['local_cache_visibility_scope'] ?? null,
        ];

        // Fail fast when required envelope fields are missing to prevent ambiguous offline replay.
        $missing = collect(self::REQUIRED_ENVELOPE_KEYS)
            ->filter(fn (string $key) => blank($envelope[$key] ?? null))
            ->values()
            ->all();

        if (! empty($missing)) {
            throw new \InvalidArgumentException('Offline sync envelope incomplete: '.implode(', ', $missing));
        }

        $this->assertOfflineGuardrails(
            envelope: $envelope,
            terminalId: $terminalId,
            sessionId: $sessionId,
            operationType: $operationType,
            payload: $payload,
        );

        return array_merge($metadata, $envelope);
    }

    private function assertOfflineGuardrails(
        array $envelope,
        ?int $terminalId,
        ?int $sessionId,
        string $operationType,
        array $payload,
    ): void {
        $this->assertTenantBoundary($envelope);
        $terminal = $this->assertDeviceRegistrationState($envelope, $terminalId, $sessionId);
        $this->assertWarehouseScopeState($envelope, $terminal);
        $this->assertCapabilitySnapshotState($envelope);
        $this->assertOfflinePolicyTierState($envelope, $operationType, $payload);
        $this->assertLocalCacheSecurityState($envelope);
    }

    private function assertTenantBoundary(array $envelope): void
    {
        $runtimeTenantId = (string) (function_exists('tenant') && tenant() ? tenant('id') : 'unknown');
        if ((string) ($envelope['tenant_id'] ?? '') !== $runtimeTenantId) {
            throw new \InvalidArgumentException('Offline tenant boundary mismatch.');
        }
    }

    private function assertDeviceRegistrationState(array $envelope, ?int $terminalId, ?int $sessionId): PosTerminal
    {
        $resolvedTerminalId = $terminalId;
        if (! $resolvedTerminalId && is_numeric($envelope['offline_device_id'] ?? null)) {
            $resolvedTerminalId = (int) $envelope['offline_device_id'];
        }

        $terminal = $resolvedTerminalId
            ? PosTerminal::where('id', $resolvedTerminalId)->where('is_active', true)->first()
            : null;

        if (! $terminal) {
            throw new \InvalidArgumentException('Offline device is not registered or active.');
        }

        if (! empty($envelope['device_revoked_at'])) {
            throw new \InvalidArgumentException('Offline device has been revoked.');
        }

        if ($sessionId) {
            $openSessionExists = PosSession::query()
                ->where('id', $sessionId)
                ->where('terminal_id', $terminal->id)
                ->where('status', 'open')
                ->exists();

            if (! $openSessionExists) {
                throw new \InvalidArgumentException('Offline session is not active for the registered device.');
            }
        }

        return $terminal;
    }

    private function assertWarehouseScopeState(array $envelope, PosTerminal $terminal): void
    {
        $capturedWarehouseId = (string) ($envelope['warehouse_id_at_capture'] ?? '');
        $terminalWarehouseId = (string) ($terminal->warehouse_id ?? '');

        if ($terminalWarehouseId === '' || $capturedWarehouseId !== $terminalWarehouseId) {
            throw new \InvalidArgumentException('Offline warehouse scope mismatch for captured event.');
        }
    }

    private function assertCapabilitySnapshotState(array $envelope): void
    {
        $expectedCapabilityVersion = PosSetting::getValue('offline_capability_snapshot_version');
        if ($expectedCapabilityVersion !== null && (string) $expectedCapabilityVersion !== (string) $envelope['capability_snapshot_version']) {
            throw new \InvalidArgumentException('Offline capability snapshot is stale.');
        }

        $expectedPermissionVersion = PosSetting::getValue('offline_permission_snapshot_version');
        if ($expectedPermissionVersion !== null && (string) $expectedPermissionVersion !== (string) $envelope['permission_snapshot_version']) {
            throw new \InvalidArgumentException('Offline permission snapshot is stale.');
        }
    }

    private function assertOfflinePolicyTierState(array $envelope, string $operationType, array $payload): void
    {
        $requestedTier = (string) ($envelope['offline_policy_tier'] ?? '');
        $configuredTier = (string) (PosSetting::getValue('offline_policy_tier', $requestedTier) ?? '');

        if ($configuredTier !== '' && $requestedTier !== $configuredTier) {
            throw new \InvalidArgumentException('Offline policy tier mismatch.');
        }

        $allowedOperationsByTier = [
            'strict' => ['create_order'],
            'standard' => ['create_order', 'payment'],
            'enterprise' => ['create_order', 'payment', 'void', 'refund'],
        ];

        $allowedOperations = $allowedOperationsByTier[$requestedTier] ?? [];
        if (! in_array($operationType, $allowedOperations, true)) {
            throw new \InvalidArgumentException('Offline policy tier does not allow operation: '.$operationType);
        }

        $allocationEnabled = (bool) PosSetting::getValue('offline_enable_stock_allocation', false);
        if ($operationType === 'create_order' && $allocationEnabled && empty($payload['allocation_plan'])) {
            throw new \InvalidArgumentException('Offline stock allocation plan is required when allocation is enabled.');
        }

        if (($payload['workflow'] ?? null) === 'stock_count' && empty($payload['draft_reconciliation_reference'])) {
            throw new \InvalidArgumentException('Offline stock count requires draft reconciliation reference.');
        }
    }

    private function assertLocalCacheSecurityState(array $envelope): void
    {
        if (($envelope['local_cache_encrypted'] ?? false) !== true) {
            throw new \InvalidArgumentException('Offline local cache must be encrypted.');
        }

        $scope = (string) ($envelope['local_cache_visibility_scope'] ?? '');
        if (! in_array($scope, ['user', 'session', 'device'], true)) {
            throw new \InvalidArgumentException('Offline local cache visibility scope is invalid.');
        }
    }

    private function logOfflineEvent(string $event, array $context = []): void
    {
        Log::info('offline_sync_event', array_merge(['event' => $event], $context));
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

                $this->logOfflineEvent('sync.succeeded', [
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

                $this->logOfflineEvent($isConflict ? 'sync.conflict' : 'sync.failed', [
                    'queue_id' => $queueItem->id,
                    'queue_number' => $queueItem->queue_number,
                    'error' => $e->getMessage(),
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
            throw new \Exception('Void operation not approved. Current status: '.$approvalRequest->status);
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

        if ($order->status === 'void' || $order->status === 'refunded') {
            return [
                'entity_type' => 'PosOrder',
                'entity_id' => $order->id,
            ];
        }

        $existingRefund = $order->returns()->first();
        if ($existingRefund) {
            return [
                'entity_type' => 'PosOrder',
                'entity_id' => $existingRefund->id,
            ];
        }

        if (empty($payload['approval_request_id'])) {
            throw new \Exception('Refund operation requires approval. Cannot sync without approval_request_id.');
        }

        $approvalRequest = \App\Models\ApprovalRequest::findOrFail($payload['approval_request_id']);

        if ($approvalRequest->status !== 'approved') {
            throw new \Exception('Refund operation not approved. Current status: '.$approvalRequest->status);
        }

        if ($approvalRequest->requested_by === $approvalRequest->reviewed_by) {
            throw new \Exception('Self-approval of refunds is not permitted. Approver must differ from requester.');
        }

        $refundMethod = $payload['refund_method'] ?? 'cash';
        $reason = $payload['reason'] ?? 'Offline refund';
        $idempotencyKey = "offline_refund_{$order->id}";

        $refundService = app(\App\Services\RefundService::class);
        $refundLines = $order->items->map(fn ($item) => [
            'line_id' => $item->id,
            'product_id' => $item->product_id,
            'variant_id' => $item->product_variant_id,
            'quantity' => $item->quantity,
            'amount' => $item->line_total,
        ])->toArray();

        $refundTransaction = $refundService->createRefundRequest(
            sourceType: 'PosOrder',
            sourceId: $order->id,
            refundAmount: $order->total,
            originalAmount: $order->total,
            refundLines: $refundLines,
            reason: $reason,
            notes: "Offline refund for order #{$order->order_number}",
            idempotencyKey: $idempotencyKey
        );

        $refundTransaction = $refundService->approveRefund($refundTransaction->id, $approvalRequest->reviewed_by);
        $refundTransaction = $refundService->completeRefund($refundTransaction->id);

        $returnOrder = $this->createAndRestockReturnForSync($order, $queueItem, $reason);

        $this->createRefundPaymentForSync($order, $returnOrder, $refundMethod, $reason, $approvalRequest->reviewed_by);

        $postingService = app(\Modules\POS\Services\PosPostingService::class);

        try {
            $postingService->reverseOrder($order, $returnOrder, 'POS offline refund reversal');
        } catch (\Exception $e) {
            Log::warning('GL reversal failed for offline refund', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $refundJournalEntry = $postingService->postRefundPayment(
                $order,
                $refundMethod,
                $order->total,
                "Offline refund payment for order #{$order->order_number}"
            );

            if ($refundTransaction && $refundJournalEntry) {
                $refundTransaction->update(['journal_entry_id' => $refundJournalEntry->id]);
            }
        } catch (\Exception $e) {
            Log::warning('Refund payment GL posting failed for offline refund', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($order->customer_id) {
            try {
                $reversalService = app(\Modules\Loyalty\Services\PointsReversalService::class);
                $reversalService->reverseOrder(
                    $order->customer_id,
                    'pos_order',
                    $order->id,
                    "Loyalty reversal for offline refunded order #{$order->order_number}: {$reason}"
                );
            } catch (\Exception $e) {
                Log::warning('Loyalty reversal failed for offline refund', [
                    'order_id' => $order->id,
                    'customer_id' => $order->customer_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $order->update([
            'status' => 'refunded',
            'refunded_at' => now(),
        ]);

        Log::info('Offline refund synced successfully', [
            'queue_id' => $queueItem->id,
            'order_id' => $order->id,
            'return_order_id' => $returnOrder->id,
            'refund_transaction_id' => $refundTransaction->id,
        ]);

        return [
            'entity_type' => 'PosOrder',
            'entity_id' => $order->id,
        ];
    }

    private function createAndRestockReturnForSync(PosOrder $order, PosOfflineQueue $queueItem, ?string $notes): PosOrder
    {
        $items = $order->items()
            ->where('quantity', '>', 0)
            ->get()
            ->map(fn ($item) => [
                'item_id' => $item->id,
                'quantity' => (int) $item->quantity,
            ])
            ->values()
            ->all();

        $returnOrder = $order->createReturn($items, $notes ?? 'Offline refund');

        $terminal = $order->terminal()->with('warehouse')->first();
        $warehouseId = $terminal?->warehouse_id;

        if (! $warehouseId) {
            Log::warning('POS return has no terminal warehouse — stock restock skipped', [
                'pos_order_id' => $order->id,
            ]);
        }

        if ($warehouseId) {
            $inventoryService = app(\Modules\Inventory\Services\InventoryService::class);
            foreach ($returnOrder->items()->with(['product', 'variant'])->get() as $item) {
                $qty = abs($item->quantity);
                $productId = $item->variant ? $item->variant->product_id : $item->product_id;
                $trackInventory = $item->variant
                    ? (bool) $item->variant->track_inventory
                    : (bool) ($item->product?->track_inventory ?? false);

                if ($productId && $trackInventory && $qty > 0) {
                    try {
                        $inventoryService->adjustStock(
                            productId: $productId,
                            warehouseId: $warehouseId,
                            quantity: $qty,
                            type: 'return',
                            reference: 'Return: '.$returnOrder->order_number,
                            referenceType: 'PosOrder',
                            referenceId: $returnOrder->id,
                        );
                    } catch (\Exception $e) {
                        Log::error('POS stock return failed', [
                            'return_order' => $returnOrder->order_number,
                            'product_id' => $productId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return $returnOrder;
    }

    private function createRefundPaymentForSync(
        PosOrder $order,
        PosOrder $returnOrder,
        string $refundMethod,
        string $reason,
        int $receivedBy
    ): \Modules\POS\Models\PosPayment {
        $reversalService = app(\Modules\POS\Services\PosReversalService::class);

        return $reversalService->createRefundPayment($order, $refundMethod, $receivedBy, $reason);
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

        if (! $queueItem->hasFailed() && ! $queueItem->hasConflict()) {
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

            if (! $queueItem->hasConflict()) {
                throw new \Exception('Queue item is not in conflict state');
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

            $this->logOfflineEvent('conflict.resolved', [
                'queue_id' => $queueItem->id,
                'resolution' => $resolution,
            ]);

            return $queueItem;
        });
    }
}

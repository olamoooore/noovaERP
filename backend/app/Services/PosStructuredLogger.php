<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * OBS-001: Structured logging with correlation IDs for all POS mutation paths.
 *
 * Replaces ad-hoc Log::info arrays with structured entries that include:
 * correlation_id, tenant_id, terminal_id, session_id, operation,
 * idempotency_key, actor_id, timestamp.
 */
class PosStructuredLogger
{
    public function __construct(private readonly Request $request)
    {
    }

    /**
     * Log a POS payment completion
     */
    public function payment(
        int $orderId,
        int $paymentId,
        string $method,
        float $amount,
        ?int $terminalId = null,
        ?int $sessionId = null,
        ?string $idempotencyKey = null
    ): void {
        Log::info('pos.payment.completed', $this->context([
            'operation' => 'pos_payment',
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'method' => $method,
            'amount' => $amount,
            'terminal_id' => $terminalId,
            'session_id' => $sessionId,
            'idempotency_key' => $idempotencyKey,
        ]));
    }

    /**
     * Log a POS void
     */
    public function void(
        int $orderId,
        string $voidReason,
        ?int $approvedBy = null,
        ?int $terminalId = null,
        ?int $sessionId = null
    ): void {
        Log::info('pos.void.completed', $this->context([
            'operation' => 'pos_void',
            'order_id' => $orderId,
            'void_reason' => $voidReason,
            'approved_by' => $approvedBy,
            'terminal_id' => $terminalId,
            'session_id' => $sessionId,
        ]));
    }

    /**
     * Log a POS refund
     */
    public function refund(
        int $orderId,
        float $refundAmount,
        string $method,
        ?int $approvedBy = null,
        ?int $terminalId = null,
        ?int $sessionId = null
    ): void {
        Log::info('pos.refund.completed', $this->context([
            'operation' => 'pos_refund',
            'order_id' => $orderId,
            'refund_amount' => $refundAmount,
            'method' => $method,
            'approved_by' => $approvedBy,
            'terminal_id' => $terminalId,
            'session_id' => $sessionId,
        ]));
    }

    /**
     * Log a POS return
     */
    public function return(
        int $orderId,
        int $returnOrderId,
        float $returnAmount,
        ?int $approvedBy = null,
        ?int $terminalId = null,
        ?int $sessionId = null
    ): void {
        Log::info('pos.return.completed', $this->context([
            'operation' => 'pos_return',
            'order_id' => $orderId,
            'return_order_id' => $returnOrderId,
            'return_amount' => $returnAmount,
            'approved_by' => $approvedBy,
            'terminal_id' => $terminalId,
            'session_id' => $sessionId,
        ]));
    }

    /**
     * Log a payment failure
     */
    public function paymentFailed(
        int $orderId,
        string $reason,
        ?int $terminalId = null,
        ?int $sessionId = null
    ): void {
        Log::error('pos.payment.failed', $this->context([
            'operation' => 'pos_payment',
            'order_id' => $orderId,
            'failure_reason' => $reason,
            'terminal_id' => $terminalId,
            'session_id' => $sessionId,
        ]));
    }

    /**
     * Log a sync operation
     */
    public function sync(
        string $operationType,
        int $queueItemId,
        string $status,
        ?string $errorMessage = null
    ): void {
        $level = $status === 'failed' ? 'error' : 'info';

        Log::$level("pos.sync.{$status}", $this->context([
            'operation' => 'pos_sync',
            'operation_type' => $operationType,
            'queue_item_id' => $queueItemId,
            'sync_status' => $status,
            'error_message' => $errorMessage,
        ]));
    }

    /**
     * Build a structured log context with correlation ID and actor info.
     */
    protected function context(array $extra = []): array
    {
        return array_merge([
            'correlation_id' => $this->request->header('X-Correlation-ID'),
            'actor_id' => $this->request->user()?->id,
            'tenant_id' => $this->request->user()?->tenant_id,
            'ip_address' => $this->request->ip(),
            'timestamp' => now()->toIso8601String(),
        ], $extra);
    }
}

<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Platform\Audit\AuditHashChain;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    public function __construct(private ?AuditHashChain $hashChain = null) {}

    /**
     * Log a POS operation
     */
    public function log(
        string $operation,
        string $referenceType,
        int $referenceId,
        ?int $actorId = null,
        ?int $terminalId = null,
        ?int $sessionId = null,
        ?array $beforeState = null,
        ?array $afterState = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null
    ): AuditLog {
        // Use authenticated user if actor not specified
        $actorId = $actorId ?? Auth::id();

        // Get request context
        $ipAddress = Request::ip();
        $userAgent = Request::userAgent();
        $sourceRoute = Request::route()?->getName() ?? Request::path();

        // Create audit log (idempotent based on idempotency_key)
        if ($idempotencyKey) {
            $existing = AuditLog::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                return $existing;
            }
        }

        // Created-at is truncated to the database's timestamp precision so the
        // hash computed here matches the value read back during verification.
        $createdAt = now()->startOfSecond();
        $tenantId = tenant()?->id;

        // Issue #912: chain the audit row to the tenant's previous audit entry
        // and advance the chain head in the same transaction as the insert.
        return DB::transaction(function () use (
            $operation,
            $referenceType,
            $referenceId,
            $actorId,
            $terminalId,
            $sessionId,
            $sourceRoute,
            $idempotencyKey,
            $beforeState,
            $afterState,
            $metadata,
            $ipAddress,
            $userAgent,
            $createdAt,
            $tenantId,
        ) {
            $seal = ($this->hashChain ??= app(AuditHashChain::class))->seal([
                'tenant_id' => $tenantId,
                'actor_id' => $actorId,
                'operation' => $operation,
                'subject' => $referenceType.'#'.$referenceId,
                'payload' => [
                    'before_state' => $beforeState,
                    'after_state' => $afterState,
                    'metadata' => $metadata,
                    'source_route' => $sourceRoute,
                    'idempotency_key' => $idempotencyKey,
                    'terminal_id' => $terminalId,
                    'session_id' => $sessionId,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                ],
                'created_at' => $createdAt,
            ]);

            return AuditLog::create([
                'operation' => $operation,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'actor_id' => $actorId,
                'terminal_id' => $terminalId,
                'session_id' => $sessionId,
                'source_route' => $sourceRoute,
                'idempotency_key' => $idempotencyKey,
                'before_state' => $beforeState,
                'after_state' => $afterState,
                'metadata' => $metadata,
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'previous_hash' => $seal['previous_hash'],
                'entry_hash' => $seal['entry_hash'],
                'chain_sequence' => $seal['chain_sequence'],
            ]);
        });
    }

    /**
     * Log POS payment
     */
    public function logPayment(
        int $orderId,
        int $paymentId,
        string $method,
        float $amount,
        ?int $terminalId = null,
        ?int $sessionId = null
    ): AuditLog {
        return $this->log(
            operation: 'pos_payment',
            referenceType: 'pos_order',
            referenceId: $orderId,
            terminalId: $terminalId,
            sessionId: $sessionId,
            afterState: [
                'payment_id' => $paymentId,
                'method' => $method,
                'amount' => $amount,
            ],
            idempotencyKey: "payment_{$orderId}_{$paymentId}"
        );
    }

    /**
     * Log POS void
     */
    public function logVoid(
        int $orderId,
        string $voidReason,
        ?int $approvedBy = null,
        ?int $terminalId = null,
        ?int $sessionId = null
    ): AuditLog {
        return $this->log(
            operation: 'pos_void',
            referenceType: 'pos_order',
            referenceId: $orderId,
            terminalId: $terminalId,
            sessionId: $sessionId,
            afterState: [
                'void_reason' => $voidReason,
                'approved_by' => $approvedBy,
            ],
            idempotencyKey: "void_{$orderId}"
        );
    }

    /**
     * Log POS refund
     */
    public function logRefund(
        int $orderId,
        int $refundPaymentId,
        float $refundAmount,
        string $notes,
        ?int $approvedBy = null,
        ?int $terminalId = null,
        ?int $sessionId = null
    ): AuditLog {
        return $this->log(
            operation: 'pos_refund',
            referenceType: 'pos_order',
            referenceId: $orderId,
            terminalId: $terminalId,
            sessionId: $sessionId,
            afterState: [
                'refund_payment_id' => $refundPaymentId,
                'refund_amount' => $refundAmount,
                'notes' => $notes,
                'approved_by' => $approvedBy,
            ],
            idempotencyKey: "refund_{$orderId}_{$refundPaymentId}"
        );
    }

    /**
     * Log POS return
     */
    public function logReturn(
        int $orderId,
        int $returnOrderId,
        array $items,
        ?int $approvedBy = null,
        ?int $terminalId = null,
        ?int $sessionId = null
    ): AuditLog {
        return $this->log(
            operation: 'pos_return',
            referenceType: 'pos_order',
            referenceId: $orderId,
            terminalId: $terminalId,
            sessionId: $sessionId,
            afterState: [
                'return_order_id' => $returnOrderId,
                'items' => $items,
                'approved_by' => $approvedBy,
            ],
            idempotencyKey: "return_{$orderId}_{$returnOrderId}"
        );
    }

    /**
     * Log approval grant
     */
    public function logApprovalGrant(
        int $approvalRequestId,
        string $operation,
        int $referenceId,
        ?int $terminalId = null,
        ?int $sessionId = null
    ): AuditLog {
        return $this->log(
            operation: 'approval_grant',
            referenceType: 'approval_request',
            referenceId: $approvalRequestId,
            terminalId: $terminalId,
            sessionId: $sessionId,
            metadata: [
                'approved_operation' => $operation,
                'approved_reference_id' => $referenceId,
            ],
            idempotencyKey: "approval_grant_{$approvalRequestId}"
        );
    }

    /**
     * Log approval reject
     */
    public function logApprovalReject(
        int $approvalRequestId,
        string $operation,
        int $referenceId,
        string $reason,
        ?int $terminalId = null,
        ?int $sessionId = null
    ): AuditLog {
        return $this->log(
            operation: 'approval_reject',
            referenceType: 'approval_request',
            referenceId: $approvalRequestId,
            terminalId: $terminalId,
            sessionId: $sessionId,
            metadata: [
                'rejected_operation' => $operation,
                'rejected_reference_id' => $referenceId,
                'reason' => $reason,
            ],
            idempotencyKey: "approval_reject_{$approvalRequestId}"
        );
    }
}

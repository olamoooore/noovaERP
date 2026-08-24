<?php

namespace App\Accounting\DTOs;

/**
 * ReversalRequest
 *
 * Typed DTO for all reversal operations. Replaces the loose primitives that
 * were previously scattered across the call-site (reason string, optional
 * approver) with a governed contract that enforces required fields.
 */
class ReversalRequest
{
    /**
     * @param  int  $journalEntryId  The posted entry to reverse
     * @param  string  $reversalDate  Effective date of the reversal (Y-m-d) — validated against lock + fiscal
     * @param  string  $reason  Human-readable reversal reason (required)
     * @param  string|null  $reasonCode  Short code for categorisation (e.g. 'CORRECTION', 'DUPLICATE')
     * @param  int|null  $actorUserId  User performing the reversal (null = current auth user)
     * @param  int|null  $approvalUserId  Approver (must differ from actorUserId when overriding a lock)
     * @param  string|null  $requestId  External request ID for idempotency/tracing
     * @param  string|null  $correlationId  Correlation ID for logging/audit
     * @param  string|null  $tenantId  Tenant context (required for multi-tenant safety)
     */
    public function __construct(
        public readonly int $journalEntryId,
        public readonly string $reversalDate,
        public readonly string $reason,
        public readonly ?string $reasonCode = null,
        public readonly ?int $actorUserId = null,
        public readonly ?int $approvalUserId = null,
        public readonly ?string $requestId = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $tenantId = null,
    ) {}
}

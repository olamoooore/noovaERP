<?php

namespace App\Services;

use Illuminate\Routing\Redirector;

class CorrectionRequest
{
    public function __construct(
        public readonly ?int $userId,
        public readonly string $reasonCode,
        public readonly ?string $reasonText = null,
        public readonly ?int $versionNumber = 1,
        public readonly ?string $correctionGroupId = null,
    ) {}

    public static function fromRequest(array $data): self
    {
        return new self(
            userId: $data['user_id'] ?? auth()->id(),
            reasonCode: $data['reason_code'] ?? 'general',
            reasonText: $data['reason_text'] ?? null,
            versionNumber: $data['version_number'] ?? null,
            correctionGroupId: $data['correction_group_id'] ?? null,
        );
    }
}

enum CorrectionType: string
{
    case VOID = 'void';
    case REVERSE = 'reverse';
    case CREDIT_NOTE = 'credit_note';
    case REPLACEMENT = 'replacement';
    case REFUND = 'refund';
    case UNAPPLY_PAYMENT = 'unapply_payment';
}

enum LifecycleStatus: string
{
    case DRAFT = 'draft';
    case POSTED = 'posted';
    case VOIDED = 'voided';
    case REVERSED = 'reversed';
    case CREDITED = 'credited';
    case SUPERSEDED = 'superseded';
    case CANCELLED = 'cancelled';
}

enum SettlementStatus: string
{
    case UNPAID = 'unpaid';
    case PARTIALLY_PAID = 'partially_paid';
    case PAID = 'paid';
    case REFUNDED = 'refunded';
}

enum ReconciliationStatus: string
{
    case UNRECONCILED = 'unreconciled';
    case PARTIALLY_RECONCILED = 'partially_reconciled';
    case CLEARED = 'cleared';
}

enum PaymentStatus: string
{
    case RECORDED = 'recorded';
    case APPLIED = 'applied';
    case UNAPPLIED = 'unapplied';
    case REVERSED = 'reversed';
    case REFUNDED = 'refunded';
}
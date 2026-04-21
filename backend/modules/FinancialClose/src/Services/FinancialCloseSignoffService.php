<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\Signoff;
use Modules\FinancialClose\Models\AccountReview;
use Modules\FinancialClose\Models\AuditLog;
use Modules\FinancialClose\Services\FinancialClosePolicyService;

class FinancialCloseSignoffService
{
    protected FinancialClosePolicyService $policy;

    public function __construct(FinancialClosePolicyService $policy)
    {
        $this->policy = $policy;
    }

    /**
     * Get all signoffs for a review
     */
    public function listSignoffs(string $tenantId, string $reviewId): array
    {
        return Signoff::where('tenant_id', $tenantId)
            ->where('review_id', $reviewId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Record a sign-off on a review
     */
    public function recordSignoff(string $tenantId, string $reviewId, string $userId, string $role, ?string $notes = null): ?Signoff
    {
        $review = AccountReview::where('tenant_id', $tenantId)
            ->where('id', $reviewId)
            ->first();

        if (!$review) {
            return null;
        }

        if (!in_array($review->status, ['submitted', 'approved'])) {
            Log::warning('FinancialClose: cannot sign off review in current state', [
                'review_id' => $reviewId,
                'status' => $review->status,
            ]);
            return null;
        }

        // SoD check
        if ($this->policy->requiresIndependentReviewer($tenantId, $review->assigned_to, $userId)) {
            Log::warning('FinancialClose: SoD conflict on sign-off', [
                'review_id' => $reviewId,
                'assigned_to' => $review->assigned_to,
                'signer' => $userId,
            ]);
            return null;
        }

        $signoff = Signoff::create([
            'tenant_id' => $tenantId,
            'review_id' => $reviewId,
            'signed_by' => $userId,
            'role' => $role,
            'status' => 'signed',
            'notes' => $notes,
            'signed_at' => now(),
        ]);

        // Write audit log
        $this->writeAuditLog($tenantId, $userId, 'signoff_recorded', 'review', $reviewId);

        return $signoff;
    }

    /**
     * Revoke a sign-off
     */
    public function revokeSignoff(string $tenantId, string $signoffId, string $userId): bool
    {
        $signoff = Signoff::where('tenant_id', $tenantId)
            ->where('id', $signoffId)
            ->first();

        if (!$signoff) {
            return false;
        }

        $review = AccountReview::where('tenant_id', $tenantId)
            ->where('id', $signoff->review_id)
            ->first();

        if ($review && $review->status === 'locked') {
            Log::warning('FinancialClose: cannot revoke signoff on locked review', [
                'signoff_id' => $signoffId,
            ]);
            return false;
        }

        $signoff->update(['status' => 'revoked']);

        // Write audit log
        $this->writeAuditLog($tenantId, $userId, 'signoff_revoked', 'signoff', $signoffId);

        return true;
    }

    /**
     * Write an audit log entry
     */
    protected function writeAuditLog(string $tenantId, string $actorId, string $action, string $entityType, string $entityId): void
    {
        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
    }
}
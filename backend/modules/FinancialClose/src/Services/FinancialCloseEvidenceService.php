<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\AccountReview;
use Modules\FinancialClose\Models\SupportingEvidence;

class FinancialCloseEvidenceService
{
    /**
     * Get all evidence for a review
     */
    public function listEvidence(string $tenantId, string $reviewId): array
    {
        return SupportingEvidence::where('tenant_id', $tenantId)
            ->where('review_id', $reviewId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->toArray();
    }

    /**
     * Attach evidence to a review
     */
    public function attachEvidence(string $tenantId, string $reviewId, string $userId, array $fileData): ?SupportingEvidence
    {
        $review = AccountReview::where('tenant_id', $tenantId)
            ->where('id', $reviewId)
            ->first();

        if (! $review) {
            return null;
        }

        if ($review->status === 'locked') {
            Log::warning('FinancialClose: cannot attach evidence to locked review', [
                'review_id' => $reviewId,
            ]);

            return null;
        }

        return SupportingEvidence::create([
            'tenant_id' => $tenantId,
            'review_id' => $reviewId,
            'file_name' => $fileData['file_name'],
            'file_path' => $fileData['file_path'],
            'status' => 'attached',
            'uploaded_by' => $userId,
        ]);
    }

    /**
     * Delete evidence from a review
     */
    public function deleteEvidence(string $tenantId, string $evidenceId, string $userId): bool
    {
        $evidence = SupportingEvidence::where('tenant_id', $tenantId)
            ->where('id', $evidenceId)
            ->first();

        if (! $evidence) {
            return false;
        }

        $review = AccountReview::where('tenant_id', $tenantId)
            ->where('id', $evidence->review_id)
            ->first();

        if (! $review) {
            return false;
        }

        // Cannot delete evidence if review is locked or approved
        if (in_array($review->status, ['locked', 'approved'])) {
            Log::warning('FinancialClose: cannot delete evidence from locked or approved review', [
                'evidence_id' => $evidenceId,
                'review_status' => $review->status,
            ]);

            return false;
        }

        $evidence->delete();

        return true;
    }

    /**
     * Mark evidence as stale
     */
    public function markStale(string $tenantId, string $evidenceId): bool
    {
        $evidence = SupportingEvidence::where('tenant_id', $tenantId)
            ->where('id', $evidenceId)
            ->first();

        if (! $evidence) {
            return false;
        }

        $evidence->update(['status' => 'stale']);

        return true;
    }

    /**
     * Mark evidence as sufficient
     */
    public function markSufficient(string $tenantId, string $evidenceId): bool
    {
        $evidence = SupportingEvidence::where('tenant_id', $tenantId)
            ->where('id', $evidenceId)
            ->first();

        if (! $evidence) {
            return false;
        }

        $evidence->update(['status' => 'sufficient']);

        return true;
    }
}

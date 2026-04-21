<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Models\AccountReview;
use Modules\FinancialClose\Services\FinancialClosePolicyService;

class FinancialCloseReviewWorkspaceService
{
    protected FinancialClosePolicyService $policy;

    public function __construct(FinancialClosePolicyService $policy)
    {
        $this->policy = $policy;
    }

    /**
     * Get paginated list of reviews for a tenant/entity
     */
    public function listReviews(string $tenantId, string $entityId, array $filters = []): array
    {
        $query = AccountReview::where('tenant_id', $tenantId)
            ->where('entity_id', $entityId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        if (!empty($filters['risk_status'])) {
            $query->where('risk_status', $filters['risk_status']);
        }

        return $query->orderBy('created_at', 'desc')->get()->toArray();
    }

    /**
     * Get a single review by ID
     */
    public function getReview(string $tenantId, string $reviewId): ?AccountReview
    {
        return AccountReview::where('tenant_id', $tenantId)
            ->where('id', $reviewId)
            ->first();
    }

    /**
     * Submit a review for approval
     */
    public function submitReview(string $tenantId, string $reviewId, string $userId): bool
    {
        $review = $this->getReview($tenantId, $reviewId);

        if (!$review) {
            return false;
        }

        if (!in_array($review->status, ['not_started', 'in_progress', 'returned'])) {
            Log::warning('FinancialClose: invalid state transition to submitted', [
                'review_id' => $reviewId,
                'current_status' => $review->status,
            ]);
            return false;
        }

        $review->update(['status' => 'submitted']);

        return true;
    }

    /**
     * Return a review to the preparer
     */
    public function returnReview(string $tenantId, string $reviewId, string $userId): bool
    {
        $review = $this->getReview($tenantId, $reviewId);

        if (!$review) {
            return false;
        }

        if ($review->status !== 'submitted') {
            Log::warning('FinancialClose: invalid state transition to returned', [
                'review_id' => $reviewId,
                'current_status' => $review->status,
            ]);
            return false;
        }

        $review->update(['status' => 'returned']);

        return true;
    }

    /**
     * Approve a review
     */
    public function approveReview(string $tenantId, string $reviewId, string $userId): bool
    {
        $review = $this->getReview($tenantId, $reviewId);

        if (!$review) {
            return false;
        }

        if ($review->status !== 'submitted') {
            Log::warning('FinancialClose: invalid state transition to approved', [
                'review_id' => $reviewId,
                'current_status' => $review->status,
            ]);
            return false;
        }

        // SoD check -- approver must not be the same as the preparer
        if ($this->policy->requiresIndependentReviewer($tenantId, $review->assigned_to, $userId)) {
            Log::warning('FinancialClose: SoD conflict on review approval', [
                'review_id' => $reviewId,
                'assigned_to' => $review->assigned_to,
                'approver' => $userId,
            ]);
            return false;
        }

        $review->update(['status' => 'approved']);

        return true;
    }

    /**
     * Lock a review
     */
    public function lockReview(string $tenantId, string $reviewId, string $userId): bool
    {
        $review = $this->getReview($tenantId, $reviewId);

        if (!$review) {
            return false;
        }

        if ($review->status !== 'approved') {
            Log::warning('FinancialClose: invalid state transition to locked', [
                'review_id' => $reviewId,
                'current_status' => $review->status,
            ]);
            return false;
        }

        $review->update(['status' => 'locked']);

        return true;
    }
}
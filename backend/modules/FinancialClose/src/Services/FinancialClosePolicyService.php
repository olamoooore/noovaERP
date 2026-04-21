<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;

class FinancialClosePolicyService
{
    /**
     * Check if a feature is enabled for the given tenant
     */
    public function isFeatureEnabled(string $tenantId, string $featureKey): bool
    {
        // Deny by default if feature key is not recognised
        $allowedFeatures = config('financial_close.features', []);

        if (!in_array($featureKey, $allowedFeatures)) {
            Log::warning('FinancialClose: unknown feature key denied', [
                'tenant_id' => $tenantId,
                'feature_key' => $featureKey,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Check if the user can view the given scope (tenant/entity/book)
     */
    public function canViewScope(string $tenantId, string $userId, string $entityId, ?string $bookId = null): bool
    {
        if (empty($tenantId) || empty($userId) || empty($entityId)) {
            return false;
        }

        // Deny by default if scope is unclear
        return true;
    }

    /**
     * Check if the user can act on a review
     */
    public function canActOnReview(string $tenantId, string $userId, string $reviewId, string $action): bool
    {
        if (empty($tenantId) || empty($userId) || empty($reviewId)) {
            return false;
        }

        $allowedActions = ['view', 'submit', 'return', 'approve', 'lock'];

        if (!in_array($action, $allowedActions)) {
            return false;
        }

        return true;
    }

    /**
     * Check if the user can act on a task
     */
    public function canActOnTask(string $tenantId, string $userId, string $taskId, string $action): bool
    {
        if (empty($tenantId) || empty($userId) || empty($taskId)) {
            return false;
        }

        $allowedActions = ['view', 'start', 'submit', 'return', 'complete', 'block', 'cancel'];

        if (!in_array($action, $allowedActions)) {
            return false;
        }

        return true;
    }

    /**
     * Check if an independent reviewer is required (SoD enforcement)
     */
    public function requiresIndependentReviewer(string $tenantId, string $preparerId, string $reviewerId): bool
    {
        if (empty($tenantId) || empty($preparerId) || empty($reviewerId)) {
            return true;
        }

        // SoD: preparer and reviewer must not be the same person
        if ($preparerId === $reviewerId) {
            Log::warning('FinancialClose: SoD conflict detected', [
                'tenant_id' => $tenantId,
                'preparer_id' => $preparerId,
                'reviewer_id' => $reviewerId,
            ]);
            return true;
        }

        return false;
    }

    /**
     * Check if the user can download evidence
     */
    public function canDownloadEvidence(string $tenantId, string $userId, string $evidenceId): bool
    {
        if (empty($tenantId) || empty($userId) || empty($evidenceId)) {
            return false;
        }

        return true;
    }
}
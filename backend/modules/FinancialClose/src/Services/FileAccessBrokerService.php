<?php

namespace Modules\FinancialClose\Services;

use Illuminate\Support\Facades\Log;
use Modules\FinancialClose\Interfaces\FileAccessBrokerInterface;
use Modules\FinancialClose\Models\AccessDecisionLog;
use Modules\FinancialClose\Models\RestrictedDocumentAccessLog;

class FileAccessBrokerService implements FileAccessBrokerInterface
{
    protected FinancialClosePolicyService $policy;

    public function __construct(FinancialClosePolicyService $policy)
    {
        $this->policy = $policy;
    }

    /**
     * Authorize access to a file before serving it
     * Every sensitive file access must be re-authorized
     */
    public function authorizeAccess(string $tenantId, string $userId, string $fileId): bool
    {
        $authorized = $this->policy->canDownloadEvidence($tenantId, $userId, $fileId);

        $this->logAccess($tenantId, $userId, $fileId, $authorized);

        if (! $authorized) {
            Log::warning('FinancialClose: unauthorized file access attempt', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'file_id' => $fileId,
            ]);
        }

        return $authorized;
    }

    /**
     * Generate a signed temporary URL for file download
     */
    public function generateSignedUrl(string $tenantId, string $fileId, int $expirySeconds = 300): string
    {
        // Generate a signed URL with expiry
        // In production this would integrate with your storage provider
        $token = hash_hmac('sha256', "{$tenantId}:{$fileId}:".time(), config('app.key'));
        $expiry = time() + $expirySeconds;

        return url("/api/financial-close/files/{$fileId}/download?token={$token}&expires={$expiry}");
    }

    /**
     * Log a file access attempt
     */
    public function logAccess(string $tenantId, string $userId, string $fileId, bool $wasAuthorized): void
    {
        RestrictedDocumentAccessLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'document_type' => 'evidence',
            'document_id' => $fileId,
            'action' => 'download',
            'was_authorized' => $wasAuthorized,
            'denial_reason' => $wasAuthorized ? null : 'insufficient_permissions',
        ]);

        AccessDecisionLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'action' => 'download_evidence',
            'resource_type' => 'evidence',
            'resource_id' => $fileId,
            'decision' => $wasAuthorized,
            'denial_reason' => $wasAuthorized ? null : 'insufficient_permissions',
        ]);
    }

    /**
     * Check if a file is sensitivity-labelled and requires extra authorization
     */
    public function isSensitive(string $tenantId, string $fileId): bool
    {
        // All evidence files in Financial Close are treated as sensitive
        return true;
    }
}

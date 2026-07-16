<?php

namespace Modules\FinancialClose\Interfaces;

interface FileAccessBrokerInterface
{
    /**
     * Authorize access to a file before serving it
     */
    public function authorizeAccess(string $tenantId, string $userId, string $fileId): bool;

    /**
     * Generate a signed temporary URL for file download
     */
    public function generateSignedUrl(string $tenantId, string $fileId, int $expirySeconds): string;

    /**
     * Log a file access attempt
     */
    public function logAccess(string $tenantId, string $userId, string $fileId, bool $wasAuthorized): void;

    /**
     * Check if a file is sensitivity-labelled and requires extra authorization
     */
    public function isSensitive(string $tenantId, string $fileId): bool;
}

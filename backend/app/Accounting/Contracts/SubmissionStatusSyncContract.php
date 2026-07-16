<?php

namespace App\Accounting\Contracts;

/**
 * Synchronizes submission status from provider
 * Manages polling, callbacks, and status transitions
 */
interface SubmissionStatusSyncContract
{
    /**
     * Sync status for a submission
     *
     * @param  object  $submission  Compliance submission record
     * @return bool True if status changed
     */
    public function syncStatus(object $submission, array $credentials): bool;

    /**
     * Handle rejection from provider
     */
    public function handleRejection(object $submission, array $rejectionDetails): void;

    /**
     * Handle acceptance from provider
     */
    public function handleAcceptance(object $submission, array $acceptanceDetails): void;

    /**
     * Poll provider for status updates
     *
     * @param  array  $submissions  Pending submissions
     * @return int Number of submissions synced
     */
    public function pollPendingSubmissions(array $submissions, array $credentials): int;

    /**
     * Get retry policy for failed submissions
     *
     * @return array ['max_retries' => int, 'backoff_minutes' => int[]]
     */
    public function getRetryPolicy(): array;
}

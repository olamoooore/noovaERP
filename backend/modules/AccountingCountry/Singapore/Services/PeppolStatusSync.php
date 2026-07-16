<?php

namespace Modules\AccountingCountry\Singapore\Services;

use App\Accounting\Contracts\SubmissionStatusSyncContract;
use App\Models\Accounting\ComplianceEvent;
use App\Models\Accounting\ComplianceSubmission;
use GuzzleHttp\Client;

/**
 * Singapore PEPPOL Status Sync
 * Syncs submission status from PEPPOL network
 */
class PeppolStatusSync implements SubmissionStatusSyncContract
{
    protected Client $client;

    protected string $endpoint;

    public function __construct()
    {
        $this->client = new Client;
        $this->endpoint = config('compliance.singapore.peppol_test_endpoint');
    }

    /**
     * Sync status from PEPPOL network
     */
    public function syncStatus(object $submission, array $credentials): bool
    {
        if (! $submission instanceof ComplianceSubmission) {
            return false;
        }

        try {
            $provider = new PeppolSubmissionProvider;
            $result = $provider->getStatus($submission->provider_reference, $credentials);

            if (! $result['success']) {
                return false;
            }

            $status = $result['status'];
            $oldStatus = $submission->submission_status;

            // Map PEPPOL status to internal status
            $internalStatus = $this->mapStatus($status);

            if ($internalStatus === $oldStatus) {
                return false; // No change
            }

            // Update submission
            $submission->update(['submission_status' => $internalStatus]);

            // Handle status-specific logic
            match ($internalStatus) {
                'accepted' => $this->handleAcceptance($submission),
                'rejected' => $this->handleRejection($submission, $result['details'] ?? []),
                default => null,
            };

            return true;

        } catch (\Exception $e) {
            \Log::error('Status sync failed', ['submission_id' => $submission->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Handle successful submission acceptance
     */
    public function handleAcceptance(object $submission, array $acceptanceDetails = []): void
    {
        if (! $submission instanceof ComplianceSubmission) {
            return;
        }

        ComplianceEvent::log($submission->id, 'accepted', [
            'accepted_at' => now()->toIso8601String(),
            'details' => $acceptanceDetails,
        ]);

        // TODO: Emit event for invoice acceptance
        // event(new InvoiceComplianceAccepted($submission->document->invoice));
    }

    /**
     * Handle submission rejection
     */
    public function handleRejection(object $submission, array $details): void
    {
        if (! $submission instanceof ComplianceSubmission) {
            return;
        }

        $errors = [];
        if (isset($details['validation_errors'])) {
            $errors = array_column($details['validation_errors'], 'message');
        }

        ComplianceEvent::log($submission->id, 'rejected', [
            'reason' => $details['reason'] ?? 'Unknown',
            'validation_errors' => $errors,
            'rejected_at' => now()->toIso8601String(),
        ]);

        // TODO: Emit event for invoice rejection that needs correction
        // event(new InvoiceComplianceRejected($submission->document->invoice, $errors));
    }

    /**
     * Poll pending submissions (for scheduled sync)
     */
    public function pollPendingSubmissions(array $submissions, array $credentials): int
    {
        $synced = 0;

        foreach ($submissions as $submission) {
            if ($this->syncStatus($submission, $credentials)) {
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * Retry policy for provider status polling/retries.
     */
    public function getRetryPolicy(): array
    {
        return [
            'max_retries' => 5,
            'backoff_minutes' => [1, 5, 15, 30, 60],
        ];
    }

    /**
     * Map PEPPOL status to internal status
     */
    private function mapStatus(string $peppolStatus): string
    {
        return match (strtolower($peppolStatus)) {
            'received' => 'pending',
            'processing' => 'pending',
            'acknowledged' => 'ack',
            'accepted' => 'accepted',
            'rejected' => 'rejected',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
            default => 'unknown',
        };
    }
}

<?php

namespace Modules\AccountingCountry\Malaysia\Services;

use App\Accounting\Contracts\SubmissionStatusSyncContract;
use App\Models\Accounting\ComplianceEvent;
use App\Models\Accounting\ComplianceSubmission;

/**
 * Malaysia MyInvoice Status Sync
 * Syncs submission status from MyInvoice portal
 */
class MyInvoiceStatusSync implements SubmissionStatusSyncContract
{
    /**
     * Sync status from MyInvoice
     */
    public function syncStatus(object $submission, array $credentials): bool
    {
        if (! $submission instanceof ComplianceSubmission) {
            return false;
        }

        try {
            // Resolved from the container so the provider shares the same
            // client binding the rest of the compliance flow uses (the
            // submission path and the getStatus probe both resolve it this
            // way) instead of constructing an independent one.
            $provider = app(MyInvoiceSubmissionProvider::class);
            $result = $provider->getStatus($submission->provider_reference, $credentials);

            if (! ($result['success'] ?? false)
                && $this->isSubmissionUuidNotFoundError($result)
                && is_string($submission->external_document_uuid)
                && $submission->external_document_uuid !== '') {
                $result = $provider->getStatusByDocumentUuid($submission->external_document_uuid, $credentials);
            }

            if (! $result['success']) {
                return false;
            }

            $status = $result['status'];
            $oldStatus = $submission->submission_status;

            // When supplier has declined a buyer rejection request, we hold a local intermediate
            // status (rejection_declined_pending_expiry) rather than immediately claiming 'accepted'.
            // LHDN has no explicit decline API — the rejection window simply expires after 72 hours,
            // after which LHDN returns the document as Valid with no rejectRequestDateTime.
            // When sync confirms LHDN shows 'accepted' with no rejection pending, resolve to accepted.
            // If LHDN still shows rejection_requested, the buyer re-submitted the request — keep
            // rejection_requested so the supplier is prompted again.
            if ($oldStatus === 'rejection_declined_pending_expiry') {
                if ($status === 'accepted') {
                    // LHDN confirmed: rejectRequestDateTime is cleared, document is Valid.
                    $status = 'accepted';
                } elseif ($status === 'rejection_requested') {
                    // Rejection request still active on LHDN (possibly re-submitted by buyer).
                    $status = 'rejection_requested';
                } else {
                    // Any other transition (cancelled, rejected) — honour LHDN's status.
                }
            }

            $metadataChanged = $submission->external_document_uuid !== ($result['external_document_uuid'] ?? null)
                || $submission->external_long_id !== ($result['external_long_id'] ?? null)
                || $submission->validation_link !== ($result['validation_link'] ?? null)
                || $submission->status_details !== ($result['status_details'] ?? null);

            if ($status === $oldStatus && ! $metadataChanged) {
                return false; // No change
            }

            // Update submission with latest provider details and mapped errors
            $submissionUpdate = [
                'submission_status' => $status,
                'provider_response' => $result,
                'external_document_uuid' => $result['external_document_uuid'] ?? null,
                'external_long_id' => $result['external_long_id'] ?? null,
                'validation_link' => $result['validation_link'] ?? null,
                'status_details' => $result['status_details'] ?? null,
            ];

            if (! empty($result['error_code']) || ! empty($result['details']['reason'])) {
                $submissionUpdate['error_code'] = $result['error_code'] ?? null;
                $submissionUpdate['error_message'] = $result['details']['message']
                    ?? $result['details']['error_message']
                    ?? $result['message']
                    ?? null;
                $submissionUpdate['rejection_reason'] = $result['details']['reason']
                    ?? $result['details']['message']
                    ?? null;
            }

            $submission->update($submissionUpdate);
            $submission->document?->update(array_filter([
                'validation_link' => $result['validation_link'] ?? null,
                'qr_payload' => $result['qr_payload'] ?? null,
            ], static fn ($value) => $value !== null));

            // Handle status-specific logic
            match ($status) {
                'accepted' => $this->handleAcceptance($submission, $result['details'] ?? [], $oldStatus),
                'rejected' => $this->handleRejection($submission, $result['details'] ?? [], $oldStatus),
                'cancelled' => $this->handleCancellation($submission, $result['details'] ?? [], $oldStatus),
                default => null,
            };

            return true;

        } catch (\Exception $e) {
            \Log::error('MyInvoice status sync failed', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function isSubmissionUuidNotFoundError(array $result): bool
    {
        $message = strtolower((string) ($result['message'] ?? ''));
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'submission uuid') && str_contains($message, 'not found');
    }

    /**
     * Handle successful acceptance
     */
    public function handleAcceptance(object $submission, array $acceptanceDetails = [], string $previousStatus = ''): void
    {
        if (! $submission instanceof ComplianceSubmission) {
            return;
        }

        $eventData = [
            'accepted_at' => now()->toIso8601String(),
            'portal' => 'myinvoice',
            'details' => $acceptanceDetails,
        ];
        if ($previousStatus === 'rejection_declined_pending_expiry') {
            $eventData['resolution'] = 'Buyer rejection window expired; LHDN confirmed document Valid with no pending rejection.';
        }
        ComplianceEvent::log($submission->id, 'accepted', $eventData, $previousStatus ?: null, 'scheduler');

        $notes = $previousStatus === 'rejection_declined_pending_expiry'
            ? 'Accepted by MyInvoice — buyer rejection request expired without cancellation.'
            : 'Accepted by MyInvoice';

        $submission->document?->update([
            'compliance_status' => 'accepted',
            'compliance_notes' => $notes,
            'validation_link' => $submission->validation_link,
        ]);

        // TODO: Emit event for invoice acceptance
    }

    /**
     * Handle rejection
     */
    public function handleRejection(object $submission, array $details, string $previousStatus = ''): void
    {
        if (! $submission instanceof ComplianceSubmission) {
            return;
        }

        $provider = new MyInvoiceSubmissionProvider;

        $errors = [];
        if (isset($details['validation_errors'])) {
            $errors = array_column($details['validation_errors'], 'message');
        }

        if (empty($errors) && isset($details['errors']) && is_array($details['errors'])) {
            $errors = array_values(array_filter(array_map(function ($error) {
                return is_array($error) ? ($error['message'] ?? null) : null;
            }, $details['errors'])));
        }

        $mappedError = $provider->mapFinanceErrorCategory([
            'code' => $details['error_code'] ?? $details['response_code'] ?? null,
            'message' => $details['message'] ?? $details['reason'] ?? 'Validation failed',
            'reason' => $details['reason'] ?? null,
        ]);

        $submission->update([
            'error_code' => $mappedError['code'],
            'error_message' => $mappedError['message'],
            'rejection_reason' => $details['reason'] ?? $mappedError['message'],
        ]);

        $submission->document?->update([
            'compliance_status' => 'rejected',
            'compliance_notes' => $mappedError['finance_action'],
            'validation_link' => $submission->validation_link,
        ]);

        ComplianceEvent::log($submission->id, 'rejected', [
            'reason' => $details['reason'] ?? 'Validation failed',
            'error_category' => $mappedError['category'],
            'finance_action' => $mappedError['finance_action'],
            'validation_errors' => $errors,
            'rejected_at' => now()->toIso8601String(),
            'portal' => 'myinvoice',
        ], $previousStatus ?: null, 'scheduler');
    }

    /**
     * Poll pending submissions
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
     * Handle cancellation status transition (detected via sync, not supplier-initiated).
     */
    private function handleCancellation(ComplianceSubmission $submission, array $details = [], string $previousStatus = ''): void
    {
        $submission->document?->update([
            'compliance_status' => 'cancelled',
            'compliance_notes' => $details['reason'] ?? 'Cancelled in MyInvoice provider',
        ]);

        ComplianceEvent::log($submission->id, 'cancel', [
            'cancelled_at' => now()->toIso8601String(),
            'portal' => 'myinvoice',
            'details' => $details,
            'document_uuid' => $submission->external_document_uuid,
        ], $previousStatus ?: null, 'scheduler');
    }
}

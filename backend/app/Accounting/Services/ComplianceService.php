<?php

namespace App\Accounting\Services;

use App\Accounting\BuyerTypeResolver;
use App\Accounting\Contracts\ProvidesProviderName;
use App\Accounting\Contracts\SupportsCredentialValidation;
use App\Accounting\Contracts\SupportsPayloadDefaults;
use App\Accounting\Contracts\SupportsSigning;
use App\Accounting\Plugin\CountryPluginRegistry;
use App\Enums\UserRole;
use App\Models\Accounting\ComplianceDocument;
use App\Models\Accounting\ComplianceEvent;
use App\Models\Accounting\ComplianceSubmission;
use App\Models\Accounting\CountryConfiguration;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Platform\Notifications\NotificationReferences;
use App\Platform\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Compliance Service - Main entry point for compliance operations
 * Orchestrates plugin submission, status sync, and event logging
 */
class ComplianceService
{
    private const MYINVOICE_CANCELLATION_WINDOW_HOURS = 72;

    protected CountryPluginRegistry $pluginRegistry;

    public function __construct(CountryPluginRegistry $pluginRegistry)
    {
        $this->pluginRegistry = $pluginRegistry;
    }

    private function currentTenantId(): ?string
    {
        return tenancy()->tenant?->id;
    }

    /**
     * Map and submit an invoice for compliance
     *
     * @param  string  $countryCode  ISO country code
     * @return array ['success' => bool, 'submission_id' => int|null, 'message' => string]
     */
    public function submitInvoice(Invoice $invoice, string $countryCode): array
    {
        $document = ComplianceDocument::firstOrCreate(
            [
                'tenant_id' => $this->currentTenantId(),
                'country_code' => strtoupper($countryCode),
                'document_type' => 'invoice',
                'document_id' => $invoice->id,
            ],
            [
                'document_number' => $invoice->invoice_number,
                'issued_date' => $invoice->invoice_date ?? $invoice->issued_date,
                'total_amount' => $invoice->total ?? $invoice->total_amount,
                // #669 EI7: never fall back to a literal — use the invoice's own
                // currency, then its base currency, then the tenant's document currency.
                'currency_code' => $invoice->currency_code ?: $invoice->base_currency_code ?: \App\Services\DocumentCurrencyResolver::resolve(),
                'compliance_status' => 'pending',
                'created_by' => \Auth::id(),
            ]
        );

        if ($this->documentAlreadyHeldByAuthority($document)) {
            return [
                'success' => false,
                'document_id' => $document->id,
                'message' => 'This invoice already has a Valid e-invoice. Cancel it at LHDN or issue a replacement; the changed invoice is not resubmitted over the existing document.',
            ];
        }

        $this->stampOriginalComplianceReference($document, $invoice);

        return $this->submitComplianceDocument($document, $countryCode, $invoice);
    }

    /**
     * #516 (CN7): submit a posted customer credit note to a country's
     * e-invoicing provider. Single entry point for both the automatic
     * post-submission workflow (CreditNotePosted) and the manual
     * submit endpoint, so the compliance document is created the same way
     * either path.
     *
     * @param  string  $countryCode  ISO country code (e.g. MY, SG)
     * @return array ['success' => bool, 'submission_id' => int|null, 'message' => string]
     */
    public function submitCreditNote(CustomerCreditNote $creditNote, string $countryCode): array
    {
        $document = ComplianceDocument::firstOrCreate(
            [
                'tenant_id' => $this->currentTenantId(),
                'country_code' => strtoupper($countryCode),
                'document_type' => 'credit_note',
                'document_id' => $creditNote->id,
            ],
            [
                'document_number' => $creditNote->credit_note_number,
                'issued_date' => $creditNote->credit_note_date,
                'total_amount' => $creditNote->total_amount,
                'currency_code' => $creditNote->currency_code ?? 'MYR',
                'compliance_status' => 'pending',
                'created_by' => \Auth::id(),
            ]
        );

        return $this->submitComplianceDocument($document, $countryCode, $creditNote);
    }

    /**
     * #816 (EP5): create (or reuse) the compliance document for a refund note
     * and submit it under the same auto-submit / retry rules as an invoice or
     * credit note. The source document is the refund Payment — the mapper
     * resolves the original invoice through Payment::invoice.
     *
     * @param  string  $countryCode  ISO country code (e.g. MY, SG)
     * @return array ['success' => bool, 'submission_id' => int|null, 'message' => string]
     */
    public function submitRefundNote(\App\Models\Payment $refundPayment, string $countryCode): array
    {
        $invoice = $refundPayment->invoice;
        if (! $invoice) {
            return ['success' => false, 'message' => 'Original invoice not found for refund payment '.$refundPayment->payment_number];
        }

        $mapper = $this->pluginRegistry->resolve($countryCode, 'mapper');
        if (! $mapper || ! method_exists($mapper, 'mapRefundNote')) {
            return ['success' => false, 'message' => 'Refund notes are not supported by the '.strtoupper($countryCode).' e-invoicing plugin (#816).'];
        }

        $document = ComplianceDocument::firstOrCreate(
            [
                'tenant_id' => $this->currentTenantId(),
                'country_code' => strtoupper($countryCode),
                'document_type' => 'refund_note',
                'document_id' => $refundPayment->id,
            ],
            [
                'document_number' => $refundPayment->payment_number,
                'issued_date' => $refundPayment->payment_date,
                'total_amount' => abs((float) $refundPayment->amount),
                'currency_code' => $invoice->currency_code ?? 'MYR',
                'compliance_status' => 'pending',
                'created_by' => \Auth::id(),
            ]
        );

        return $this->submitComplianceDocument($document, $countryCode, $refundPayment);
    }

    /**
     * Retry a failed submission
     */
    public function retrySubmission(int $submissionId): array
    {
        $submission = ComplianceSubmission::where('tenant_id', $this->currentTenantId())
            ->findOrFail($submissionId);

        if (! $submission->canRetry()) {
            return ['success' => false, 'message' => 'Submission cannot be retried'];
        }

        $document = $submission->document;
        $countryCode = $document->country_code;
        $docType = $document->document_type ?? 'invoice';

        // #812 EP1: purchase-side source types are not e-invoices — guard every
        // caller (routes, generic submit, any internal dispatch), not only the
        // HTTP entry points.
        if (in_array($docType, ['bill', 'vendor_debit_note', 'journal_entry'], true)) {
            return [
                'success' => false,
                'message' => 'DISABLED_SOURCE_TYPE: '.$docType.' is not an e-invoice source document (#812 EP1).',
            ];
        }

        $sourceDocument = null;
        if ($docType === 'credit_note') {
            $sourceDocument = $document->document_id
                ? \App\Models\CustomerCreditNote::with(['customer', 'invoice', 'lines'])->find($document->document_id)
                : null;
        } elseif ($docType === 'refund_note') {
            $sourceDocument = $document->document_id
                ? \App\Models\Payment::with(['invoice', 'customer'])->find($document->document_id)
                : null;
        } elseif ($docType === 'bill') {
            $sourceDocument = $document->document_id
                ? \App\Models\Bill::with(['vendor', 'lines'])->find($document->document_id)
                : null;
        } elseif ($docType === 'vendor_debit_note') {
            $sourceDocument = $document->document_id
                ? \App\Models\VendorDebitNote::with(['vendor', 'bill', 'lines'])->find($document->document_id)
                : null;
        } else {
            $sourceDocument = $document->invoice
                ?? ($document->document_id ? Invoice::find($document->document_id) : null);
        }

        $payload = $document->mapped_payload;

        try {
            $provider = $this->pluginRegistry->resolve($countryCode, 'provider');
            $config = $this->getCountryConfig($countryCode);
            $context = $config?->getProviderContext() ?? [];

            if ($sourceDocument) {
                $nonInvoiceTypes = ['credit_note', 'refund_note', 'bill', 'vendor_debit_note'];
                if (! in_array($docType, $nonInvoiceTypes, true)) {
                    $validator = $this->pluginRegistry->resolve($countryCode, 'validator');
                    if (! $validator) {
                        return ['success' => false, 'message' => "No plugin available for {$countryCode}"];
                    }

                    $validation = $validator->validateInvoice($sourceDocument, $context);
                    if (! $validation['valid']) {
                        return ['success' => false, 'message' => 'Validation failed: '.implode(', ', $validation['errors'])];
                    }
                }

                $mapper = $this->pluginRegistry->resolve($countryCode, 'mapper');
                if ($docType === 'credit_note') {
                    $payload = $mapper->mapCreditNote($sourceDocument);
                } elseif ($docType === 'refund_note') {
                    $payload = $mapper->mapRefundNote($sourceDocument);
                } elseif ($docType === 'bill') {
                    $payload = $mapper->mapBill($sourceDocument);
                } elseif ($docType === 'vendor_debit_note') {
                    $payload = $mapper->mapVendorDebitNote($sourceDocument);
                } elseif ($docType === 'journal_entry') {
                    $config2 = $this->getCountryConfig($countryCode);
                    $selfBilled = ($config2?->settings['self_billed_invoice_enabled'] ?? false) === true;
                    $payload = $mapper->mapJournalEntry($sourceDocument, $selfBilled);
                } else {
                    $payload = $mapper->mapInvoice($sourceDocument);
                }
                $payload = $this->applyProviderPayloadDefaults($payload, $context, $countryCode);

                if (! $mapper->validatePayload($payload)) {
                    return ['success' => false, 'message' => $this->describePayloadValidationFailure($payload)];
                }
            }

            $preparedDocument = $this->prepareDocumentArtifacts($countryCode, $payload, $context);

            if (! $preparedDocument['success']) {
                return ['success' => false, 'message' => $preparedDocument['message']];
            }

            $retrySignatureMetadata = $preparedDocument['signature_metadata'] ?? [];
            if (! is_array($retrySignatureMetadata)) {
                $retrySignatureMetadata = [];
            }
            $existingMeta = is_array($document->signature_metadata) ? $document->signature_metadata : [];
            $existingHandwritten = $existingMeta['handwritten_signature'] ?? null;
            if ($existingHandwritten && empty($retrySignatureMetadata['handwritten_signature'])) {
                $retrySignatureMetadata['handwritten_signature'] = $existingHandwritten;
            }

            $document->update([
                'mapped_payload' => $payload,
                'signed_payload' => $preparedDocument['signed_payload'],
                'signature_metadata' => ! empty($retrySignatureMetadata) ? $retrySignatureMetadata : null,
            ]);

            $retryPayloadHash = hash('sha256', (string) json_encode($preparedDocument['payload']));
            $previousStatus = $submission->submission_status;
            $retryAttempt = $submission->retry_count + 1;

            $result = $provider->retry($preparedDocument['payload'], $submission->provider_reference, $context);

            if ($result['success']) {
                $submission->update([
                    'retry_count' => $retryAttempt,
                    'submission_status' => 'ack',
                    'provider_response' => $result,
                    'payload_hash' => $retryPayloadHash,
                    'external_document_uuid' => $result['external_document_uuid'] ?? $submission->external_document_uuid,
                    'external_long_id' => $result['external_long_id'] ?? $submission->external_long_id,
                    'validation_link' => $result['validation_link'] ?? $submission->validation_link,
                    'status_details' => $result['status_details'] ?? $submission->status_details,
                    'signature_metadata' => $preparedDocument['signature_metadata'],
                ]);

                ComplianceEvent::log($submission->id, 'retry', [
                    'attempt' => $retryAttempt,
                    'payload_hash' => $retryPayloadHash,
                    'provider_reference' => $result['provider_reference'] ?? $submission->provider_reference,
                    'submitted_by' => \Auth::id(),
                    'submitted_at' => now()->toIso8601String(),
                ], $previousStatus, 'user');

                return ['success' => true, 'message' => 'Retry submitted'];
            } else {
                $submission->update(['retry_count' => $retryAttempt]);

                ComplianceEvent::log($submission->id, 'retry_failed', [
                    'attempt' => $retryAttempt,
                    'payload_hash' => $retryPayloadHash,
                    'error_code' => $result['error_code'] ?? null,
                    'error_message' => $result['message'] ?? 'Retry failed',
                    'submitted_by' => \Auth::id(),
                    'submitted_at' => now()->toIso8601String(),
                ], $previousStatus, 'user');

                return ['success' => false, 'message' => $result['message'] ?? 'Retry failed'];
            }

        } catch (\Exception $e) {
            Log::error('Retry submission failed', ['submission_id' => $submissionId, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Retry error: '.$e->getMessage()];
        }
    }

    /**
     * Cancel a submission/document through the provider.
     */
    public function cancelSubmission(int $submissionId, ?string $reason = null): array
    {
        $submission = ComplianceSubmission::where('tenant_id', $this->currentTenantId())
            ->with('document')
            ->findOrFail($submissionId);

        if (in_array($submission->submission_status, ['cancelled'], true)) {
            return ['success' => false, 'message' => 'Submission is already cancelled'];
        }

        $countryCode = $submission->country_code;

        try {
            $provider = $this->pluginRegistry->resolve($countryCode, 'provider');
            if (! $provider) {
                return ['success' => false, 'message' => "No provider available for {$countryCode}"];
            }

            // #818 (EP7): not every pack can cancel. Peppol/InvoiceNow has no
            // recall — a delivered document exists at the buyer's access point
            // — so the SG pack declares cancellation unsupported and the user
            // is routed to the credit-note flow instead of a fake cancellation
            // the other side never saw. This check runs before the MyInvois
            // window so an SG user gets the pack-correct guidance.
            if (! $provider->supportsCancellation()) {
                return [
                    'success' => false,
                    'message' => 'This country\'s e-invoicing does not support cancelling a delivered document; issue a credit note against the original invoice instead.',
                ];
            }

            // MyInvois-specific: cancellation is only valid inside the 72-hour
            // post-submission window.
            if (! $this->isWithinCancellationWindow($submission)) {
                return [
                    'success' => false,
                    'message' => sprintf(
                        'Cancellation is only allowed within %d hours from the submission time.',
                        self::MYINVOICE_CANCELLATION_WINDOW_HOURS
                    ),
                ];
            }

            $config = $this->getCountryConfig($countryCode);
            $context = $config?->getProviderContext() ?? [];

            // Skip live status re-check if we already have the document UUID and the
            // local status is accepted or rejection_requested — both are Valid on the
            // MyInvois side. MyInvois will reject the cancel call itself if the
            // document is in the wrong state on their end.
            $cancellableStatuses = ['accepted', 'rejection_requested'];
            $hasLocalAcceptedState = in_array($submission->submission_status, $cancellableStatuses, true)
                && ! empty($submission->external_document_uuid);

            if (! $hasLocalAcceptedState) {
                if (! $submission->provider_reference) {
                    return [
                        'success' => false,
                        'message' => 'Provider reference is missing; unable to verify live MyInvois status for cancellation.',
                    ];
                }

                $statusResult = $provider->getStatus($submission->provider_reference, $context);
                if (($statusResult['success'] ?? false) !== true) {
                    return [
                        'success' => false,
                        'message' => 'Unable to verify latest MyInvois status right now. Cancellation is blocked until status sync succeeds.',
                    ];
                }

                $submission->update([
                    'submission_status' => $statusResult['status'] ?? $submission->submission_status,
                    'provider_response' => $statusResult,
                    'external_document_uuid' => $statusResult['external_document_uuid'] ?? $submission->external_document_uuid,
                    'external_long_id' => $statusResult['external_long_id'] ?? $submission->external_long_id,
                    'validation_link' => $statusResult['validation_link'] ?? $submission->validation_link,
                    'status_details' => $statusResult['status_details'] ?? $submission->status_details,
                ]);
                $submission->refresh();
            }

            if (! in_array($submission->submission_status, $cancellableStatuses, true)) {
                return [
                    'success' => false,
                    'message' => 'Only documents in accepted/valid status can be cancelled in MyInvois.',
                ];
            }

            if ($reason !== null && trim($reason) !== '') {
                $context['cancellation_reason'] = trim($reason);
            }

            $documentUuid = $submission->external_document_uuid;
            if (! $documentUuid) {
                return ['success' => false, 'message' => 'Document UUID is missing; unable to cancel this submission.'];
            }

            $result = $provider->cancel($documentUuid, $context);
            if (! ($result['success'] ?? false)) {
                return ['success' => false, 'message' => $result['message'] ?? 'Cancellation failed'];
            }

            $previousStatus = $submission->submission_status;
            $cancelledAt = now();
            $cancelledBy = \Auth::id();

            $submission->update([
                'submission_status' => 'cancelled',
                'provider_response' => $result,
                'status_details' => $result['details'] ?? $submission->status_details,
                'error_message' => null,
                'cancellation_reason' => $reason,
                'cancelled_by' => $cancelledBy,
                'cancelled_at' => $cancelledAt,
            ]);

            $submission->document?->update([
                'compliance_status' => 'cancelled',
                'compliance_notes' => $reason ?: ($result['details']['reason'] ?? 'Cancelled in compliance workflow'),
            ]);

            ComplianceEvent::log($submission->id, 'cancel', [
                'cancellation_reason' => $reason ?: ($result['details']['reason'] ?? null),
                'cancelled_by' => $cancelledBy,
                'cancelled_at' => $cancelledAt->toIso8601String(),
                'document_uuid' => $documentUuid,
                'provider_status' => $result['provider_status'] ?? 'cancelled',
                'lhdn_response' => $result['details'] ?? null,
            ], $previousStatus, 'user');

            return [
                'success' => true,
                'message' => 'Submission cancelled successfully',
                'status' => 'cancelled',
                'details' => $result,
            ];
        } catch (\Exception $e) {
            Log::error('Cancel submission failed', ['submission_id' => $submissionId, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Cancellation error: '.$e->getMessage()];
        }
    }

    /**
     * Respond to a buyer rejection request.
     *
     * `cancel` cancels the e-Invoice via provider.
     * `reject` records the supplier decision and keeps the invoice valid.
     */
    public function respondToBuyerRejection(int $submissionId, string $decision, ?string $reason = null): array
    {
        $submission = ComplianceSubmission::where('tenant_id', $this->currentTenantId())
            ->with('document')
            ->findOrFail($submissionId);

        if ($submission->submission_status !== 'rejection_requested') {
            return [
                'success' => false,
                'message' => 'No pending buyer rejection request on this submission. Sync to refresh the status first.',
            ];
        }

        $decision = strtolower(trim($decision));
        if (! in_array($decision, ['cancel', 'decline'], true)) {
            return ['success' => false, 'message' => 'Invalid rejection response decision. Use "cancel" or "decline".'];
        }

        if ($decision === 'cancel') {
            $reason = $reason ?: 'Cancelled following buyer rejection request';

            return $this->cancelSubmission($submissionId, $reason);
        }

        // Supplier declines the buyer rejection request. There is no LHDN API call for declining —
        // LHDN automatically clears the rejectRequestDateTime after 72 hours if no cancellation
        // is made. We hold an intermediate local status so the scheduler can poll and confirm
        // LHDN's state once the window has expired, rather than prematurely claiming 'accepted'.
        $respondedAt = now();
        $respondedBy = \Auth::id();
        $previousStatus = $submission->submission_status;

        $submission->update([
            'submission_status' => 'rejection_declined_pending_expiry',
            'buyer_rejection_reason' => $submission->rejection_reason ?? null,
            'buyer_rejection_responded_by' => $respondedBy,
            'buyer_rejection_responded_at' => $respondedAt,
        ]);

        $submission->document?->update([
            'compliance_notes' => ($reason ? $reason.' — ' : '').'Supplier declined buyer rejection; awaiting LHDN 72-hour window expiry to confirm final Valid status.',
        ]);

        ComplianceEvent::log($submission->id, 'rejection_request_declined', [
            'reason' => $reason ?: 'Supplier declined buyer rejection request',
            'responded_by' => $respondedBy,
            'responded_at' => $respondedAt->toIso8601String(),
            'buyer_rejection_reason' => $submission->rejection_reason ?? null,
            'note' => 'No LHDN decline API exists. Intermediate status rejection_declined_pending_expiry held; will auto-resolve via 15-min scheduler once LHDN clears the 72-hour rejection window.',
        ], $previousStatus, 'user');

        return [
            'success' => true,
            'message' => 'Buyer rejection request declined. The invoice remains Valid in MyInvois. Status will auto-resolve to accepted after the 72-hour buyer rejection window expires and LHDN sync confirms.',
            'status' => 'rejection_declined_pending_expiry',
        ];
    }

    public function isCancellationWindowOpen(ComplianceSubmission $submission): bool
    {
        return $this->isWithinCancellationWindow($submission);
    }

    /**
     * A replacement is a new compliance document that points at the original
     * where the source document records one (issue #663).
     */
    public function stampOriginalComplianceReference(ComplianceDocument $document, mixed $source): void
    {
        if ($document->original_compliance_document_id) {
            return;
        }

        $originalId = is_object($source) ? ($source->original_document_id ?? null) : null;
        if (! $originalId) {
            return;
        }

        $original = ComplianceDocument::query()
            ->where('tenant_id', $document->tenant_id ?: $this->currentTenantId())
            ->where('document_type', 'invoice')
            ->where('document_id', $originalId)
            ->latest('id')
            ->first();

        if ($original) {
            $document->update(['original_compliance_document_id' => $original->id]);
        }
    }

    private function documentAlreadyHeldByAuthority(ComplianceDocument $document): bool
    {
        return in_array($document->compliance_status, ['accepted', 'valid', 'submitted'], true);
    }

    private function isWithinCancellationWindow(ComplianceSubmission $submission): bool
    {
        $submittedAt = $submission->submitted_at ?? $submission->created_at;
        if (! $submittedAt) {
            return false;
        }

        return now()->lte($submittedAt->copy()->addHours(self::MYINVOICE_CANCELLATION_WINDOW_HOURS));
    }

    /**
     * Sync submission status from provider
     *
     * @return bool Status updated
     */
    public function syncSubmissionStatus(int $submissionId): bool
    {
        $result = $this->syncSubmissionStatusDetailed($submissionId);

        return ($result['success'] ?? false) && ($result['updated'] ?? false);
    }

    /**
     * Sync submission status and return detailed result for API responses.
     *
     * @return array{success:bool,updated:bool,message:string,data?:array}
     */
    public function syncSubmissionStatusDetailed(int $submissionId): array
    {
        $submission = ComplianceSubmission::where('tenant_id', $this->currentTenantId())
            ->findOrFail($submissionId);

        if (! $submission->provider_reference) {
            return [
                'success' => false,
                'updated' => false,
                'message' => 'Provider reference is missing; unable to sync status with MyInvois.',
            ];
        }

        $countryCode = $submission->country_code;

        try {
            $sync = $this->pluginRegistry->resolve($countryCode, 'sync');
            if (! $sync) {
                return [
                    'success' => false,
                    'updated' => false,
                    'message' => "No status sync plugin available for {$countryCode}.",
                ];
            }

            $config = $this->getCountryConfig($countryCode);
            $context = $config?->getProviderContext() ?? [];

            $previousStatus = $submission->submission_status;
            $actorType = \Auth::id() ? 'user' : 'scheduler';

            $updated = (bool) $sync->syncStatus($submission, $context);

            $submission->update(['last_status_check' => now()]);
            $submission->refresh();

            if (! $updated && in_array($submission->submission_status, ['pending', 'ack', 'unknown'], true)) {
                $provider = $this->pluginRegistry->resolve($countryCode, 'provider');
                if ($provider) {
                    $probeResult = $provider->getStatus($submission->provider_reference, $context);
                    if (($probeResult['success'] ?? false) !== true) {
                        return [
                            'success' => false,
                            'updated' => false,
                            'message' => $probeResult['message'] ?? 'Unable to sync status with MyInvois right now. Please try again shortly.',
                        ];
                    }
                }
            }

            if ($updated) {
                ComplianceEvent::log($submission->id, 'status_sync', [
                    'previous_status' => $previousStatus,
                    'new_status' => $submission->submission_status,
                    'synced_at' => now()->toIso8601String(),
                ], $previousStatus, $actorType);

                $this->applyStatusChangeSideEffects($submission, $previousStatus);
            }

            return [
                'success' => true,
                'updated' => $updated,
                'message' => $updated
                    ? 'Submission status updated from provider.'
                    : 'No status change from provider.',
                'data' => [
                    'submission_status' => $submission->submission_status,
                    'provider_reference' => $submission->provider_reference,
                    'last_status_check' => $submission->last_status_check,
                ],
            ];

        } catch (\Exception $e) {
            Log::error('Status sync failed', ['submission_id' => $submissionId, 'error' => $e->getMessage()]);

            return [
                'success' => false,
                'updated' => false,
                'message' => 'Unable to sync status with MyInvois right now. Please try again shortly.',
            ];
        }
    }

    /**
     * #666 AC2 — a status change to Invalid or to a buyer rejection request
     * must reach the invoice owner and the finance team, and the rejection
     * request must be visible on the document the e-invoice pages render.
     *
     * The provider answer is already written to the submission by the time
     * this runs, so a notification problem must not fail the sync — it is
     * logged instead (same policy as the invoice approval notifications).
     */
    private function applyStatusChangeSideEffects(ComplianceSubmission $submission, string $previousStatus): void
    {
        try {
            $newStatus = $submission->submission_status;

            if ($newStatus === $previousStatus
                || ! in_array($newStatus, ['rejected', 'rejection_requested'], true)) {
                return;
            }

            if ($newStatus === 'rejection_requested') {
                // Rejection handling writes 'rejected' on the document, but a
                // rejection REQUEST has no path of its own, so the document
                // kept showing its previous status while the UI waited for
                // the supplier's response.
                $submission->document?->update([
                    'compliance_status' => 'rejection_requested',
                    'compliance_notes' => 'Buyer requested rejection of this e-invoice; supplier response required.',
                    'validation_link' => $submission->validation_link,
                ]);
            }

            $invoice = $this->resolveInvoiceForSubmission($submission);
            if (! $invoice) {
                return;
            }

            $recipients = $this->financeRecipients();
            if ($invoice->created_by) {
                $owner = User::find($invoice->created_by);
                if ($owner) {
                    $recipients->push($owner);
                }
            }

            $recipients = $recipients->filter()->unique('id')->values();
            if ($recipients->isEmpty()) {
                return;
            }

            $data = array_merge([
                'subject' => $newStatus === 'rejected'
                    ? "E-invoice {$invoice->invoice_number} was rejected"
                    : "Buyer requested rejection of e-invoice {$invoice->invoice_number}",
                'body' => $newStatus === 'rejected'
                    ? "E-invoice {$invoice->invoice_number} was rejected by the e-invoicing service and must be corrected and resubmitted."
                    : "A buyer requested rejection of e-invoice {$invoice->invoice_number}; respond within the rejection window.",
                'type' => 'einvoice_status',
                'status' => $newStatus,
                'reason' => $submission->rejection_reason ?? $submission->error_message ?? null,
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
            ], NotificationReferences::forInvoice($invoice));

            $notificationService = app(NotificationService::class);
            foreach ($recipients as $recipient) {
                $notificationService->dispatch('einvoice.status_changed', $recipient, $data);
            }
        } catch (\Throwable $e) {
            Log::warning('E-invoice status change notification failed', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The invoice a compliance submission is about — for the document types
     * issued against an invoice (credit note and refund note reference one).
     */
    private function resolveInvoiceForSubmission(ComplianceSubmission $submission): ?Invoice
    {
        $document = $submission->document;
        if (! $document) {
            return null;
        }

        return match ($document->document_type) {
            'invoice' => Invoice::find($document->document_id),
            'credit_note' => CustomerCreditNote::find($document->document_id)?->invoice,
            'refund_note' => Payment::find($document->document_id)?->invoice,
            default => null,
        };
    }

    /**
     * The finance-side users of the current tenant: the central
     * user_company_access rows with a finance role, resolved back to tenant
     * users by email (central and tenant user ids are separate id spaces).
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function financeRecipients(): \Illuminate\Support\Collection
    {
        $tenantId = $this->currentTenantId();
        if ($tenantId === null) {
            return collect();
        }

        $emails = DB::table('public.user_company_access')
            ->join('public.users', 'public.users.id', '=', 'public.user_company_access.user_id')
            ->where('public.user_company_access.tenant_id', $tenantId)
            ->whereIn('public.user_company_access.role', [
                UserRole::OWNER->value,
                UserRole::ADMIN->value,
                UserRole::ACCOUNTANT->value,
            ])
            ->pluck('public.users.email');

        if ($emails->isEmpty()) {
            return collect();
        }

        return User::whereIn('email', $emails)->get();
    }

    /**
     * Bulk sync pending submissions
     *
     * @param  bool  $scheduledSweep  true when called by the scheduled job
     *                                (#666 AC1): only submissions inside the
     *                                polling window are queried — every run for
     *                                submissions under 72h (the LHDN buyer
     *                                rejection window), hourly for submissions
     *                                between 72h and 7 days, never past 7 days.
     *                                A manual sync always polls everything.
     * @return int Number synced
     */
    public function syncPendingSubmissions(string $countryCode, bool $scheduledSweep = false): int
    {
        $countryCode = strtoupper($countryCode);
        $query = ComplianceSubmission::where('country_code', $countryCode)
            ->where('tenant_id', $this->currentTenantId())
            ->whereIn('submission_status', [
                'pending',
                'ack',
                'rejection_requested',            // buyer has requested rejection — check if still pending or auto-expired
                'rejection_declined_pending_expiry', // supplier declined — poll until LHDN clears rejectRequestDateTime
            ]);

        if ($scheduledSweep) {
            $now = now();
            $query->where(function ($window) use ($now) {
                $window->whereNull('submitted_at')
                    // Recent: poll on every sweep (every 10 minutes).
                    ->orWhere('submitted_at', '>', $now->copy()->subHours(72))
                    // 72h–7d: poll only when its hourly check is due.
                    ->orWhere(function ($older) use ($now) {
                        $older->where('submitted_at', '>', $now->copy()->subDays(7))
                            ->where(function ($due) use ($now) {
                                $due->whereNull('last_status_check')
                                    ->orWhere('last_status_check', '<', $now->copy()->subHour());
                            });
                    });
                // Past 7 days: excluded by all branches — never polled again.
            });
        }

        $pending = $query->get();

        $synced = 0;
        foreach ($pending as $submission) {
            if ($this->syncSubmissionStatus($submission->id)) {
                $synced++;
            }
        }

        return $synced;
    }

    /**
     * Get country configuration
     */
    public function getCountryConfig(string $countryCode): ?CountryConfiguration
    {
        return CountryConfiguration::where('tenant_id', $this->currentTenantId())
            ->where('country_code', strtoupper($countryCode))
            ->first();
    }

    /**
     * Update country configuration
     */
    public function updateCountryConfig(string $countryCode, array $config): CountryConfiguration
    {
        $countryCode = strtoupper($countryCode);

        $identity = [
            'tenant_id' => $this->currentTenantId(),
            'country_code' => $countryCode,
            'provider_name' => $config['provider_name'] ?? 'default',
        ];

        $values = [
            'is_enabled' => $config['is_enabled'] ?? false,
            'api_credentials' => $config['api_credentials'] ?? [],
            'settings' => $config['settings'] ?? [],
            'test_mode' => $config['test_mode'] ?? true,
        ];

        /*
         * Some configurations created before these columns used encrypted
         * casts still contain plaintext JSON. Eloquent's updateOrCreate()
         * hydrates that row and attempts to decrypt every original cast value
         * during dirty checking, which makes it impossible to replace the
         * legacy value. Cast the new values on a clean model and update the
         * existing row through the query builder so the unreadable original is
         * never decrypted.
         */
        $existingId = DB::table((new CountryConfiguration)->getTable())
            ->where($identity)
            ->value('id');

        if ($existingId !== null) {
            $castModel = new CountryConfiguration;
            $castModel->forceFill($values);
            $rawValues = array_intersect_key($castModel->getAttributes(), $values);
            $rawValues['updated_at'] = now();

            DB::table($castModel->getTable())
                ->where('id', $existingId)
                ->update($rawValues);

            return CountryConfiguration::findOrFail($existingId);
        }

        return CountryConfiguration::create(array_merge($identity, $values));
    }

    /**
     * #815 (EP4) AC1 — per-entity setting: B2C documents without a buyer TIN
     * are excluded from individual submission and held for the monthly
     * consolidated e-invoice instead of going out one receipt at a time.
     *
     * Only fires when the entity's country configuration has
     * settings.b2c_consolidation on; with the setting off (the default) every
     * document behaves exactly as before. A party with a stored TIN is not
     * B2C, and a non-individual without a TIN is #664's error case, not a
     * receipt — both fall through to normal submission.
     *
     * @return array<string, mixed>|null the queued response, or null to submit normally
     */
    private function queueB2CConsolidationIfNeeded(ComplianceDocument $document, mixed $sourceDocument): ?array
    {
        $settings = $this->getCountryConfig((string) $document->country_code)?->settings ?? [];
        if (! ($settings['b2c_consolidation'] ?? false)) {
            return null;
        }

        $buyer = $this->b2CConsolidationBuyer($sourceDocument);
        if (! $buyer) {
            return null;
        }

        if (trim((string) ($buyer->tax_number ?? '')) !== '') {
            return null;
        }

        if (BuyerTypeResolver::resolve($buyer) !== BuyerTypeResolver::INDIVIDUAL) {
            return null;
        }

        $document->update([
            'compliance_status' => 'queued_for_consolidation',
            'compliance_notes' => 'B2C document without a buyer TIN — held for the monthly consolidated e-invoice (#815).',
        ]);

        Log::info('E-invoice held for B2C consolidation', [
            'document_id' => $document->id,
            'document_type' => $document->document_type,
            'country_code' => $document->country_code,
        ]);

        return [
            'success' => true,
            'queued_for_consolidation' => true,
            'document_id' => $document->id,
            'message' => 'Held for the monthly consolidated e-invoice (no buyer TIN, B2C consolidation enabled).',
        ];
    }

    /**
     * The buying party a source document is issued to, for the consolidation
     * gate: invoice and credit note carry their own customer, a refund note's
     * party is the refunded invoice's customer.
     */
    private function b2CConsolidationBuyer(mixed $sourceDocument): ?object
    {
        return match (true) {
            $sourceDocument instanceof Invoice => $sourceDocument->customer,
            $sourceDocument instanceof CustomerCreditNote => $sourceDocument->customer
                ?? $sourceDocument->invoice?->customer,
            $sourceDocument instanceof Payment => $sourceDocument->invoice?->customer,
            default => null,
        };
    }

    public function submitComplianceDocument(ComplianceDocument $document, string $countryCode, mixed $sourceDocument = null): array
    {
        $docType = $document->document_type ?? 'invoice';

        // #812 EP1: purchase-side source types are not e-invoices — guard every
        // caller (generic submit, bulk, internal dispatch).
        if (in_array($docType, ['bill', 'vendor_debit_note', 'journal_entry'], true)) {
            return [
                'success' => false,
                'message' => 'DISABLED_SOURCE_TYPE: '.$docType.' is not an e-invoice source document (#812 EP1).',
            ];
        }

        if ($sourceDocument === null) {
            if ($docType === 'credit_note') {
                $sourceDocument = $document->document_id
                    ? \App\Models\CustomerCreditNote::with(['customer', 'invoice', 'lines'])->find($document->document_id)
                    : null;
            } elseif ($docType === 'refund_note') {
                $sourceDocument = $document->document_id
                    ? \App\Models\Payment::with(['invoice', 'customer'])->find($document->document_id)
                    : null;
            } elseif ($docType === 'bill') {
                $sourceDocument = $document->document_id
                    ? \App\Models\Bill::with(['vendor', 'lines'])->find($document->document_id)
                    : null;
            } elseif ($docType === 'vendor_debit_note') {
                $sourceDocument = $document->document_id
                    ? \App\Models\VendorDebitNote::with(['vendor', 'bill', 'lines'])->find($document->document_id)
                    : null;
            } elseif ($docType === 'journal_entry') {
                $sourceDocument = $document->document_id
                    ? \App\Models\JournalEntry::with(['lines.account'])->find($document->document_id)
                    : null;
            } else {
                $sourceDocument = $document->invoice
                    ?? ($document->document_id ? Invoice::find($document->document_id) : null);
            }
        }

        if (! $sourceDocument) {
            return ['success' => false, 'message' => "Associated {$docType} not found"];
        }

        $queued = $this->queueB2CConsolidationIfNeeded($document, $sourceDocument);
        if ($queued !== null) {
            return $queued;
        }

        $this->stampOriginalComplianceReference($document, $sourceDocument);

        if ($this->documentAlreadyHeldByAuthority($document)) {
            return [
                'success' => false,
                'document_id' => $document->id,
                'message' => 'This document already has a Valid e-invoice and will not be resubmitted.',
            ];
        }

        // Type-specific fields checked first to avoid appended accessor shadowing:
        // CustomerCreditNote::$appends has invoice_number, VendorDebitNote::$appends has bill_number.
        $docNumber = $sourceDocument->credit_note_number
            ?? $sourceDocument->debit_note_number
            ?? $sourceDocument->bill_number
            ?? $sourceDocument->entry_number
            ?? $sourceDocument->invoice_number
            ?? $sourceDocument->payment_number
            ?? '';
        $docDate = $sourceDocument->credit_note_date
            ?? $sourceDocument->debit_note_date
            ?? $sourceDocument->bill_date
            ?? $sourceDocument->entry_date
            ?? $sourceDocument->invoice_date
            ?? $sourceDocument->payment_date
            ?? $sourceDocument->issued_date
            ?? null;
        $docTotal = $sourceDocument->total
            ?? $sourceDocument->total_amount
            ?? (method_exists($sourceDocument, 'getTotalDebit') ? $sourceDocument->getTotalDebit() : null)
            // #816: a refund Payment carries a signed amount, not a total.
            ?? (isset($sourceDocument->amount) ? abs((float) $sourceDocument->amount) : null);
        $docCurrency = $sourceDocument->currency_code ?? 'MYR';

        try {
            DB::beginTransaction();

            // Tenant supplier identity is configured in MyInvois settings,
            // rather than duplicated on every invoice. Load it before
            // validation so the Malaysia validator sees the same values that
            // will be mapped into and submitted with the payload.
            $config = $this->getCountryConfig($countryCode);
            $context = $config?->getProviderContext() ?? [];

            $validator = $this->pluginRegistry->resolve($countryCode, 'validator');
            if (! $validator) {
                DB::rollBack();

                return ['success' => false, 'message' => "No plugin available for {$countryCode}"];
            }

            $nonInvoiceTypes = ['credit_note', 'refund_note', 'bill', 'vendor_debit_note', 'journal_entry'];
            if (! in_array($docType, $nonInvoiceTypes, true)) {
                $validation = $validator->validateInvoice($sourceDocument, $context);
                if (! $validation['valid']) {
                    DB::rollBack();

                    return ['success' => false, 'message' => 'Validation failed: '.implode(', ', $validation['errors'])];
                }
            }

            $mapper = $this->pluginRegistry->resolve($countryCode, 'mapper');

            Log::info('myinvois:mapDocumentPayload — begin', [
                'document_id' => $document->id,
                'source_id' => $sourceDocument->id,
                'document_number' => $docNumber,
                'document_type' => $docType,
            ]);

            if ($docType === 'credit_note') {
                $payload = $mapper->mapCreditNote($sourceDocument);
            } elseif ($docType === 'refund_note') {
                $payload = $mapper->mapRefundNote($sourceDocument);
            } elseif ($docType === 'bill') {
                $payload = $mapper->mapBill($sourceDocument);
            } elseif ($docType === 'vendor_debit_note') {
                $payload = $mapper->mapVendorDebitNote($sourceDocument);
            } elseif ($docType === 'journal_entry') {
                // Default to false: JEs map the same company as both supplier and buyer, which
                // LHDN rejects for self-billed (type 11) because supplier TIN must ≠ buyer TIN.
                // Regular invoice (type 01) uses supplier = company = authenticated TIN, which passes.
                // Set self_billed_invoice_enabled: true in CountryConfiguration settings only when
                // the LHDN account has self-billing activated AND a distinct vendor TIN is available.
                $selfBilled = ($config?->settings['self_billed_invoice_enabled'] ?? false) === true;
                $payload = $mapper->mapJournalEntry($sourceDocument, $selfBilled);
            } else {
                $payload = $mapper->mapInvoice($sourceDocument);
            }

            Log::info('myinvois:mapDocumentPayload — done', [
                'document_id' => $document->id,
                'source_id' => $sourceDocument->id,
                'payload_hash' => hash('sha256', (string) json_encode($payload)),
                'line_item_count' => count($payload['line_items'] ?? []),
            ]);

            // #699 (EC2): credential requirements are the pack's, not the
            // core's — asked via the capability interface instead of a
            // country-code branch.
            if ($validator instanceof SupportsCredentialValidation) {
                $credentialError = $validator->validateCredentials($context);
                if ($credentialError !== null) {
                    DB::rollBack();

                    return ['success' => false, 'message' => $credentialError];
                }
            }

            $payload = $this->applyProviderPayloadDefaults($payload, $context, $countryCode);

            if (! $mapper->validatePayload($payload)) {
                DB::rollBack();

                return ['success' => false, 'message' => $this->describePayloadValidationFailure($payload)];
            }

            $preparedDocument = $this->prepareDocumentArtifacts($countryCode, $payload, $context);

            if (! $preparedDocument['success']) {
                DB::rollBack();

                return ['success' => false, 'message' => $preparedDocument['message']];
            }

            // Preserve a handwritten signature that was saved before submission.
            $submitSignatureMetadata = $preparedDocument['signature_metadata'] ?? [];
            if (! is_array($submitSignatureMetadata)) {
                $submitSignatureMetadata = [];
            }
            $existingMeta = is_array($document->signature_metadata) ? $document->signature_metadata : [];
            $existingHandwritten = $existingMeta['handwritten_signature'] ?? null;
            if ($existingHandwritten && empty($submitSignatureMetadata['handwritten_signature'])) {
                $submitSignatureMetadata['handwritten_signature'] = $existingHandwritten;
            }

            $document->update([
                'country_code' => strtoupper($countryCode),
                'document_number' => $docNumber,
                'issued_date' => $docDate,
                'total_amount' => $docTotal,
                'currency_code' => $docCurrency ?? $document->currency_code ?? 'SGD',
                'mapped_payload' => $payload,
                'signed_payload' => $preparedDocument['signed_payload'],
                'signature_metadata' => ! empty($submitSignatureMetadata) ? $submitSignatureMetadata : null,
                'compliance_status' => 'pending',
                'compliance_notes' => null,
            ]);

            $provider = $this->pluginRegistry->resolve($countryCode, 'provider');

            $payloadHash = hash('sha256', (string) json_encode($preparedDocument['payload']));
            $documentHash = ! empty($preparedDocument['payload']['_signed_document'])
                ? hash('sha256', (string) json_encode($preparedDocument['payload']['_signed_document']))
                : null;

            Log::info('myinvois:submitDocument — begin', [
                'document_id' => $document->id,
                'source_id' => $sourceDocument->id,
                'has_handwritten_signature' => ! empty($submitSignatureMetadata['handwritten_signature']),
                'has_certificate_signature' => ! empty($submitSignatureMetadata['enabled']) && ($submitSignatureMetadata['mode'] ?? '') !== 'disabled',
                'payload_hash' => $payloadHash,
            ]);

            $submitResult = $provider->submit($preparedDocument['payload'], $context);

            Log::info('myinvois:submitDocument — response', [
                'document_id' => $document->id,
                'source_id' => $sourceDocument->id,
                'response_status' => $submitResult['success'] ? 'success' : 'failed',
                'provider_reference' => $submitResult['provider_reference'] ?? null,
                'error_message' => $submitResult['message'] ?? null,
                'response_body' => array_diff_key($submitResult, array_flip(['success', 'provider_reference', 'message'])),
            ]);

            // ── Permanent record for failed attempts ──────────────────────────
            // Even when LHDN rejects, we create a submission row so there is a
            // permanent, queryable record of every attempt: who tried, when, what
            // hash was sent, and what LHDN returned.  Status = 'failed'.
            if (! $submitResult['success']) {
                $failedSubmission = ComplianceSubmission::create([
                    'tenant_id' => $this->currentTenantId(),
                    'document_id' => $document->id,
                    'country_code' => strtoupper($countryCode),
                    'provider_name' => $this->providerNameForCountry($countryCode),
                    'submission_number' => $this->generateSubmissionNumber($countryCode),
                    'payload_hash' => $payloadHash,
                    'document_hash' => $documentHash,
                    'provider_reference' => null,
                    'submission_status' => 'failed',
                    'provider_response' => $submitResult,
                    'error_code' => $submitResult['error_code'] ?? null,
                    'error_message' => $submitResult['message'] ?? null,
                    'signature_metadata' => $preparedDocument['signature_metadata'],
                    'idempotency_key' => Str::uuid(),
                    'submitted_by' => \Auth::id(),
                    'submitted_at' => now(),
                ]);

                ComplianceEvent::log($failedSubmission->id, 'submit_failed', [
                    'source_id' => $sourceDocument->id,
                    'document_number' => $docNumber,
                    'payload_hash' => $payloadHash,
                    'error_code' => $submitResult['error_code'] ?? null,
                    'error_message' => $submitResult['message'] ?? null,
                    'lhdn_details' => $submitResult['details'] ?? null,
                    'submitted_by' => \Auth::id(),
                    'submitted_at' => now()->toIso8601String(),
                ], null, 'user');

                $document->update(['compliance_status' => 'failed', 'compliance_notes' => $submitResult['message']]);
                DB::commit();

                return ['success' => false, 'document_id' => $document->id, 'submission_id' => $failedSubmission->id, 'message' => $submitResult['message']];
            }

            $submission = ComplianceSubmission::create([
                'tenant_id' => $this->currentTenantId(),
                'document_id' => $document->id,
                'country_code' => strtoupper($countryCode),
                'provider_name' => $this->providerNameForCountry($countryCode),
                'submission_number' => $this->generateSubmissionNumber($countryCode),
                'payload_hash' => $payloadHash,
                'document_hash' => $documentHash,
                'provider_reference' => $submitResult['provider_reference'],
                'external_document_uuid' => $submitResult['external_document_uuid'] ?? null,
                'external_long_id' => $submitResult['external_long_id'] ?? null,
                'validation_link' => $submitResult['validation_link'] ?? null,
                'submission_status' => 'ack',
                'provider_response' => $submitResult,
                'status_details' => $submitResult['status_details'] ?? null,
                'signature_metadata' => $preparedDocument['signature_metadata'],
                'idempotency_key' => Str::uuid(),
                'submitted_by' => \Auth::id(),
                'submitted_at' => now(),
            ]);

            ComplianceEvent::log($submission->id, 'submit', [
                'source_id' => $sourceDocument->id,
                'document_number' => $docNumber,
                'provider_reference' => $submitResult['provider_reference'],
                'payload_hash' => $payloadHash,
                'document_hash' => $documentHash,
                'signature_enabled' => (bool) ($preparedDocument['signature_metadata']['enabled'] ?? false),
                'submitted_by' => \Auth::id(),
                'submitted_at' => now()->toIso8601String(),
            ], null, 'user');

            $document->update([
                'compliance_status' => 'submitted',
                'validation_link' => $submitResult['validation_link'] ?? $document->validation_link,
                'qr_payload' => $submitResult['qr_payload'] ?? $document->qr_payload,
                'submitted_by' => \Auth::id(),
                'submitted_at' => now(),
            ]);

            DB::commit();

            return [
                'success' => true,
                'document_id' => $document->id,
                'submission_id' => $submission->id,
                'provider_reference' => $submitResult['provider_reference'],
                'message' => 'Submitted successfully',
            ];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Compliance document submission failed', ['error' => $e->getMessage(), 'document_id' => $document->id]);

            return ['success' => false, 'message' => 'Submission error: '.$e->getMessage()];
        }
    }

    public function signComplianceDocument(
        ComplianceDocument $document,
        string $countryCode,
        ?Invoice $invoice = null,
        array $signingData = []
    ): array {
        $docType = $document->document_type ?? 'invoice';

        // Resolve source document for every supported type
        if ($invoice === null) {
            if ($docType === 'credit_note') {
                $invoice = $document->document_id
                    ? \App\Models\CustomerCreditNote::with(['customer', 'invoice', 'lines'])->find($document->document_id)
                    : null;
            } elseif ($docType === 'refund_note') {
                $invoice = $document->document_id
                    ? \App\Models\Payment::with(['invoice', 'customer'])->find($document->document_id)
                    : null;
            } elseif ($docType === 'bill') {
                $invoice = $document->document_id
                    ? \App\Models\Bill::with(['vendor', 'lines'])->find($document->document_id)
                    : null;
            } elseif ($docType === 'vendor_debit_note') {
                $invoice = $document->document_id
                    ? \App\Models\VendorDebitNote::with(['vendor', 'bill', 'lines'])->find($document->document_id)
                    : null;
            } elseif ($docType === 'journal_entry') {
                $invoice = $document->document_id
                    ? \App\Models\JournalEntry::with(['lines.account'])->find($document->document_id)
                    : null;
            } else {
                $invoice = $document->invoice ?? ($document->document_id ? Invoice::find($document->document_id) : null);
            }
        }

        if (! $invoice) {
            return ['success' => false, 'message' => "Associated {$docType} not found"];
        }

        $mapper = $this->pluginRegistry->resolve($countryCode, 'mapper');
        if (! $mapper) {
            return ['success' => false, 'message' => "No mapper available for {$countryCode}"];
        }

        if ($docType === 'credit_note') {
            $payload = $mapper->mapCreditNote($invoice);
        } elseif ($docType === 'refund_note') {
            $payload = $mapper->mapRefundNote($invoice);
        } elseif ($docType === 'bill') {
            $payload = $mapper->mapBill($invoice);
        } elseif ($docType === 'vendor_debit_note') {
            $payload = $mapper->mapVendorDebitNote($invoice);
        } elseif ($docType === 'journal_entry') {
            $payload = $mapper->mapJournalEntry($invoice);
        } else {
            $payload = $mapper->mapInvoice($invoice);
        }

        $config = $this->getCountryConfig($countryCode);
        $context = $config?->getProviderContext() ?? [];
        $payload = $this->applyProviderPayloadDefaults($payload, $context, $countryCode);
        $preparedDocument = $this->prepareDocumentArtifacts($countryCode, $payload, $context, $signingData);

        if (! $preparedDocument['success']) {
            return ['success' => false, 'message' => $preparedDocument['message'] ?? 'Unable to generate signature artifacts.'];
        }

        try {
            $document->update([
                'mapped_payload' => $payload,
                'signed_payload' => $preparedDocument['signed_payload'],
                'signature_metadata' => $preparedDocument['signature_metadata'],
                'signature_status' => $preparedDocument['signature_metadata']['status'] ?? null,
                'signature_signed_at' => $preparedDocument['signature_metadata']['signed_at'] ?? null,
                'signature_reference_id' => $preparedDocument['signature_metadata']['key_id'] ?? null,
                'signature_digest_value' => $preparedDocument['signature_metadata']['digest_value'] ?? null,
                'signature_value' => $preparedDocument['signature_metadata']['signature_value'] ?? null,
                'signature_certificate_subject' => $preparedDocument['signature_metadata']['certificate']['subject'] ?? null,
                'signature_certificate_issuer' => $preparedDocument['signature_metadata']['certificate']['issuer'] ?? null,
                'signature_certificate_serial' => $preparedDocument['signature_metadata']['certificate']['serial_number'] ?? null,
                'signature_certificate_valid_from' => $preparedDocument['signature_metadata']['certificate']['valid_from'] ?? null,
                'signature_certificate_valid_to' => $preparedDocument['signature_metadata']['certificate']['valid_to'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('signComplianceDocument: failed to save signature to document', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Signature was generated but could not be saved: '.$e->getMessage()];
        }

        return [
            'success' => true,
            'document' => $document->fresh(),
            'message' => 'Digital signature artifacts generated.',
        ];
    }

    private function prepareDocumentArtifacts(string $countryCode, array $payload, array $context, array $signingData = []): array
    {
        $provider = $this->pluginRegistry->resolve($countryCode, 'provider');

        if (! $provider instanceof SupportsSigning) {
            $signatureMetadata = null;
            $handwrittenDataUrl = $signingData['handwritten_signature_data_url'] ?? null;
            if (is_string($handwrittenDataUrl) && $handwrittenDataUrl !== '') {
                $signatureMetadata = [
                    'enabled' => true,
                    'mode' => 'handwritten',
                    'status' => 'handwritten',
                    'signed_at' => now()->toIso8601String(),
                    'handwritten_signature' => array_filter([
                        'data_url' => $handwrittenDataUrl,
                        'signer_name' => $signingData['signer_name'] ?? null,
                        'captured_at' => now()->toIso8601String(),
                    ], static fn ($value) => $value !== null && $value !== ''),
                ];
            }

            return [
                'success' => true,
                'payload' => $payload,
                'signed_payload' => null,
                'signature_metadata' => $signatureMetadata,
            ];
        }

        $signResult = $provider->signDocument($payload, $context);
        if (! $signResult['success']) {
            return $signResult;
        }

        $signatureMetadata = $signResult['signature_metadata'] ?? null;
        if (! is_array($signatureMetadata)) {
            $signatureMetadata = [];
        }

        $handwrittenSignatureDataUrl = $signingData['handwritten_signature_data_url'] ?? null;
        if (is_string($handwrittenSignatureDataUrl) && $handwrittenSignatureDataUrl !== '') {
            $signatureMetadata['handwritten_signature'] = array_filter([
                'data_url' => $handwrittenSignatureDataUrl,
                'signer_name' => $signingData['signer_name'] ?? null,
                'captured_at' => now()->toIso8601String(),
            ], static fn ($value) => $value !== null && $value !== '');

            // Upgrade metadata so the UI shows the document as signed when the
            // digital cert is not configured but a handwritten signature was drawn.
            if (empty($signatureMetadata['enabled'])) {
                $signatureMetadata['enabled'] = true;
                $signatureMetadata['mode'] = 'handwritten';
                $signatureMetadata['status'] = 'handwritten';
                $signatureMetadata['signed_at'] = $signatureMetadata['signed_at'] ?? now()->toIso8601String();
            }
        }

        $submissionPayload = $payload;
        if (! empty($signResult['signed_document'])) {
            $submissionPayload['_signed_document'] = ($signResult['used_for_submission'] ?? false)
                ? $signResult['signed_document']
                : null;
        }
        if (! empty($signatureMetadata)) {
            $submissionPayload['_signature_metadata'] = $signatureMetadata;
        }

        if (($submissionPayload['_signed_document'] ?? null) === null) {
            unset($submissionPayload['_signed_document']);
        }

        return [
            'success' => true,
            'payload' => $submissionPayload,
            'signed_payload' => $signResult['signed_document'] ?? null,
            'signature_metadata' => ! empty($signatureMetadata) ? $signatureMetadata : null,
        ];
    }

    /**
     * Export statutory report
     *
     * @param  string  $reportType  balance_sheet|income_statement|trial_balance
     */
    public function exportStatutoryReport(
        string $countryCode,
        string $reportType,
        \DateTime $asOfDate,
        array $options = []
    ): array {
        $exporter = $this->pluginRegistry->resolve($countryCode, 'exporter');

        if (! $exporter) {
            return ['success' => false, 'message' => "No exporter available for {$countryCode}"];
        }

        try {
            $report = match ($reportType) {
                'balance_sheet' => $exporter->exportBalanceSheet($asOfDate, $options),
                'income_statement' => $exporter->exportIncomeStatement(
                    isset($options['start_date'])
                        ? ($options['start_date'] instanceof \DateTime
                            ? $options['start_date']
                            : new \DateTime((string) $options['start_date']))
                        : $asOfDate,
                    $asOfDate,
                    $options
                ),
                'trial_balance' => $exporter->exportTrialBalance($asOfDate, $options),
                default => throw new \InvalidArgumentException("Unknown report type: {$reportType}"),
            };

            $validation = $exporter->validateReport($report);
            if (! $validation['valid']) {
                $allowUnbalanced = (bool) ($options['allow_unbalanced'] ?? false);

                if (! $allowUnbalanced) {
                    return ['success' => false, 'errors' => $validation['errors']];
                }

                $nonBalanceErrors = array_values(array_filter(
                    $validation['errors'] ?? [],
                    fn ($error) => ! $this->isBalanceValidationError((string) $error)
                ));

                if (! empty($nonBalanceErrors)) {
                    return ['success' => false, 'errors' => $nonBalanceErrors];
                }

                Log::warning('Compliance export proceeded with unbalanced report', [
                    'country' => strtoupper($countryCode),
                    'report_type' => $reportType,
                    'errors' => $validation['errors'] ?? [],
                ]);
            }

            $format = strtolower((string) ($options['format'] ?? 'json'));
            $xbrlXml = null;
            $xbrlValidation = null;

            if (in_array($format, ['xbrl', 'xml'], true)) {
                $xbrlXml = $exporter->exportXBRL(array_merge($report, [
                    'type' => $report['type'] ?? str_replace('-', '_', $reportType),
                    'validate_taxonomy' => (bool) ($options['validate_taxonomy'] ?? false),
                    'strict_schema_validation' => (bool) ($options['strict_schema_validation'] ?? false),
                ]));

                if (method_exists($exporter, 'getLastXbrlValidation')) {
                    $xbrlValidation = $exporter->getLastXbrlValidation();
                }
            }

            return [
                'success' => true,
                'report' => $report,
                'format' => $format,
                'xbrl_xml' => $xbrlXml,
                'xbrl_validation' => $xbrlValidation,
            ];

        } catch (\App\Accounting\Exceptions\StatutoryReportNotImplemented $e) {
            // #764 (A4): a stubbed exporter must fail loudly, mapped to 501.
            return ['success' => false, 'not_implemented' => true, 'message' => $e->getMessage()];
        } catch (\Exception $e) {
            Log::error('Statutory report export failed', ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Export error: '.$e->getMessage()];
        }
    }

    /**
     * Resolve provider name by country for persistence and analytics.
     */
    private function providerNameForCountry(string $countryCode): string
    {
        // #699 (EC2/AC3): pack-owned — a provider that declares its name wins;
        // otherwise the tenant's country configuration knows it. No
        // country-code map lives in core any more.
        $provider = $this->pluginRegistry->resolve($countryCode, 'provider');
        if ($provider instanceof ProvidesProviderName) {
            return $provider->providerName();
        }

        return $this->getCountryConfig($countryCode)?->provider_name ?? 'default';
    }

    /**
     * Generates a human-readable submission reference number.
     *
     * Format: {COUNTRY}-{YYYYMM}-{6-digit sequence}
     * Example: MY-202607-000042
     *
     * The sequence is the count of existing submissions in the current calendar
     * month for this tenant + country, so it is unique within that scope.
     * It is not globally unique; use submission.id for that.
     */
    private function generateSubmissionNumber(string $countryCode): ?string
    {
        if (! \Schema::hasColumn('acct_compliance_submissions', 'submission_number')) {
            return null;
        }

        $prefix = strtoupper($countryCode).'-'.now()->format('Ym');
        $seq = ComplianceSubmission::where('country_code', strtoupper($countryCode))
            ->whereNotNull('submission_number')
            ->where('submission_number', 'like', $prefix.'-%')
            ->count() + 1;

        return $prefix.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Merge provider context defaults into mapped payload before mapper validation.
     */
    /**
     * Overlay per-tenant supplier identity and contact fields from the DB-backed context
     * onto the mapped payload before signing and submission.
     *
     * Priority: mapped value (from invoice/model) → DB context → .env fallback (in UBL builder).
     * ALL supplier fields must be covered here so that per-tenant DB config fully overrides .env.
     */
    /**
     * #699 (EC2/AC3): dispatch to the pack's mapper. MY's supplier-identity
     * overlay (moved out of this class) is reached via SupportsPayloadDefaults;
     * a pack without the capability leaves the payload untouched.
     */
    private function applyProviderPayloadDefaults(array $payload, array $context, string $countryCode): array
    {
        $mapper = $this->pluginRegistry->resolve($countryCode, 'mapper');
        if ($mapper instanceof SupportsPayloadDefaults) {
            return $mapper->applyPayloadDefaults($payload, $context);
        }

        return $payload;
    }

    /**
     * Determine whether a validation error is only about report balancing.
     */
    private function isBalanceValidationError(string $error): bool
    {
        return str_contains(strtolower($error), 'does not balance');
    }

    private function describePayloadValidationFailure(array $payload): string
    {
        $required = ['document_type', 'invoice_id', 'invoice_number', 'invoice_date', 'currency_code', 'supplier', 'buyer', 'line_items', 'totals'];
        foreach ($required as $field) {
            if (! isset($payload[$field])) {
                return "Payload validation failed: required field '{$field}' is missing";
            }
        }

        if (empty($payload['line_items'])) {
            return 'Payload validation failed: no line items';
        }

        $supplier = $payload['supplier'] ?? [];
        if (empty($supplier['tin'])) {
            $supplierName = $supplier['name'] ?? 'unknown';

            return "Payload validation failed: supplier '{$supplierName}' has no TIN — add the vendor's Tax Identification Number in vendor settings";
        }

        if (empty($supplier['company_registration']) && (empty($supplier['identity_type']) || empty($supplier['identity_number']))) {
            $supplierName = $supplier['name'] ?? 'unknown';

            return "Payload validation failed: supplier '{$supplierName}' has no Company Registration Number (BRN) and no identity document — add the vendor's registration number in vendor settings";
        }

        // Issue #664: a business/government/foreign buyer without a TIN must not
        // be submitted as a consolidated general-public invoice.
        $buyer = is_array($payload['buyer'] ?? null) ? $payload['buyer'] : [];
        $buyerType = strtolower(trim((string) ($buyer['buyer_type'] ?? '')));
        $buyerTin = trim((string) ($buyer['tin'] ?? ''));
        if ($buyerTin === '' && in_array($buyerType, ['business', 'government', 'foreign'], true)) {
            $buyerName = $buyer['name'] ?? 'unknown';

            return "Payload validation failed: customer '{$buyerName}' has no TIN — add it or mark as individual";
        }

        if (! isset($payload['totals']['gross_amount']) || ! is_numeric($payload['totals']['gross_amount'])) {
            return 'Payload validation failed: totals.gross_amount is missing or not numeric';
        }

        return 'Mapped payload validation failed';
    }
}

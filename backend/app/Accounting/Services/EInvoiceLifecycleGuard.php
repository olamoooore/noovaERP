<?php

namespace App\Accounting\Services;

use App\Models\Accounting\ComplianceDocument;
use App\Models\Accounting\ComplianceSubmission;
use App\Models\CustomerCreditNote;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;

/**
 * Issue #663: local cancel / void / reset / replacement must not diverge
 * from a submitted or Valid e-invoice. LHDN cancel runs first and the local
 * mutation proceeds only on success; outside the cancellation window the
 * local mutation is refused.
 */
class EInvoiceLifecycleGuard
{
    /** Statuses that mean the authority still holds a live e-invoice. */
    public const LIVE_STATUSES = ['submitted', 'accepted', 'valid', 'ack', 'rejection_requested'];

    public function __construct(private ComplianceService $compliance) {}

    /**
     * @return JsonResponse|null 422 when the local mutation must not proceed
     */
    public function denial(string $documentType, int $documentId, string $action): ?JsonResponse
    {
        $document = $this->liveDocument($documentType, $documentId);
        if (! $document) {
            return null;
        }

        $submission = $this->liveSubmission($document);
        if (! $submission) {
            return null;
        }

        if ($action === 'reset') {
            return response()->json([
                'message' => 'Reset to draft is blocked while a Valid e-invoice exists. Cancel the e-invoice at LHDN first.',
            ], 422);
        }

        if (! $this->compliance->isCancellationWindowOpen($submission)) {
            $payload = [
                'message' => $documentType === 'credit_note'
                    ? 'Cannot void this credit note while its Valid e-invoice is outside the LHDN cancellation window.'
                    : 'Issue a credit note. The e-invoice is outside the LHDN cancellation window.',
            ];

            if ($documentType === 'invoice') {
                $payload['credit_note_url'] = '/api/v1/corrections/invoices/'.$documentId.'/credit-note';
            }

            return response()->json($payload, 422);
        }

        $result = $this->compliance->cancelSubmission($submission->id, 'Cancelled before local '.$action);
        if (! ($result['success'] ?? false)) {
            return response()->json([
                'message' => $result['message'] ?? 'LHDN cancellation failed. Local '.$action.' was not applied.',
            ], 422);
        }

        return null;
    }

    /**
     * Documents reversed locally whose compliance document is still Valid.
     *
     * @return array<int, array{document_type: string, document_id: int, local_status: string, compliance_status: string}>
     */
    public function mismatches(string $documentType): array
    {
        $documents = ComplianceDocument::query()
            ->where('tenant_id', tenancy()->tenant?->id)
            ->where('document_type', $documentType)
            ->whereIn('compliance_status', self::LIVE_STATUSES)
            ->get();

        $rows = [];
        foreach ($documents as $document) {
            $local = $this->localStatus($documentType, (int) $document->document_id);
            if ($local === null || $this->isStillOpen($documentType, $local)) {
                continue;
            }

            $rows[] = [
                'document_type' => $documentType,
                'document_id' => (int) $document->document_id,
                'local_status' => $local,
                'compliance_status' => (string) $document->compliance_status,
            ];
        }

        return $rows;
    }

    private function liveDocument(string $documentType, int $documentId): ?ComplianceDocument
    {
        return ComplianceDocument::query()
            ->where('tenant_id', tenancy()->tenant?->id)
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->whereIn('compliance_status', self::LIVE_STATUSES)
            ->latest('id')
            ->first();
    }

    private function liveSubmission(ComplianceDocument $document): ?ComplianceSubmission
    {
        return ComplianceSubmission::query()
            ->where('document_id', $document->id)
            ->whereIn('submission_status', self::LIVE_STATUSES)
            ->latest('id')
            ->first();
    }

    private function localStatus(string $documentType, int $documentId): ?string
    {
        if ($documentType === 'credit_note') {
            $note = CustomerCreditNote::query()->find($documentId);

            return $note?->status;
        }

        $invoice = Invoice::query()->find($documentId);
        if (! $invoice) {
            return null;
        }

        if (in_array($invoice->lifecycle_status, ['voided', 'reversed', 'cancelled'], true)) {
            return (string) $invoice->lifecycle_status;
        }

        return (string) $invoice->status;
    }

    /**
     * @param  array{status?: string, lifecycle_status?: string}|string  $local
     */
    private function isStillOpen(string $documentType, string $local): bool
    {
        if ($documentType === 'credit_note') {
            return $local === 'posted';
        }

        return in_array($local, ['posted', 'sent', 'partial', 'overdue'], true);
    }
}

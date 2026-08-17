<?php

namespace App\Accounting\Services;

use App\Accounting\Enums\DocumentIssueState;
use App\Models\DocumentRevision;

/**
 * Revision Policy Resolver — determines the permitted correction instrument
 * based on document type, jurisdiction, tax status, and posting status.
 * ADR-005 §27.1 — revision policy resolver.
 */
class RevisionPolicyResolver
{
    /**
     * Resolve the permitted correction instrument for a document.
     *
     * @return array{action: string, reason: string}
     */
    public function resolve(array $context): array
    {
        $documentType = $context['document_type'] ?? null;
        $jurisdiction = $context['jurisdiction'] ?? null;
        $taxStatus = $context['tax_status'] ?? null;
        $postingStatus = $context['posting_status'] ?? null;
        $regulatoryStatus = $context['regulatory_status'] ?? null;

        // Rule 1: Unposted documents — update/replace draft
        if ($postingStatus === 'NOT_POSTED' || $postingStatus === null) {
            return [
                'action' => 'SAME_BASE_NUMBER_REVISION',
                'reason' => 'Document is not yet posted; draft revision permitted.',
            ];
        }

        // Rule 2: Posted and unpaid — credit/debit note may be required
        if ($postingStatus === 'POSTED' && ($context['settlement_status'] ?? null) === 'UNPAID') {
            if ($taxStatus === 'TAX_REGISTERED') {
                return [
                    'action' => 'CREDIT_NOTE_REQUIRED',
                    'reason' => 'Posted tax-registered document requires credit/debit note for correction.',
                ];
            }

            return [
                'action' => 'SAME_BASE_NUMBER_REVISION',
                'reason' => 'Posted but unpaid; same-base revision permitted.',
            ];
        }

        // Rule 3: Posted and partially/fully settled — credit note required
        if ($postingStatus === 'POSTED' && in_array($context['settlement_status'] ?? null, ['PARTIALLY_PAID', 'PAID'])) {
            return [
                'action' => 'CREDIT_NOTE_REQUIRED',
                'reason' => 'Posted and settled document requires credit/debit note.',
            ];
        }

        // Rule 4: Regulatory rejected — may need reissue
        if ($regulatoryStatus === 'REJECTED') {
            return [
                'action' => 'REGULATORY_CANCEL_AND_REISSUE',
                'reason' => 'Regulatory rejection requires cancellation and reissue.',
            ];
        }

        // Default
        return [
            'action' => 'NEW_DOCUMENT_NUMBER_REQUIRED',
            'reason' => 'Default correction instrument.',
        ];
    }

    /**
     * Check if a revision sequence is valid (atomic reservation).
     * ADR-005 §27.2 — unsafe MAX(sequence)+1 is prohibited.
     */
    public function reserveNextSequence(string $documentUuid): int
    {
        return DocumentRevision::nextSequence($documentUuid);
    }

    /**
     * Resolve the effective revision for a document.
     * ADR-005 §27.4 — effective revision must be policy-resolved, not simply highest sequence.
     */
    public function resolveEffectiveRevision(string $documentUuid): ?DocumentRevision
    {
        // First try: explicitly marked effective revision
        $effective = DocumentRevision::forDocument($documentUuid)
            ->effective()
            ->issued()
            ->first();

        if ($effective) {
            return $effective;
        }

        // Fallback: most recent ISSUED + CURRENT revision
        return DocumentRevision::forDocument($documentUuid)
            ->issued()
            ->current()
            ->orderByDesc('revision_sequence')
            ->first();
    }
}

<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * #670 (EINV-UX1) / #525 (CN-UX4): the e-invoice status of a sales document
 * as a list-page state — shared by every list that shows or filters it
 * (invoices, customer credit notes) so the status keys and their mapping onto
 * compliance document / submission statuses live in one place.
 *
 * UI keys: not_submitted, queued, submitted, valid, invalid, cancelled,
 * buyer_rejection_requested (see frontend einvoiceStatus.ts).
 */
final class EInvoiceListStatus
{
    /**
     * Translate the UI's e-invoice status keys into compliance document /
     * submission statuses and filter the document query.
     *
     *  - queued  → document pending
     *  - submitted → document submitted
     *  - valid → document accepted
     *  - invalid → document rejected or failed
     *  - cancelled → document cancelled
     *  - not_submitted → no compliance document at all
     *  - buyer_rejection_requested → an open buyer rejection request lives on
     *    the submission, because the request arrives after acceptance
     *
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @param  string  $statuses  comma-separated UI keys
     * @param  string  $documentType  acct_compliance_documents.document_type
     * @param  string  $idColumn  qualified id column of the listed document
     */
    public static function applyFilter(Builder $query, string $statuses, string $documentType, string $idColumn): void
    {
        $wanted = array_values(array_filter(array_map('trim', explode(',', $statuses))));

        if ($wanted === []) {
            return;
        }

        $tenantId = tenant()->id;

        $documentsWithStatus = function (array $documentStatuses) use ($tenantId, $documentType, $idColumn): \Closure {
            return function ($sub) use ($documentStatuses, $tenantId, $documentType, $idColumn) {
                $sub->selectRaw('1')
                    ->from('acct_compliance_documents as cd')
                    ->whereColumn('cd.document_id', $idColumn)
                    ->where('cd.document_type', $documentType)
                    ->where('cd.tenant_id', $tenantId)
                    ->whereIn('cd.compliance_status', $documentStatuses);
            };
        };

        $anyDocument = function ($sub) use ($tenantId, $documentType, $idColumn) {
            $sub->selectRaw('1')
                ->from('acct_compliance_documents as cd')
                ->whereColumn('cd.document_id', $idColumn)
                ->where('cd.document_type', $documentType)
                ->where('cd.tenant_id', $tenantId);
        };

        $openBuyerRejection = function ($sub) use ($tenantId, $documentType, $idColumn) {
            $sub->selectRaw('1')
                ->from('acct_compliance_submissions as cs')
                ->join('acct_compliance_documents as cd2', 'cd2.id', '=', 'cs.document_id')
                ->whereColumn('cd2.document_id', $idColumn)
                ->where('cd2.document_type', $documentType)
                ->where('cd2.tenant_id', $tenantId)
                ->whereIn('cs.submission_status', ['rejection_requested', 'rejection_declined_pending_expiry']);
        };

        $query->where(function ($q) use ($wanted, $documentsWithStatus, $anyDocument, $openBuyerRejection) {
            foreach ($wanted as $status) {
                match ($status) {
                    'queued' => $q->orWhereExists($documentsWithStatus(['pending'])),
                    'submitted' => $q->orWhereExists($documentsWithStatus(['submitted'])),
                    // Valid means accepted AND not contested by an open buyer
                    // rejection request — that request has its own status.
                    'valid' => $q->orWhere(function ($w) use ($documentsWithStatus, $openBuyerRejection) {
                        $w->whereExists($documentsWithStatus(['accepted']))
                            ->whereNotExists($openBuyerRejection);
                    }),
                    'invalid' => $q->orWhereExists($documentsWithStatus(['rejected', 'failed'])),
                    'cancelled' => $q->orWhereExists($documentsWithStatus(['cancelled'])),
                    'not_submitted' => $q->orWhereNotExists($anyDocument),
                    'buyer_rejection_requested' => $q->orWhereExists($openBuyerRejection),
                    default => null,
                };
            }
        });
    }

    /**
     * Effective e-invoice status per document id for one page of a list —
     * two queries regardless of page size. Documents with no compliance
     * document are absent from the map (callers decide whether that means
     * "not submitted" or "not applicable", e.g. for drafts).
     *
     * @param  array<int, int>  $documentIds
     * @return array<int, string> document id => UI status key
     */
    public static function statusMap(string $documentType, array $documentIds): array
    {
        $documentIds = array_values(array_unique(array_map('intval', $documentIds)));

        if ($documentIds === []) {
            return [];
        }

        // Latest compliance document per source document (a resubmission
        // can create a new one).
        $documents = DB::table('acct_compliance_documents')
            ->where('tenant_id', tenant()->id)
            ->where('document_type', $documentType)
            ->whereIn('document_id', $documentIds)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'document_id', 'compliance_status'])
            ->keyBy('document_id');

        if ($documents->isEmpty()) {
            return [];
        }

        $latestSubmission = DB::table('acct_compliance_submissions')
            ->whereIn('document_id', $documents->pluck('id')->all())
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get(['document_id', 'submission_status'])
            ->unique('document_id')
            ->keyBy('document_id');

        $map = [];
        foreach ($documents as $sourceId => $document) {
            $map[(int) $sourceId] = self::derive(
                $document->compliance_status,
                $latestSubmission->get($document->id)?->submission_status,
            );
        }

        return $map;
    }

    /**
     * Mirrors the frontend's deriveEInvoiceStatus: the latest submission wins
     * when present (an accepted document with an open buyer rejection request
     * is not "valid"), otherwise the document's own lifecycle.
     */
    public static function derive(?string $documentStatus, ?string $submissionStatus): string
    {
        $submission = strtolower((string) $submissionStatus);

        $fromSubmission = match ($submission) {
            'rejected', 'failed' => 'invalid',
            'cancelled' => 'cancelled',
            'rejection_requested', 'rejection_declined_pending_expiry' => 'buyer_rejection_requested',
            'accepted' => 'valid',
            'ack', 'acknowledged' => 'submitted',
            'pending', 'queued' => 'queued',
            default => null,
        };

        if ($fromSubmission !== null) {
            return $fromSubmission;
        }

        return match (strtolower((string) $documentStatus)) {
            'accepted' => 'valid',
            'submitted' => 'submitted',
            'rejected', 'failed' => 'invalid',
            'cancelled' => 'cancelled',
            'pending' => 'queued',
            default => 'not_submitted',
        };
    }
}

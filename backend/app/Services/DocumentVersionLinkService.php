<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\DocumentCorrectionLink;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Quotation;
use Modules\Sales\Models\DeliveryOrder;

class DocumentVersionLinkService
{
    public function linkReplacement($original, $replacement, CorrectionRequest $request): void
    {
        $documentType = $this->getDocumentType($original);
        $relatedDocumentType = $this->getDocumentType($replacement);
        $groupId = $original->correction_group_id ?? $replacement->correction_group_id;

        DocumentCorrectionLink::create([
            'document_type' => $documentType,
            'document_id' => $original->id,
            'related_document_type' => $relatedDocumentType,
            'related_document_id' => $replacement->id,
            'relation_type' => 'replacement_for',
            'created_by' => $request->userId,
            'reason_code' => $request->reasonCode,
            'reason_text' => $request->reasonText,
            'correction_group_id' => $groupId,
        ]);

        DocumentCorrectionLink::create([
            'document_type' => $relatedDocumentType,
            'document_id' => $replacement->id,
            'related_document_type' => $documentType,
            'related_document_id' => $original->id,
            'relation_type' => 'replaced_by',
            'created_by' => $request->userId,
            'reason_code' => $request->reasonCode,
            'reason_text' => $request->reasonText,
            'correction_group_id' => $groupId,
        ]);
    }

    public function linkReversal($original, $reversalEntry, CorrectionRequest $request): void
    {
        $documentType = $this->getDocumentType($original);

        DocumentCorrectionLink::create([
            'document_type' => $documentType,
            'document_id' => $original->id,
            'related_document_type' => 'journal_entry',
            'related_document_id' => $reversalEntry->id,
            'relation_type' => 'reversal_of',
            'created_by' => $request->userId,
            'reason_code' => $request->reasonCode,
            'reason_text' => $request->reasonText,
            'correction_group_id' => $original->correction_group_id,
        ]);
    }

    public function linkVoid($document, CorrectionRequest $request): void
    {
        $documentType = $this->getDocumentType($document);

        DocumentCorrectionLink::create([
            'document_type' => $documentType,
            'document_id' => $document->id,
            'related_document_type' => $documentType,
            'related_document_id' => $document->id,
            'relation_type' => 'void',
            'created_by' => $request->userId,
            'reason_code' => $request->reasonCode,
            'reason_text' => $request->reasonText,
            'correction_group_id' => $document->correction_group_id,
        ]);
    }

    public function linkCreditNote($original, $creditNote, CorrectionRequest $request): void
    {
        DocumentCorrectionLink::create([
            'document_type' => 'invoice',
            'document_id' => $original->id,
            'related_document_type' => 'invoice',
            'related_document_id' => $creditNote->id,
            'relation_type' => 'credit_note_for',
            'created_by' => $request->userId,
            'reason_code' => $request->reasonCode,
            'reason_text' => $request->reasonText,
            'correction_group_id' => $original->correction_group_id,
        ]);

        DocumentCorrectionLink::create([
            'document_type' => 'invoice',
            'document_id' => $creditNote->id,
            'related_document_type' => 'invoice',
            'related_document_id' => $original->id,
            'relation_type' => 'credited_by',
            'created_by' => $request->userId,
            'reason_code' => $request->reasonCode,
            'reason_text' => $request->reasonText,
            'correction_group_id' => $original->correction_group_id,
        ]);
    }

    public function linkRefund($document, $refundPayment, CorrectionRequest $request): void
    {
        $documentType = $this->getDocumentType($document);

        DocumentCorrectionLink::create([
            'document_type' => $documentType,
            'document_id' => $document->id,
            'related_document_type' => 'payment',
            'related_document_id' => $refundPayment->id,
            'relation_type' => 'refund_for',
            'created_by' => $request->userId,
            'reason_code' => $request->reasonCode,
            'reason_text' => $request->reasonText,
            'correction_group_id' => $document->correction_group_id,
        ]);
    }

    public function getCorrectionChain($document): \Illuminate\Database\Eloquent\Collection
    {
        $documentType = $this->getDocumentType($document);

        // No tenant_id filter needed — search_path already scopes to this tenant's schema.
        return DocumentCorrectionLink::where(function ($query) use ($documentType, $document) {
            $query->where(function ($q) use ($documentType, $document) {
                $q->where('document_type', $documentType)
                    ->where('document_id', $document->id);
            })->orWhere(function ($q) use ($documentType, $document) {
                $q->where('related_document_type', $documentType)
                    ->where('related_document_id', $document->id);
            });
        })
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function getActiveVersion($document)
    {
        $correctionGroupId = $document->correction_group_id ?? null;

        if (! $correctionGroupId) {
            return $document->is_active_version ? $document : null;
        }

        $documentType = $this->getDocumentType($document);

        return match ($documentType) {
            'invoice' => Invoice::where('correction_group_id', $correctionGroupId)->where('is_active_version', true)->first(),
            'bill' => Bill::where('correction_group_id', $correctionGroupId)->where('is_active_version', true)->first(),
            'journal_entry' => JournalEntry::where('correction_group_id', $correctionGroupId)->where('is_active_version', true)->first(),
            default => null,
        };
    }

    public function getOriginalDocument($document)
    {
        if (! $document->original_document_id) {
            return null;
        }

        $documentType = $this->getDocumentType($document);

        return match ($documentType) {
            'invoice' => Invoice::find($document->original_document_id),
            'bill' => Bill::find($document->original_document_id),
            'journal_entry' => JournalEntry::find($document->original_document_id),
            default => null,
        };
    }

    public function getVersionHistory($document): \Illuminate\Database\Eloquent\Collection
    {
        $documentType = $this->getDocumentType($document);

        return DocumentCorrectionLink::where(function ($query) use ($documentType, $document) {
            $query->where(function ($q) use ($documentType, $document) {
                $q->where('document_type', $documentType)
                    ->where('document_id', $document->id);
            })->orWhere(function ($q) use ($documentType, $document) {
                $q->where('related_document_type', $documentType)
                    ->where('related_document_id', $document->id);
            });
        })
            ->orderBy('created_at', 'asc')
            ->get();
    }

    private function getDocumentType($document): string
    {
        return match (true) {
            $document instanceof Invoice => 'invoice',
            $document instanceof Bill => 'bill',
            $document instanceof JournalEntry => 'journal_entry',
            $document instanceof Quotation => 'quotation',
            $document instanceof DeliveryOrder => 'delivery_order',
            default => throw new \InvalidArgumentException('Unknown document type'),
        };
    }
}

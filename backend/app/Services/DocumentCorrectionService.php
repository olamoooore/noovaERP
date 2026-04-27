<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Bill;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\CustomerCreditNote;
use App\Models\VendorCreditNote;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class DocumentCorrectionService
{
    public function __construct(
        private DocumentCorrectionPolicyService $policyService,
        private DocumentLifecycleService $lifecycleService,
        private DocumentVersionLinkService $linkService,
        private ReplacementDraftService $replacementService,
        private PaymentCorrectionService $paymentCorrectionService,
    ) {}

    public function voidDocument(Invoice|Bill|JournalEntry $document, CorrectionRequest $request): CorrectionResult
    {
        $policy = $this->policyService->canVoid($document, $request->userId ? \App\Models\User::find($request->userId) : null);

        if (!$policy->allowed) {
            throw new \RuntimeException($policy->message());
        }

        $this->lifecycleService->markVoided($document, $request);

        return new CorrectionResult(
            document: $document,
            correctionType: 'void',
        );
    }

    public function reverseDocument(Invoice|Bill|JournalEntry $document, CorrectionRequest $request): CorrectionResult
    {
        $policy = $this->policyService->canReverse($document, $request->userId ? \App\Models\User::find($request->userId) : null);

        if (!$policy->allowed) {
            throw new \RuntimeException($policy->message());
        }

        $reversalEntry = app(\App\Accounting\PostingEngine::class)->reverse(
            $document->journal_entry_id,
            $request->reasonText ?? 'Reversal: ' . ($document instanceof Invoice ? $document->invoice_number : ($document instanceof Bill ? $document->bill_number : $document->entry_number)),
            $request->userId
        );

        $this->lifecycleService->markReversed($document, $request, $reversalEntry->id);

        $this->linkService->linkReversal($document, $reversalEntry, $request);

        return new CorrectionResult(
            document: $document,
            correctionType: 'reverse',
            reversalJournalEntry: $reversalEntry,
        );
    }

    public function createCreditNote(Invoice|Bill $document, CorrectionRequest $request): CorrectionResult
    {
        $policy = $this->policyService->canCreateCreditNote($document, $request->userId ? \App\Models\User::find($request->userId) : null);

        if (!$policy->allowed) {
            throw new \RuntimeException($policy->message());
        }

        if ($document instanceof Invoice) {
            $creditNote = $this->createInvoiceCreditNote($document, $request);
        } else {
            $creditNote = $this->createBillCreditNote($document, $request);
        }

        $this->lifecycleService->markCredited($document, $request, $creditNote->id);

        return new CorrectionResult(
            document: $document,
            correctionType: 'credit_note',
            creditNote: $creditNote,
        );
    }

    public function refundDocument(Invoice $invoice, CorrectionRequest $request): CorrectionResult
    {
        $policy = $this->policyService->canRefund($invoice, $request->userId ? \App\Models\User::find($request->userId) : null);

        if (!$policy->allowed) {
            throw new \RuntimeException($policy->message());
        }

        $payments = $invoice->payments()->where('payment_status', 'applied')->get();

        if ($payments->isEmpty()) {
            throw new \RuntimeException('No applied payments to refund');
        }

        $refundPayment = null;
        foreach ($payments as $payment) {
            $result = $this->paymentCorrectionService->refundPayment($payment, $request);
            $refundPayment = $result->payment;
        }

        $invoice->update([
            'settlement_status' => 'refunded',
        ]);

        return new CorrectionResult(
            document: $invoice,
            correctionType: 'refund',
            refundPayment: $refundPayment,
        );
    }

    public function requestReplacementDraft(Invoice|Bill|JournalEntry $document, CorrectionRequest $request): CorrectionResult
    {
        $policy = $this->policyService->canCreateReplacement($document, $request->userId ? \App\Models\User::find($request->userId) : null);

        if (!$policy->allowed) {
            $data = $policy->data ?? [];
            throw new \RuntimeException($data['message'] ?? $policy->message());
        }

        $replacement = match (true) {
            $document instanceof Invoice => $this->replacementService->createInvoiceReplacementDraft($document, $request),
            $document instanceof Bill => $this->replacementService->createBillReplacementDraft($document, $request),
            $document instanceof JournalEntry => $this->replacementService->createJournalReplacementDraft($document, $request),
            default => throw new \InvalidArgumentException('Unknown document type'),
        };

        $document->update([
            'replacement_document_id' => $replacement->id,
        ]);

        return new CorrectionResult(
            document: $replacement,
            correctionType: 'replacement',
            originalDocument: $document,
        );
    }

    public function finalizeReplacement(Invoice|Bill|JournalEntry $replacement, CorrectionRequest $request): CorrectionResult
    {
        if ($replacement->lifecycle_status !== 'draft') {
            throw new \RuntimeException('Replacement must be in draft status to finalize');
        }

        $original = $this->linkService->getOriginalDocument($replacement);

        if ($original) {
            $this->lifecycleService->markSuperseded($original, $request);
            $this->lifecycleService->markOtherVersionsInactive($replacement);
        }

        $replacement->update([
            'lifecycle_status' => 'posted',
            'is_active_version' => true,
        ]);

        return new CorrectionResult(
            document: $replacement,
            correctionType: 'replacement_finalized',
            originalDocument: $original,
        );
    }

    private function createInvoiceCreditNote(Invoice $invoice, CorrectionRequest $request): CustomerCreditNote
    {
        $correctionGroupId = $invoice->correction_group_id ?? Str::uuid()->toString();

        $creditNote = CustomerCreditNote::create([
            'credit_note_number' => $this->generateCreditNoteNumber('CCN'),
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'credit_note_date' => now()->toDateString(),
            'reference' => $invoice->invoice_number,
            'reason_code' => $request->reasonCode,
            'reason_text' => $request->reasonText,
            'status' => 'draft',
            'subtotal' => $invoice->subtotal,
            'tax_amount' => $invoice->tax_amount,
            'total' => $invoice->total,
            'created_by' => $request->userId ?? Auth::id(),
        ]);

        foreach ($invoice->lines as $line) {
            \App\Models\CustomerCreditNoteLine::create([
                'credit_note_id' => $creditNote->id,
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'tax_rate' => $line->tax_rate,
                'tax_amount' => $line->tax_amount,
                'line_total' => $line->line_total,
            ]);
        }

        $this->linkService->linkCreditNote($invoice, $creditNote, $request);

        return $creditNote;
    }

    private function createBillCreditNote(Bill $bill, CorrectionRequest $request): VendorCreditNote
    {
        $correctionGroupId = $bill->correction_group_id ?? Str::uuid()->toString();

        $creditNote = VendorCreditNote::create([
            'credit_note_number' => $this->generateCreditNoteNumber('VCN'),
            'vendor_id' => $bill->vendor_id,
            'bill_id' => $bill->id,
            'credit_note_date' => now()->toDateString(),
            'reference' => $bill->bill_number,
            'reason_code' => $request->reasonCode,
            'reason_text' => $request->reasonText,
            'status' => 'draft',
            'subtotal' => $bill->subtotal,
            'tax_amount' => $bill->tax_amount,
            'total' => $bill->total,
            'created_by' => $request->userId ?? Auth::id(),
        ]);

        foreach ($bill->lines as $line) {
            \App\Models\VendorCreditNoteLine::create([
                'credit_note_id' => $creditNote->id,
                'product_id' => $line->product_id,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price,
                'tax_rate' => $line->tax_rate,
                'tax_amount' => $line->tax_amount,
                'line_total' => $line->line_total,
            ]);
        }

        $this->linkService->linkCreditNote($bill, $creditNote, $request);

        return $creditNote;
    }

    private function generateCreditNoteNumber(string $prefix): string
    {
        $latest = CustomerCreditNote::orderBy('id', 'desc')->first()
            ?? VendorCreditNote::orderBy('id', 'desc')->first();

        $number = $latest ? (int) substr($latest->credit_note_number, -4) + 1 : 1;
        return $prefix . '-' . date('Y') . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}

class CorrectionResult
{
    public function __construct(
        public readonly Invoice|Bill|JournalEntry $document,
        public readonly string $correctionType,
        public readonly ?JournalEntry $reversalJournalEntry = null,
        public readonly object|null $creditNote = null,
        public readonly ?Payment $refundPayment = null,
        public readonly object|null $originalDocument = null,
    ) {}
}
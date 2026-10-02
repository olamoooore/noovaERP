<?php

namespace App\Http\Controllers\Api;

use App\Accounting\Services\EInvoiceLifecycleGuard;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\CorrectionRequest;
use App\Services\DocumentCorrectionService;
use App\Services\DocumentVersionLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DocumentCorrectionsController extends Controller
{
    public function __construct(
        private DocumentCorrectionService $correctionService,
        private EInvoiceLifecycleGuard $eInvoiceLifecycle,
    ) {}

    public function voidInvoice(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        if ($denied = $this->eInvoiceLifecycle->denial('invoice', $invoice->id, 'void')) {
            return $denied;
        }

        try {
            $correctionRequest = CorrectionRequest::fromRequest($validated);
            $result = $this->correctionService->voidDocument($invoice, $correctionRequest);

            return response()->json([
                'message' => 'Invoice voided successfully',
                'invoice' => $result->document,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reverseInvoice(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        try {
            $correctionRequest = CorrectionRequest::fromRequest($validated);
            $result = $this->correctionService->reverseDocument($invoice, $correctionRequest);

            return response()->json([
                'message' => 'Invoice reversed successfully',
                'invoice' => $result->document,
                'reversal_journal_entry' => $result->reversalJournalEntry,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function createInvoiceCreditNote(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        try {
            $correctionRequest = CorrectionRequest::fromRequest($validated);
            $result = $this->correctionService->createCreditNote($invoice, $correctionRequest);

            return response()->json([
                'message' => 'Credit note created successfully',
                'credit_note' => $result->creditNote,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function refundInvoice(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        try {
            $correctionRequest = CorrectionRequest::fromRequest($validated);
            $result = $this->correctionService->refundDocument($invoice, $correctionRequest);

            return response()->json([
                'message' => 'Invoice refunded successfully',
                'refund_payment' => $result->refundPayment,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function createInvoiceReplacement(Request $request, int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);

        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        if ($denied = $this->eInvoiceLifecycle->denial('invoice', $invoice->id, 'replacement')) {
            return $denied;
        }

        try {
            $correctionRequest = CorrectionRequest::fromRequest($validated);
            $result = $this->correctionService->requestReplacementDraft($invoice, $correctionRequest);

            return response()->json([
                'message' => 'Replacement draft created successfully',
                'replacement' => $result->document,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function voidBill(Request $request, int $id): JsonResponse
    {
        $bill = Bill::findOrFail($id);

        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        try {
            $correctionRequest = CorrectionRequest::fromRequest($validated);
            $result = $this->correctionService->voidDocument($bill, $correctionRequest);

            return response()->json([
                'message' => 'Bill voided successfully',
                'bill' => $result->document,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reverseBill(Request $request, int $id): JsonResponse
    {
        $bill = Bill::findOrFail($id);

        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        try {
            $correctionRequest = CorrectionRequest::fromRequest($validated);
            $result = $this->correctionService->reverseDocument($bill, $correctionRequest);

            return response()->json([
                'message' => 'Bill reversed successfully',
                'bill' => $result->document,
                'reversal_journal_entry' => $result->reversalJournalEntry,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function createBillCreditNote(Request $request, int $id): JsonResponse
    {
        $bill = Bill::findOrFail($id);

        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        try {
            $correctionRequest = CorrectionRequest::fromRequest($validated);
            $result = $this->correctionService->createCreditNote($bill, $correctionRequest);

            return response()->json([
                'message' => 'Vendor credit note created successfully',
                'credit_note' => $result->creditNote,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function createBillReplacement(Request $request, int $id): JsonResponse
    {
        $bill = Bill::findOrFail($id);

        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        try {
            $correctionRequest = CorrectionRequest::fromRequest($validated);
            $result = $this->correctionService->requestReplacementDraft($bill, $correctionRequest);

            return response()->json([
                'message' => 'Replacement draft created successfully',
                'replacement' => $result->document,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function reverseJournal(Request $request, int $id): JsonResponse
    {
        $journalEntry = JournalEntry::findOrFail($id);

        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        try {
            $correctionRequest = CorrectionRequest::fromRequest($validated);
            $result = $this->correctionService->reverseDocument($journalEntry, $correctionRequest);

            return response()->json([
                'message' => 'Journal entry reversed successfully',
                'journal_entry' => $result->document,
                'reversal_journal_entry' => $result->reversalJournalEntry,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function createJournalReplacement(Request $request, int $id): JsonResponse
    {
        $journalEntry = JournalEntry::findOrFail($id);

        $validated = $request->validate([
            'reason_code' => 'required|string|max:50',
            'reason_text' => 'nullable|string|max:500',
        ]);

        try {
            $correctionRequest = CorrectionRequest::fromRequest($validated);
            $result = $this->correctionService->requestReplacementDraft($journalEntry, $correctionRequest);

            return response()->json([
                'message' => 'Replacement draft created successfully',
                'replacement' => $result->document,
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function getInvoiceCorrectionChain(int $id): JsonResponse
    {
        $invoice = Invoice::findOrFail($id);
        $linkService = app(DocumentVersionLinkService::class);
        $chain = $linkService->getCorrectionChain($invoice);

        return response()->json([
            'invoice' => $invoice,
            'correction_chain' => $chain,
        ]);
    }

    public function getBillCorrectionChain(int $id): JsonResponse
    {
        $bill = Bill::findOrFail($id);
        $linkService = app(DocumentVersionLinkService::class);
        $chain = $linkService->getCorrectionChain($bill);

        return response()->json([
            'bill' => $bill,
            'correction_chain' => $chain,
        ]);
    }

    public function getJournalCorrectionChain(int $id): JsonResponse
    {
        $journalEntry = JournalEntry::findOrFail($id);
        $linkService = app(DocumentVersionLinkService::class);
        $chain = $linkService->getCorrectionChain($journalEntry);

        return response()->json([
            'journal_entry' => $journalEntry,
            'correction_chain' => $chain,
        ]);
    }
}

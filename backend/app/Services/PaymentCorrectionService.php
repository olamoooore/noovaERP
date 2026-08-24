<?php

namespace App\Services;

use App\Accounting\DTOs\ReversalRequest;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use Illuminate\Support\Facades\Auth;

class PaymentCorrectionService
{
    public function __construct(
        private DocumentVersionLinkService $linkService,
        private DocumentLifecycleService $lifecycleService,
    ) {}

    public function reversePayment(Payment $payment, CorrectionRequest $request): PaymentCorrectionResult
    {
        $originalInvoice = $payment->invoice;
        $originalBill = $payment->bill ?? null;

        if (! $originalInvoice && ! $originalBill) {
            throw new \RuntimeException('Payment must be linked to an invoice or bill');
        }

        // ── GL reversal through PostingEngine (ADR-005 §15.3) ──────────
        $reversalEntry = null;
        if ($payment->journal_entry_id) {
            $reversalRequest = new ReversalRequest(
                journalEntryId: (int) $payment->journal_entry_id,
                reversalDate: now()->toDateString(),
                reason: $request->reasonText ?? 'Payment reversal',
                reasonCode: $request->reasonCode,
                actorUserId: (int) ($request->userId ?? Auth::id()),
            );
            $result = app(\App\Accounting\PostingEngine::class)->reverse($reversalRequest);
            $reversalEntry = $result->success ? $result->journalEntry : null;
        }

        $reversalPayment = Payment::create([
            'payment_number' => $this->generatePaymentNumber('reverse'),
            'customer_id' => $payment->customer_id,
            'vendor_id' => $payment->vendor_id ?? null,
            'invoice_id' => $payment->invoice_id ?? $payment->bill_id,
            'payment_date' => now()->toDateString(),
            'amount' => -$payment->amount,
            'method' => $payment->method,
            'reference' => 'Reversal: '.$payment->payment_number,
            'notes' => $request->reasonText,
            'received_by' => $request->userId ?? Auth::id(),
            'payment_status' => 'reversed',
            'original_payment_id' => $payment->id,
            'correction_group_id' => $payment->correction_group_id ?? $request->correctionGroupId,
            'correction_type' => 'reverse',
            'correction_reason_code' => $request->reasonCode,
            'correction_reason_text' => $request->reasonText,
            'reversal_journal_entry_id' => $reversalEntry?->id,
        ]);

        $payment->update([
            'payment_status' => 'reversed',
            'refund_payment_id' => $reversalPayment->id,
        ]);

        // ── Subledger update through canonical service ──────────────────
        if ($originalInvoice) {
            $this->lifecycleService->applyPaymentReversal($originalInvoice, $payment->amount);
        }

        if ($originalBill) {
            $this->lifecycleService->applyBillPaymentReversal($originalBill, $payment->amount);
        }

        $this->linkService->linkRefund(
            $originalInvoice ?? $originalBill,
            $reversalPayment,
            $request
        );

        return new PaymentCorrectionResult(
            payment: $reversalPayment,
            reversalJournalEntry: $reversalEntry,
            originalInvoice: $originalInvoice,
            originalBill: $originalBill,
        );
    }

    public function refundPayment(Payment $payment, CorrectionRequest $request): PaymentCorrectionResult
    {
        $originalInvoice = $payment->invoice;
        $originalBill = $payment->bill ?? null;

        if (! $originalInvoice && ! $originalBill) {
            throw new \RuntimeException('Payment must be linked to an invoice or bill');
        }

        // ── GL reversal through PostingEngine (ADR-005 §15.3) ──────────
        $reversalEntry = null;
        if ($payment->journal_entry_id) {
            $reversalRequest = new ReversalRequest(
                journalEntryId: (int) $payment->journal_entry_id,
                reversalDate: now()->toDateString(),
                reason: $request->reasonText ?? 'Payment refund',
                reasonCode: $request->reasonCode,
                actorUserId: (int) ($request->userId ?? Auth::id()),
            );
            $result = app(\App\Accounting\PostingEngine::class)->reverse($reversalRequest);
            $reversalEntry = $result->success ? $result->journalEntry : null;
        }

        $refundPayment = Payment::create([
            'payment_number' => $this->generatePaymentNumber('refund'),
            'customer_id' => $payment->customer_id,
            'vendor_id' => $payment->vendor_id ?? null,
            'invoice_id' => $payment->invoice_id ?? $payment->bill_id,
            'payment_date' => now()->toDateString(),
            'amount' => -$payment->amount,
            'method' => $payment->method,
            'reference' => 'Refund: '.$payment->payment_number,
            'notes' => $request->reasonText,
            'received_by' => $request->userId ?? Auth::id(),
            'payment_status' => 'refunded',
            'original_payment_id' => $payment->id,
            'correction_group_id' => $payment->correction_group_id ?? $request->correctionGroupId,
            'correction_type' => 'refund',
            'correction_reason_code' => $request->reasonCode,
            'correction_reason_text' => $request->reasonText,
            'refunded_at' => now(),
            'reversal_journal_entry_id' => $reversalEntry?->id,
        ]);

        $payment->update([
            'payment_status' => 'refunded',
            'refund_payment_id' => $refundPayment->id,
        ]);

        // ── Subledger update through canonical service ──────────────────
        if ($originalInvoice) {
            $this->lifecycleService->applyPaymentRefund($originalInvoice, $payment->amount);
        }

        if ($originalBill) {
            $this->lifecycleService->applyBillPaymentRefund($originalBill, $payment->amount);
        }

        $this->linkService->linkRefund(
            $originalInvoice ?? $originalBill,
            $refundPayment,
            $request
        );

        return new PaymentCorrectionResult(
            payment: $refundPayment,
            reversalJournalEntry: $reversalEntry,
            originalInvoice: $originalInvoice,
            originalBill: $originalBill,
        );
    }

    public function unapplyPayment(Payment $payment, CorrectionRequest $request): PaymentCorrectionResult
    {
        $originalInvoice = $payment->invoice;
        $originalBill = $payment->bill ?? null;

        if (! $originalInvoice && ! $originalBill) {
            throw new \RuntimeException('Payment must be linked to an invoice or bill');
        }

        // ── GL reversal through PostingEngine (ADR-005 §15.3) ──────────
        // Unapplying a payment requires reversing the GL entry to maintain
        // ledger-subledger consistency
        $reversalEntry = null;
        if ($payment->journal_entry_id) {
            $reversalRequest = new ReversalRequest(
                journalEntryId: (int) $payment->journal_entry_id,
                reversalDate: now()->toDateString(),
                reason: $request->reasonText ?? 'Payment unapplication',
                reasonCode: $request->reasonCode,
                actorUserId: (int) ($request->userId ?? Auth::id()),
            );
            $result = app(\App\Accounting\PostingEngine::class)->reverse($reversalRequest);
            $reversalEntry = $result->success ? $result->journalEntry : null;
        }

        $payment->update([
            'payment_status' => 'unapplied',
            'correction_type' => 'unapply_payment',
            'correction_reason_code' => $request->reasonCode,
            'correction_reason_text' => $request->reasonText,
            'unapplied_at' => now(),
            'reversal_journal_entry_id' => $reversalEntry?->id,
        ]);

        // ── Subledger update through canonical service ──────────────────
        if ($originalInvoice) {
            $this->lifecycleService->applyPaymentReversal($originalInvoice, $payment->amount);
        }

        if ($originalBill) {
            $this->lifecycleService->applyBillPaymentReversal($originalBill, $payment->amount);
        }

        return new PaymentCorrectionResult(
            payment: $payment,
            reversalJournalEntry: $reversalEntry,
            originalInvoice: $originalInvoice,
            originalBill: $originalBill,
        );
    }

    private function generatePaymentNumber(string $prefix): string
    {
        $latest = Payment::orderBy('id', 'desc')->first();
        $number = $latest ? (int) substr($latest->payment_number, -4) + 1 : 1;

        return $prefix.'-'.date('Y').'-'.str_pad($number, 4, '0', STR_PAD_LEFT);
    }
}

class PaymentCorrectionResult
{
    public function __construct(
        public readonly Payment $payment,
        public readonly ?JournalEntry $reversalJournalEntry,
        public readonly ?Invoice $originalInvoice,
        public readonly ?Bill $originalBill,
    ) {}
}

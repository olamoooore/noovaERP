<?php

namespace App\Services;

use App\Accounting\DTOs\ReversalRequest;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PaymentCorrectionService
{
    public function __construct(
        private DocumentVersionLinkService $linkService,
        private DocumentLifecycleService $lifecycleService,
    ) {}

    public function reversePayment(Payment $payment, CorrectionRequest $request): PaymentCorrectionResult
    {
        $this->assertCorrectable($payment, 'reversed');

        return DB::transaction(function () use ($payment, $request) {
            $originalInvoice = $payment->invoice;
            $originalBill = $payment->bill ?? null;

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
                if (! $result->success) {
                    throw new \RuntimeException('GL reversal failed: '.($result->error ?? 'Unknown error'));
                }
                $reversalEntry = $result->journalEntry;
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

            // #45: only link a refund correction when there is a parent document.
            // Standalone payments (no invoice, no bill) cannot have a refund link;
            // skip rather than crash with an InvalidArgumentException.
            if ($originalInvoice || $originalBill) {
                $this->linkService->linkRefund(
                    $originalInvoice ?? $originalBill,
                    $reversalPayment,
                    $request
                );
            }

            return new PaymentCorrectionResult(
                payment: $reversalPayment,
                reversalJournalEntry: $reversalEntry,
                originalInvoice: $originalInvoice,
                originalBill: $originalBill,
            );
        });
    }

    public function refundPayment(Payment $payment, CorrectionRequest $request): PaymentCorrectionResult
    {
        $this->assertCorrectable($payment, 'refunded');

        return DB::transaction(function () use ($payment, $request) {
            $originalInvoice = $payment->invoice;
            $originalBill = $payment->bill ?? null;

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
                if (! $result->success) {
                    throw new \RuntimeException('GL reversal failed: '.($result->error ?? 'Unknown error'));
                }
                $reversalEntry = $result->journalEntry;
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

            // #45: only link a refund correction when there is a parent document.
            if ($originalInvoice || $originalBill) {
                $this->linkService->linkRefund(
                    $originalInvoice ?? $originalBill,
                    $refundPayment,
                    $request
                );
            }

            return new PaymentCorrectionResult(
                payment: $refundPayment,
                reversalJournalEntry: $reversalEntry,
                originalInvoice: $originalInvoice,
                originalBill: $originalBill,
            );
        });
    }

    public function unapplyPayment(Payment $payment, CorrectionRequest $request): PaymentCorrectionResult
    {
        $this->assertCorrectable($payment, 'unapplied');

        return DB::transaction(function () use ($payment, $request) {
            $originalInvoice = $payment->invoice;
            $originalBill = $payment->bill ?? null;

            // ── GL reversal through PostingEngine (ADR-005 §15.3) ──────────
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
                if (! $result->success) {
                    throw new \RuntimeException('GL reversal failed: '.($result->error ?? 'Unknown error'));
                }
                $reversalEntry = $result->journalEntry;
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
        });
    }

    /**
     * Guard against repeated / invalid corrections.
     *
     * A payment may only be corrected while it is still an active receipt:
     * drafts are deleted rather than corrected, and payments that are already
     * reversed / refunded / cancelled / unapplied — or that already carry a
     * correction record (refund_payment_id) — must not be corrected again.
     * This is what prevents double refunds / double reversals.
     */
    private function assertCorrectable(Payment $payment, string $targetStatus): void
    {
        if ($payment->payment_status === 'draft') {
            throw new \RuntimeException(
                "Payment {$payment->payment_number} is a draft. Delete it instead of correcting it."
            );
        }

        if (in_array($payment->payment_status, ['reversed', 'refunded', 'cancelled'], true)) {
            throw new \RuntimeException(
                "Payment {$payment->payment_number} has already been {$payment->payment_status} and cannot be corrected again."
            );
        }

        if ($targetStatus === 'unapplied' && $payment->payment_status === 'unapplied') {
            throw new \RuntimeException(
                "Payment {$payment->payment_number} is already unapplied from its invoice."
            );
        }

        if ($payment->refund_payment_id) {
            throw new \RuntimeException(
                "Payment {$payment->payment_number} has already been corrected."
            );
        }
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

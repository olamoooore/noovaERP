<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\ReversalRequest;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * PaymentCorrectionService — canonical reverse/refund/unapply path (ACC-005A/005B).
 *
 * Shared correction contract with PaymentPostingService (issue #45):
 *   A payment is correctable iff it has been posted (has a journal_entry_id) and
 *   is not already corrected (assertCorrectable). Two payment shapes are supported:
 *     1. invoice- or bill-linked  → correction also drives the parent document's
 *        subledger (apply*Reversal / apply*Refund) and records a refund/correction
 *        link via DocumentVersionLinkService.
 *     2. standalone (no invoice/bill) → PaymentPostingService posts these as an
 *        AR customer credit; correction reverses that GL and updates the payment,
 *        but SKIPS subledger application and the refund link (there is no parent
 *        document). It must NOT be rejected — see the conditional guards below.
 *
 * This mirrors PaymentPostingService's own contract (see that class docblock). If
 * either service changes which shapes it supports, update BOTH the other service
 * and Issue45StandalonePaymentCorrectionTest + PaymentCorrectionWorkflowTest.
 */
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
                $result = app(PostingEngineInterface::class)->reverse($reversalRequest);
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
                // #478: mirror the discount leg too so the correction document
                // reverses the full settlement.
                'discount_amount' => -round((float) ($payment->discount_amount ?? 0), 2),
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
            // Hostile re-review C-3 (#155): when the payment was allocated
            // across invoices, undo each allocation (allocations are the
            // authoritative application ledger); otherwise use the legacy
            // single-invoice path.
            $hasAllocations = $payment->applications()->whereNull('reversed_at')->exists();

            if ($hasAllocations) {
                app(PaymentAllocationService::class)->reverseAllocations($payment);
            } else {
                if ($originalInvoice) {
                    $this->lifecycleService->applyPaymentReversal($originalInvoice, $this->settledAmount($payment));
                }

                if ($originalBill) {
                    $this->lifecycleService->applyBillPaymentReversal($originalBill, $payment->amount);
                }
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

        // #816 (EP5): captured before the refund work runs — the e-invoicing
        // listener needs the tenant id even if the posting path tears the
        // tenancy context down before the post-commit dispatch.
        $refundTenantId = tenancy()->tenant?->id;

        $result = DB::transaction(function () use ($payment, $request) {
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
                $result = app(PostingEngineInterface::class)->reverse($reversalRequest);
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
                // #478: mirror the discount leg too so the correction document
                // reverses the full settlement.
                'discount_amount' => -round((float) ($payment->discount_amount ?? 0), 2),
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
            // Hostile re-review C-3 (#155): split payments refund through their
            // allocation ledger.
            $hasAllocations = $payment->applications()->whereNull('reversed_at')->exists();

            if ($hasAllocations) {
                app(PaymentAllocationService::class)->reverseAllocations($payment);
            } else {
                if ($originalInvoice) {
                    $this->lifecycleService->applyPaymentRefund($originalInvoice, $this->settledAmount($payment));
                }

                if ($originalBill) {
                    $this->lifecycleService->applyBillPaymentRefund($originalBill, $payment->amount);
                }
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

        // #816 (EP5): money returned to a customer must reach the e-invoicing
        // workflow as a refund note (MyInvois type 04) referencing the original
        // invoice's UUID. Bill refunds carry no invoice — they are the
        // self-billed refund note (type 14), tracked with #814 / #816 AC3 — so
        // they are not dispatched here.
        if ($result->originalInvoice instanceof \App\Models\Invoice) {
            \App\Accounting\Events\RefundPosted::dispatch(
                $result->payment,
                $result->originalInvoice,
                $refundTenantId
            );
        }

        return $result;
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
                $result = app(PostingEngineInterface::class)->reverse($reversalRequest);
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
            // Hostile re-review C-3 (#155): when the payment was allocated
            // across invoices, undo each allocation (allocations are the
            // authoritative application ledger); otherwise use the legacy
            // single-invoice path.
            $hasAllocations = $payment->applications()->whereNull('reversed_at')->exists();

            if ($hasAllocations) {
                app(PaymentAllocationService::class)->reverseAllocations($payment);
            } else {
                if ($originalInvoice) {
                    $this->lifecycleService->applyPaymentReversal($originalInvoice, $this->settledAmount($payment));
                }

                if ($originalBill) {
                    $this->lifecycleService->applyBillPaymentReversal($originalBill, $payment->amount);
                }
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
    /**
     * #478: the value a payment settled on its invoice — cash received plus any
     * cash discount. Corrections must undo this full amount, not just cash.
     */
    private function settledAmount(Payment $payment): float
    {
        return round((float) $payment->amount + (float) ($payment->discount_amount ?? 0), 2);
    }

    private function assertCorrectable(Payment $payment, string $targetStatus): void
    {
        // Canonical statuses (#73) have no `draft` label: a payment is posted —
        // and therefore correctable — iff it carries a journal entry. Comparing
        // against the removed `draft` label let unposted payments be "corrected"
        // with no GL effect.
        if ($payment->journal_entry_id === null) {
            throw new \RuntimeException(
                "Payment {$payment->payment_number} has not been posted. Delete it instead of correcting it."
            );
        }

        if (in_array($payment->payment_status, ['reversed', 'refunded'], true)) {
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
        $year = date('Y');
        $pattern = "{$prefix}-{$year}-%";

        $latest = Payment::where('payment_number', 'like', $pattern)
            ->orderByRaw("CAST(SUBSTRING(payment_number FROM '[0-9]+$') AS INTEGER) DESC")
            ->first();

        if ($latest) {
            $number = (int) substr($latest->payment_number, -4) + 1;
        } else {
            $number = 1;
        }

        $candidate = $prefix.'-'.$year.'-'.str_pad($number, 4, '0', STR_PAD_LEFT);

        // Safety: ensure uniqueness
        while (Payment::where('payment_number', $candidate)->exists()) {
            $number++;
            $candidate = $prefix.'-'.$year.'-'.str_pad($number, 4, '0', STR_PAD_LEFT);
        }

        return $candidate;
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

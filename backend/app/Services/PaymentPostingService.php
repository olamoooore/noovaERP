<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Models\BankAccount;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

/**
 * Posts draft customer payments to the general ledger.
 *
 * Posting is the single transition that moves a payment from
 * draft (no ledger impact) to posted (locked, journal_entry_id set).
 * Invoice allocation happens at creation time — this service only
 * creates the journal entry and flips the status.
 *
 * Shared correction contract with PaymentCorrectionService (issue #45):
 *   This service posts BOTH invoice/bill-linked and STANDALONE payments
 *   (a standalone payment leaves an AR customer credit that nets against
 *   future invoices). Because a standalone payment is a supported,
 *   intentional posting shape, it MUST also be correctable
 *   (reverse/refund/unapply) via PaymentCorrectionService. Do not post a
 *   shape here that PaymentCorrectionService cannot later reverse, and do
 *   not reject a standalone payment in correction. See PaymentCorrectionService
 *   class docblock for the full contract and Issue45StandalonePaymentCorrectionTest.
 */
class PaymentPostingService
{
    public function __construct(
        private PostingEngineInterface $postingEngine,
    ) {}

    /**
     * Post a single payment.
     *
     * Idempotent:
     *  - already carries a journal entry → returned untouched (status repaired
     *    to the canonical posted status `applied` if needed)
     *
     * Canonical statuses (#73): a posted payment is `applied`; the legacy
     * `posted` label no longer exists in the enum.
     *
     * @throws \RuntimeException when the posting engine rejects the request
     */
    public function post(Payment $payment, ?int $userId = null): Payment
    {
        if ($payment->journal_entry_id !== null) {
            return $this->repairPostedStatus($payment);
        }

        $resolvedUserId = $userId ?? TenantUserHelper::resolveTenantUserId() ?? $payment->received_by;

        return DB::transaction(function () use ($payment, $resolvedUserId) {
            // Re-fetch inside the transaction to avoid double-posting races.
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->journal_entry_id !== null) {
                return $this->repairPostedStatus($payment);
            }

            $bankGlAccountId = null;
            if (! empty($payment->bank_account_id)) {
                $bankGlAccountId = BankAccount::find($payment->bank_account_id)?->gl_account_id;
            }

            // ── #44: policy-driven payment method → GL account mapping ──
            $method = $payment->method ?? 'cash';

            // 'other' is accepted by PaymentController::store() and produced by
            // PaymentImportService::resolveMethod() for unclassified import
            // values — it MUST be postable here too, otherwise imported
            // payments are permanently stuck in `recorded` with no GL entry
            // (production report 2026-09-24). Unclassified receipts post to
            // the default bank account, matching the platform's dominant
            // settlement path.
            $allowedMethods = ['bank_transfer', 'card', 'check', 'cash', 'gateway', 'other'];
            if (! in_array($method, $allowedMethods, true)) {
                throw new \RuntimeException(
                    "Unsupported payment method: '{$method}'. ".
                    'Allowed: '.implode(', ', $allowedMethods)
                );
            }

            $cashMappingKey = match ($method) {
                'bank_transfer' => 'bank',
                'card' => 'card_clearing',
                'check' => 'cheque_clearing',
                'cash' => 'cash',
                'gateway' => 'gateway_receivable',
                'other' => 'bank',
            };

            $lines = [
                // DR Cash/Bank
                new PostingRequestLine(
                    accountMappingKey: $cashMappingKey,
                    accountId: $bankGlAccountId,
                    debit: (float) $payment->amount,
                    credit: 0,
                    description: "Payment {$payment->payment_number}",
                ),
                // CR Accounts Receivable (for standalone payments this leaves
                // a customer credit that nets against future invoices)
                new PostingRequestLine(
                    accountMappingKey: 'accounts_receivable',
                    accountId: null,
                    debit: 0,
                    credit: (float) $payment->amount,
                    description: 'Payment received - '.$payment->payment_number,
                ),
            ];

            $result = $this->postingEngine->post(new PostingRequest(
                documentType: 'payment',
                documentId: $payment->id,
                postingDate: $payment->payment_date instanceof \DateTimeInterface
                    ? $payment->payment_date->format('Y-m-d')
                    : (string) $payment->payment_date,
                description: 'Payment #'.$payment->payment_number
                    .($payment->customer ? ' - '.$payment->customer->name : ''),
                lines: $lines,
                reference: $payment->payment_number,
                userId: TenantUserHelper::requireTenantUserId($resolvedUserId),
            ));

            if (! $result->success) {
                throw new \RuntimeException($result->error);
            }

            $payment->update([
                'journal_entry_id' => $result->journalEntryId,
                'payment_status' => 'applied',
            ]);

            Log::info('Payment posted to ledger', [
                'payment_id' => $payment->id,
                'payment_number' => $payment->payment_number,
                'journal_entry_id' => $result->journalEntryId,
            ]);

            return $payment->fresh(['customer', 'invoice', 'journalEntry']);
        });
    }

    /**
     * Post many payments. Returns per-id outcomes so the UI can show
     * exactly which rows were posted and which were skipped (and why).
     *
     * @param  int[]  $ids
     * @return array{posted: int[], skipped: array<int, array{id: int, reason: string}>}
     */
    public function postMany(array $ids): array
    {
        $posted = [];
        $skipped = [];

        foreach ($ids as $id) {
            $payment = Payment::find($id);
            if (! $payment) {
                $skipped[] = ['id' => $id, 'reason' => 'Payment not found'];

                continue;
            }

            try {
                $this->post($payment);
                $posted[] = $id;
            } catch (\Throwable $e) {
                Log::warning('Bulk payment posting failed', [
                    'payment_id' => $id,
                    'error' => $e->getMessage(),
                ]);
                $skipped[] = ['id' => $id, 'reason' => $e->getMessage()];
            }
        }

        return compact('posted', 'skipped');
    }

    /**
     * Canonical posted status is `applied` (#73). Legacy rows may still carry
     * `recorded` (or the removed `posted` label) alongside a journal entry —
     * normalise the label without touching the ledger link or re-posting.
     */
    private function repairPostedStatus(Payment $payment): Payment
    {
        if ($payment->payment_status !== 'applied') {
            $payment->update(['payment_status' => 'applied']);
        }

        return $payment->fresh(['customer', 'invoice', 'journalEntry']);
    }
}

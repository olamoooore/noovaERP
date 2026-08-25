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
     *  - already posted → returned untouched
     *  - has a journal entry but status still 'draft' (legacy UI-recorded
     *    payments) → status repaired to 'posted' without double-posting
     *
     * @throws \RuntimeException when the posting engine rejects the request
     */
    public function post(Payment $payment, ?int $userId = null): Payment
    {
        if ($payment->payment_status === 'posted') {
            return $payment;
        }

        // Legacy rows created through the record-payment flow already carry
        // a journal entry — repair the status instead of re-posting.
        if ($payment->journal_entry_id !== null) {
            $payment->update(['payment_status' => 'posted']);

            return $payment->fresh(['customer', 'invoice', 'journalEntry']);
        }

        $resolvedUserId = $userId ?? TenantUserHelper::resolveTenantUserId() ?? $payment->received_by;

        return DB::transaction(function () use ($payment, $resolvedUserId) {
            // Re-fetch inside the transaction to avoid double-posting races.
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->payment_status === 'posted') {
                return $payment;
            }
            if ($payment->journal_entry_id !== null) {
                $payment->update(['payment_status' => 'posted']);

                return $payment->fresh(['customer', 'invoice', 'journalEntry']);
            }

            $bankGlAccountId = null;
            if (! empty($payment->bank_account_id)) {
                $bankGlAccountId = BankAccount::find($payment->bank_account_id)?->gl_account_id;
            }

            // ── #44: policy-driven payment method → GL account mapping ──
            $method = $payment->method ?? 'cash';

            $allowedMethods = ['bank_transfer', 'card', 'check', 'cash', 'gateway'];
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
                'payment_status' => 'posted',
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
}

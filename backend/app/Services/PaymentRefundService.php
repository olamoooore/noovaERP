<?php

namespace App\Services;

use App\Accounting\Contracts\PostingEngineInterface;
use App\Accounting\DTOs\PostingRequest;
use App\Accounting\DTOs\PostingRequestLine;
use App\Accounting\DTOs\ReversalRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\RefundTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\PaymentGateway\Models\PaymentTransaction;

/**
 * PaymentRefundService
 *
 * Canonical service for processing payment refunds with proper GL posting.
 *
 * Workstream 1/4 (2026-04-15): Addresses critical audit finding that refunds
 * were reversing balances only without GL reversal entries.
 *
 * This service ensures:
 * - GL reversal entry is posted via PostingEngine
 * - Invoice balances are updated atomically
 * - Idempotency via PaymentTransaction.refunded_amount tracking
 * - Complete audit trail via PostingAuditLog
 */
class PaymentRefundService
{
    public function __construct(
        private readonly PostingEngineInterface $postingEngine,
        private readonly InvoiceService $invoiceService,
    ) {}

    /**
     * Process a refund for a completed payment transaction.
     *
     * @param  PaymentTransaction  $transaction  The completed transaction to refund
     * @param  float  $amount  The refund amount
     * @param  string  $reason  Human-readable refund reason
     * @param  int|null  $actorUserId  User performing the refund (null = current auth)
     * @return array{transaction: PaymentTransaction, refundEntry: \App\Models\JournalEntry|null, refundPayment: Payment|null}
     *
     * @throws \InvalidArgumentException If refund amount exceeds available balance
     * @throws \RuntimeException If refund processing fails
     */
    public function processRefund(
        PaymentTransaction $transaction,
        float $amount,
        string $reason,
        ?int $actorUserId = null,
    ): array {
        return DB::transaction(function () use ($transaction, $amount, $reason, $actorUserId) {
            // Lock the transaction for update to prevent race conditions
            $transaction = PaymentTransaction::whereKey($transaction->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Validate transaction is completed
            if ($transaction->status !== 'completed') {
                throw new \InvalidArgumentException(
                    "Only completed transactions can be refunded. Current status: {$transaction->status}"
                );
            }

            // Validate refund amount
            $availableForRefund = $transaction->amount - ($transaction->refunded_amount ?? 0);
            if ($amount > $availableForRefund) {
                throw new \InvalidArgumentException(
                    "Refund amount ({$amount}) exceeds available balance ({$availableForRefund})"
                );
            }

            $invoice = $transaction->invoice;
            $journalEntry = null;
            $refundPayment = null;

            // Step 1: Post GL reversal entry if this is an invoice payment with journal entry
            if ($invoice && $invoice->journal_entry_id) {
                // Find the original payment to determine which journal entry to reverse
                $originalPayment = Payment::where('reference', $transaction->transaction_id)->first();

                if ($originalPayment && $originalPayment->journal_entry_id) {
                    // Reverse the original payment journal entry
                    $reversalResult = $this->postingEngine->reverse(new ReversalRequest(
                        journalEntryId: $originalPayment->journal_entry_id,
                        reversalDate: now()->format('Y-m-d'),
                        reason: "Refund: {$reason}",
                        reasonCode: 'REFUND',
                        actorUserId: $actorUserId,
                    ));

                    if (! $reversalResult->success) {
                        throw new \RuntimeException(
                            "Failed to post GL reversal: {$reversalResult->error}"
                        );
                    }

                    $journalEntry = $reversalResult->journalEntry;
                } else {
                    // No original payment found - create a new GL entry for the refund
                    $journalEntry = $this->postRefundEntryDirectly($invoice, $amount, $reason, $actorUserId);
                }
            } elseif ($invoice) {
                // Invoice exists but no journal entry - create direct GL entry
                $journalEntry = $this->postRefundEntryDirectly($invoice, $amount, $reason, $actorUserId);
            }

            // Step 2: Update transaction refund tracking
            $transaction->increment('refunded_amount', $amount);

            $updateData = [
                'refund_reason' => $reason,
                'refunded_at' => now(),
            ];

            // If fully refunded, update status
            if ($transaction->refunded_amount >= $transaction->amount) {
                $updateData['status'] = 'refunded';
            }

            $transaction->update($updateData);

            // Step 3: Update invoice balances via canonical service
            if ($invoice) {
                $this->invoiceService->applyRefundToInvoice($invoice, $amount);

                // Create refund payment record for audit trail
                $refundPayment = Payment::create([
                    'customer_id' => $invoice->customer_id,
                    'invoice_id' => $invoice->id,
                    'payment_date' => now(),
                    'method' => 'refund',
                    'reference' => "REFUND-{$transaction->transaction_id}",
                    'amount' => -$amount, // Negative amount indicates refund
                    'notes' => "Refund via {$transaction->gateway->gateway_name}: {$reason}",
                    'received_by' => $actorUserId,
                    'journal_entry_id' => $journalEntry?->id,
                    'payment_status' => 'refunded',
                    'refunded_at' => now(),
                ]);
            }

            // Step 4: Update customer outstanding balance
            if ($invoice && $invoice->customer) {
                $invoice->customer->updateOutstanding();
            }

            Log::info('Payment refund processed with GL reversal', [
                'transaction_id' => $transaction->transaction_id,
                'refund_amount' => $amount,
                'journal_entry_id' => $journalEntry?->id,
                'refund_payment_id' => $refundPayment?->id,
            ]);

            return [
                'transaction' => $transaction->fresh(),
                'refundEntry' => $journalEntry,
                'refundPayment' => $refundPayment,
            ];
        });
    }

    /**
     * Post a direct GL entry when no original payment entry exists to reverse.
     *
     * This handles edge cases where the original payment wasn't posted to GL
     * (e.g., legacy data or channel-specific flows).
     */
    private function postRefundEntryDirectly(
        Invoice $invoice,
        float $amount,
        string $reason,
        ?int $actorUserId,
    ): \App\Models\JournalEntry {
        // Post a new GL entry: DR Revenue (reduction), CR Accounts Receivable (reduction)
        // This effectively reverses the revenue recognition
        $result = $this->postingEngine->post(new PostingRequest(
            documentType: 'payment_refund',
            documentId: $invoice->id,
            postingDate: now()->format('Y-m-d'),
            description: "Refund for Invoice #{$invoice->invoice_number}: {$reason}",
            lines: [
                // Debit Revenue (reversing the original credit)
                new PostingRequestLine(
                    accountMappingKey: 'revenue',
                    accountId: null,
                    debit: $amount,
                    credit: 0,
                    description: "Refund - Revenue reduction for INV-{$invoice->invoice_number}",
                ),
                // Credit Accounts Receivable (reducing the receivable)
                new PostingRequestLine(
                    accountMappingKey: 'accounts_receivable',
                    accountId: null,
                    debit: 0,
                    credit: $amount,
                    description: "Refund - AR reduction for INV-{$invoice->invoice_number}",
                ),
            ],
            reference: "REFUND-{$invoice->invoice_number}",
            userId: $actorUserId,
        ));

        if (! $result->success) {
            throw new \RuntimeException("Failed to post refund GL entry: {$result->error}");
        }

        return $result->journalEntry;
    }

    /**
     * Check if a refund can be processed for the given transaction.
     */
    public function canRefund(PaymentTransaction $transaction, float $amount): bool
    {
        if ($transaction->status !== 'completed') {
            return false;
        }

        $availableForRefund = $transaction->amount - ($transaction->refunded_amount ?? 0);

        return $amount <= $availableForRefund;
    }

    /**
     * Get the available refund amount for a transaction.
     */
    public function getAvailableRefundAmount(PaymentTransaction $transaction): float
    {
        if ($transaction->status !== 'completed') {
            return 0;
        }

        return $transaction->amount - ($transaction->refunded_amount ?? 0);
    }

    /**
     * Issue #110: durable write-ahead intent for a gateway refund.
     *
     * The gateway refund happens OUTSIDE any DB transaction (it is an external
     * call), so without a durable record a gateway success followed by a
     * ledger failure leaves the customer refunded with no GL trace and no
     * retry path. This record (committed BEFORE the gateway call) is that path.
     */
    public function beginGatewayRefund(
        PaymentTransaction $transaction,
        float $amount,
        string $reason,
        ?int $actorUserId = null,
    ): RefundTransaction {
        return DB::transaction(function () use ($transaction, $amount, $reason, $actorUserId) {
            $transaction = PaymentTransaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            $availableForRefund = (float) $transaction->amount - (float) ($transaction->refunded_amount ?? 0);
            if ($amount <= 0 || $amount > $availableForRefund) {
                throw new \InvalidArgumentException(
                    "Refund amount ({$amount}) exceeds available balance ({$availableForRefund})"
                );
            }

            $key = sprintf('gw-refund:%d:%.2f:%.2f', $transaction->id, $amount, (float) ($transaction->refunded_amount ?? 0));

            $existing = RefundTransaction::where('idempotency_key', $key)->first();
            if ($existing) {
                return $existing;
            }

            return RefundTransaction::create([
                'source_type' => PaymentTransaction::class,
                'source_id' => $transaction->id,
                'refund_type' => abs($amount - (float) $transaction->amount) < 0.01 ? 'full' : 'partial',
                'status' => 'pending',
                'refund_amount' => $amount,
                'original_amount' => (float) $transaction->amount,
                'reason' => $reason,
                'requested_by' => $actorUserId,
                'requested_at' => now(),
                'idempotency_key' => $key,
                'metadata' => [
                    'stage' => 'gateway_pending',
                    'gateway' => $transaction->gateway?->gateway_name,
                    // Target value for idempotent ledger repair.
                    'target_refunded_amount' => (float) ($transaction->refunded_amount ?? 0) + $amount,
                ],
            ]);
        });
    }

    /**
     * Mark that the gateway accepted the refund (durable marker). If the ledger
     * step then fails, this record is retryable without re-refunding.
     */
    public function markGatewayRefunded(RefundTransaction $refund, array $gatewayResult): void
    {
        $meta = $refund->metadata ?? [];
        $meta['stage'] = 'gateway_refunded_gl_pending';
        $meta['gateway_refund_id'] = $gatewayResult['refund_id'] ?? null;
        $meta['gateway_refunded_at'] = now()->toIso8601String();

        $refund->update(['metadata' => $meta]);
    }

    public function markGatewayFailed(RefundTransaction $refund, string $error): void
    {
        $meta = $refund->metadata ?? [];
        $meta['stage'] = 'gateway_failed';
        $meta['gateway_error'] = $error;

        $refund->update(['status' => 'rejected', 'metadata' => $meta]);
    }

    /**
     * Post the ledger side for a gateway-accepted refund and mark the record
     * completed.
     */
    public function completeGatewayRefundLedger(RefundTransaction $refund, ?int $actorUserId = null): array
    {
        $transaction = PaymentTransaction::findOrFail($refund->source_id);

        $result = $this->processRefund(
            $transaction,
            (float) $refund->refund_amount,
            (string) ($refund->reason ?? 'Refund'),
            $actorUserId ?? $refund->requested_by,
        );

        $meta = $refund->metadata ?? [];
        $meta['stage'] = 'completed';
        $meta['journal_entry_id'] = $result['refundEntry']?->id;

        $refund->update([
            'status' => 'completed',
            'completed_at' => now(),
            'metadata' => $meta,
        ]);

        return $result;
    }

    /**
     * Issue #110: retry ledger posting for refunds the gateway accepted but
     * whose GL posting failed. Idempotent: if the transaction already reflects
     * the target refunded amount, the record is simply marked completed.
     *
     * @return array{repaired: int[], failed: array<int, array{refund_id: int, error: string}>}
     */
    public function retryPendingRefundLedger(): array
    {
        $repaired = [];
        $failed = [];

        $pending = RefundTransaction::query()
            ->where('source_type', PaymentTransaction::class)
            ->where('status', 'pending')
            ->orderBy('id')
            ->get()
            ->filter(fn (RefundTransaction $refund) => ($refund->metadata['stage'] ?? null) === 'gateway_refunded_gl_pending');

        foreach ($pending as $refund) {
            $transaction = PaymentTransaction::find($refund->source_id);

            if (! $transaction) {
                $failed[] = ['refund_id' => $refund->id, 'error' => 'Payment transaction no longer exists'];

                continue;
            }

            $target = (float) ($refund->metadata['target_refunded_amount'] ?? 0);

            // The ledger already reflects the refund — do not post twice.
            if ($target > 0 && (float) $transaction->refunded_amount >= $target) {
                $refund->update([
                    'status' => 'completed',
                    'completed_at' => $refund->completed_at ?? now(),
                    'metadata' => array_merge($refund->metadata ?? [], ['stage' => 'completed', 'repaired_without_posting' => true]),
                ]);
                $repaired[] = $refund->id;

                continue;
            }

            try {
                $this->completeGatewayRefundLedger($refund, $refund->requested_by);
                $repaired[] = $refund->id;
            } catch (\Throwable $e) {
                Log::error('PaymentRefundService: ledger retry failed', [
                    'refund_id' => $refund->id,
                    'error' => $e->getMessage(),
                ]);
                $failed[] = ['refund_id' => $refund->id, 'error' => $e->getMessage()];
            }
        }

        return ['repaired' => $repaired, 'failed' => $failed];
    }
}

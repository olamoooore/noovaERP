<?php

declare(strict_types=1);

namespace Modules\Storefront\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\PaymentGateway\Models\PaymentGateway;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\PaymentGateway\Services\PaymentTransactionCompletionService;
use Modules\Storefront\Enums\PaymentStatus;
use Modules\Storefront\Exceptions\CheckoutException;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontPaymentProof;
use Modules\Storefront\Models\StorefrontSettings;

/**
 * P3 offline settlement (PAY-R13, Appendix C §C4/C7 flow 5):
 *   method offered at checkout (server-decided) → order pending +
 *   instructions → customer uploads proof → merchant approves → THE SAME
 *   canonical completion chain a gateway webhook drives (machine-only state,
 *   convert → invoice → combined GL → channel payment → paid mail) via an
 *   internal `offline` transaction record. Reject keeps the order pending
 *   and allows re-submission; the paid claim is never taken from the browser.
 *
 * The proof file is PRIVATE storage (payment evidence contains bank
 * details); only permission-gated admin download can reach it.
 */
final class StorefrontOfflinePaymentService
{
    /** Appendices: "type/size limits" — receipt images and PDFs only. */
    private const ALLOWED_MIMES = ['jpg', 'jpeg', 'png', 'pdf'];

    private const MAX_KILOBYTES = 5120;

    /** The only offline method keys the platform knows (Appendix C §C4). */
    public const METHOD_LABELS = [
        'bank_transfer' => 'Bank transfer',
        'pay_at_collection' => 'Pay at collection',
    ];

    /**
     * Methods offered to the storefront runtime: only tenant-ENABLED offline
     * configurations appear (FR-PAY-01/PAY-R3 — availability is server-decided).
     *
     * @return array<int,array<string,mixed>>
     */
    public function offeredOfflineMethods(): array
    {
        $configured = StorefrontSettings::current()->payment_settings['offline'] ?? [];

        $out = [];
        foreach ($configured as $key => $def) {
            if (! is_array($def) || ! ($def['enabled'] ?? false)) {
                continue;
            }
            if (! array_key_exists((string) $key, self::METHOD_LABELS)) {
                continue;
            }
            $out[] = [
                'id' => null,
                'type' => 'offline',
                'name' => (string) $key,
                'key' => (string) $key,
                'display_name' => (string) ($def['label'] ?? self::METHOD_LABELS[(string) $key]),
                'instructions' => $def['instructions'] ?? null,
                'is_test_mode' => false,
            ];
        }

        return $out;
    }

    public function enabledMethodKeys(): array
    {
        return array_column($this->offeredOfflineMethods(), 'key');
    }

    /**
     * Customer proof submission. The order must still be awaiting payment;
     * re-submission after a rejection is expected (the newest submitted proof
     * is what the merchant reviews).
     */
    public function submitProof(
        StorefrontOrder $order,
        string $method,
        ?string $reference,
        UploadedFile $file,
    ): StorefrontPaymentProof {
        if (! in_array($method, $this->enabledMethodKeys(), true)) {
            throw new CheckoutException('method_unavailable', 'That payment method is not available for this store.', 422);
        }

        if ((string) $order->payment_status !== PaymentStatus::PENDING_PAYMENT->value) {
            throw new CheckoutException('not_payable', 'This order is not awaiting payment.', 422);
        }

        $validator = \Illuminate\Support\Facades\Validator::make(['file' => $file], [
            'file' => ['required', 'file', 'mimes:'.implode(',', self::ALLOWED_MIMES), 'max:'.self::MAX_KILOBYTES],
        ]);

        if ($validator->fails()) {
            throw new CheckoutException('proof_invalid', 'Proof must be a JPG/PNG/PDF up to 5 MB.', 422);
        }

        $stored = Storage::disk('local')->putFileAs(
            "storefront/proofs/{$order->id}",
            $file,
            \Illuminate\Support\Str::random(24).'.'.strtolower($file->getClientOriginalExtension() ?: 'bin'),
        );

        if ($stored === false) {
            throw new CheckoutException('proof_store_failed', 'The proof could not be stored. Try again.', 500);
        }

        return DB::transaction(function () use ($order, $method, $reference, $file, $stored) {
            $proof = StorefrontPaymentProof::create([
                'order_id' => (int) $order->id,
                'method' => $method,
                'reference' => $reference,
                'file_path' => $stored,
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => (string) ($file->getClientMimeType() ?: 'application/octet-stream'),
                'size_bytes' => (int) $file->getSize(),
                'status' => StorefrontPaymentProof::STATUS_SUBMITTED,
            ]);

            app(OrderService::class)->recordPaymentAttempt(
                $order,
                'offline:'.$method,
                'proof:'.$proof->id,
                'proof_submitted',
            );

            return $proof;
        });
    }

    /**
     * Merchant decision. Approve drives the canonical completion; the
     * internal transaction row is created FIRST (same anchor shape the
     * launch gateways use) and its id makes approval replay-safe on every
     * downstream leg (reference-guarded settlement, engine journal links).
     */
    public function review(
        StorefrontOrder $order,
        int $proofId,
        string $decision,
        ?string $note,
        ?string $reviewerRef = null,
    ): array {
        if (! in_array($decision, ['approve', 'reject'], true)) {
            throw new CheckoutException('decision_invalid', 'Decision must be approve or reject.', 422);
        }

        $proof = StorefrontPaymentProof::query()
            ->where('order_id', (int) $order->id)
            ->whereKey($proofId)
            ->first();

        if ($proof === null) {
            throw new CheckoutException('proof_not_found', 'No such proof for this order.', 404);
        }

        if ($proof->status !== StorefrontPaymentProof::STATUS_SUBMITTED) {
            throw new CheckoutException('proof_reviewed', 'This proof was already '.$proof->status.'.', 422);
        }

        if ($decision === 'reject') {
            $proof->update([
                'status' => StorefrontPaymentProof::STATUS_REJECTED,
                'review_note' => $note,
                'reviewed_by' => $reviewerRef,
                'reviewed_at' => now(),
            ]);

            app(OrderService::class)->recordPaymentAttempt(
                $order,
                'offline:'.$proof->method,
                'proof:'.$proof->id,
                'rejected',
                $note,
            );

            return ['proof' => $proof->fresh(), 'order' => $order->fresh(), 'completed' => false];
        }

        if ((string) $order->payment_status !== PaymentStatus::PENDING_PAYMENT->value) {
            throw new CheckoutException('not_payable', 'This order is no longer awaiting payment.', 422);
        }

        $methodKey = (string) $proof->method;
        // Settlement account semantics: bank transfer lands as bank_transfer;
        // pay-at-collection lands as cash. Both are inside the platform's
        // payments.method CHECK set — no constraint migration needed (P-04f).
        $invoiceMethod = $methodKey === 'pay_at_collection' ? 'cash' : 'bank_transfer';

        $offlineGateway = PaymentGateway::firstOrCreate(
            ['gateway_name' => 'offline'],
            ['is_active' => false, 'is_test_mode' => false],
        );

        $transaction = PaymentTransaction::create([
            'payment_gateway_id' => (int) $offlineGateway->id,
            'transaction_id' => 'offline:'.$order->id.':proof:'.$proof->id,
            'amount' => (float) $order->total,
            'currency' => (string) ($order->currency ?? 'SGD'),
            'status' => 'completed',
            'payment_method' => $invoiceMethod,
            'metadata' => [
                'type' => 'storefront_order',
                'order_id' => (int) $order->id,
                'order_number' => $order->order_number,
                'settlement' => 'offline:'.$methodKey,
                'proof_id' => (int) $proof->id,
            ],
        ]);

        // The canonical chain (machine-only state, convert → invoice → GL →
        // channel payment → paid notification, fail-closed replay guards).
        app(PaymentTransactionCompletionService::class)->complete($transaction, $invoiceMethod);

        $proof->update([
            'status' => StorefrontPaymentProof::STATUS_APPROVED,
            'review_note' => $note,
            'reviewed_by' => $reviewerRef,
            'reviewed_at' => now(),
        ]);

        app(OrderService::class)->recordPaymentAttempt(
            $order->fresh(),
            'offline:'.$methodKey,
            'proof:'.$proof->id,
            'approved',
        );

        return ['proof' => $proof->fresh(), 'order' => $order->fresh(), 'completed' => true];
    }
}

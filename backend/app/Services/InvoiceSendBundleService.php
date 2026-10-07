<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceActivityLog;
use App\Models\InvoiceDelivery;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * D8 one-click Send bundle: acceptance link + pay-now link + QR in ONE email.
 *
 * Issue #329: extracted from InvoiceController::sendBundle() unchanged so the
 * single Send button and the queued Bulk Send (SendInvoiceBundleJob) deliver
 * through exactly the same path.
 */
class InvoiceSendBundleService
{
    /** Invoice statuses that are never sent to the customer. */
    public const UNSENDABLE_STATUSES = ['cancelled', 'voided', 'reversed'];

    public const UNSENDABLE_STATUS_MESSAGE = 'This invoice cannot be sent to the customer.';

    /** Issue #345: bundle parts that can be left out of the email. */
    public const PART_ACCEPTANCE_LINK = 'acceptance_link';

    public const PART_PAYMENT_LINK = 'payment_link';

    /** Issue #345: why a part was left out => readable message. */
    public const SKIP_MESSAGES = [
        'acceptance_disabled' => 'Acceptance link not included: customer acceptance is turned off in Sales settings.',
        'draft' => 'Pay-now link not included: the invoice is still a draft.',
        'zero_balance' => 'Pay-now link not included: the invoice has no balance due.',
        'payment_link_failed' => 'Pay-now link not included: the payment link could not be created.',
    ];

    public function __construct(
        private InvoiceService $invoiceService,
        private InvoiceActivityService $activityService,
        private InvoiceDeliveryService $deliveryService,
        private InvoiceEvidenceService $evidenceService,
    ) {}

    /**
     * The reason this invoice cannot be sent as a bundle, or null when it can.
     */
    public function unsendableReason(Invoice $invoice, ?string $recipient = null): ?string
    {
        if (in_array($invoice->status, self::UNSENDABLE_STATUSES, true)) {
            return self::UNSENDABLE_STATUS_MESSAGE;
        }

        return $this->deliveryService->deliverabilityError($invoice, $recipient);
    }

    /**
     * @param  array{expires_in_days?: ?int, payment_expires_in_days?: ?int, recipient?: ?string}  $options
     * @return array{link_data: array<string, mixed>, payment_url: ?string, payment: ?array, delivery: InvoiceDelivery, skipped: list<array{part: string, reason: string, message: string}>}
     */
    public function send(Invoice $invoice, User $user, array $options = []): array
    {
        $salesSettings = app(SalesSettings::class);

        // 1) Acceptance portal link (review / e-sign) — only while the
        // tenant keeps customer acceptance on (#539). The link lifetime
        // defaults to sales.portal.acceptance_link_expiry_days.
        $linkData = [];
        // Issue #345: every part left out of the email, with the reason.
        $skipped = [];

        if (! $salesSettings->acceptanceEnabled()) {
            $skipped[] = $this->skip(self::PART_ACCEPTANCE_LINK, 'acceptance_disabled');
        } else {
            $linkData = $this->evidenceService->createPortalAcceptanceLink(
                $invoice,
                $options['expires_in_days'] ?? null
            );

            $this->activityService->log(
                $invoice,
                InvoiceActivityLog::EVENT_ACCEPTANCE_LINK_CREATED,
                [
                    'acceptance_url' => $linkData['url'],
                    'expires_at' => $linkData['expires_at'],
                    'evidence_id' => $linkData['evidence_id'],
                    'send_email' => true,
                    'bundle' => true,
                ],
                $invoice->getOriginal('evidence_status') ?? $invoice->evidence_status,
                $invoice->fresh()->evidence_status,
                InvoiceActivityLog::ACTOR_USER,
                $user->id,
            );
        }

        // 2) Pay-now link — only for posted invoices with a live balance.
        // Drafts are sent for acceptance first; a draft has no payable
        // document yet, so the bundle degrades gracefully.
        $paymentUrl = null;
        $payment = null;

        if ($invoice->status === 'draft') {
            $skipped[] = $this->skip(self::PART_PAYMENT_LINK, 'draft');
        } elseif (bccomp((string) $invoice->balance, '0.00', 2) <= 0) {
            $skipped[] = $this->skip(self::PART_PAYMENT_LINK, 'zero_balance');
        } else {
            try {
                $payment = $this->invoiceService->createPaymentLink(
                    $invoice,
                    (float) $invoice->balance,
                    now()->addDays($options['payment_expires_in_days'] ?? $salesSettings->paymentLinkExpiryDays()),
                );
                $paymentUrl = $payment['url'] ?? null;

                $this->activityService->log(
                    $invoice,
                    InvoiceActivityLog::EVENT_PAYMENT_LINK_ISSUED,
                    [
                        'payment_link_url' => $paymentUrl,
                        'amount' => (float) $invoice->balance,
                        'expires_at' => $invoice->payment_link_expires_at?->toIso8601String(),
                    ],
                    null,
                    null,
                    InvoiceActivityLog::ACTOR_USER,
                    $user->id,
                );
            } catch (\Throwable $e) {
                Log::warning('Send bundle: payment link creation failed, sending without it', [
                    'invoice_id' => $invoice->id,
                    'error' => $e->getMessage(),
                ]);
                $skipped[] = $this->skip(self::PART_PAYMENT_LINK, 'payment_link_failed');
            }
        }

        // 3) ONE email: PDF + acceptance link + pay-now link + QR.
        $delivery = $this->deliveryService->deliver($invoice, 'email', $user, [
            // #539: the tenant's "document type on send" choice; the final
            // invoice stays the bundle default when none was configured.
            'document_type' => $salesSettings->documentTypeOnSend() ?? InvoiceDelivery::DOCUMENT_FINAL_INVOICE,
            'portal_url' => $linkData['url'] ?? null,
            'payment_url' => $paymentUrl,
            'recipient' => $options['recipient'] ?? null,
        ]);

        return [
            'link_data' => $linkData,
            'payment_url' => $paymentUrl,
            'payment' => $payment,
            'delivery' => $delivery,
            'skipped' => $skipped,
        ];
    }

    /** @return array{part: string, reason: string, message: string} */
    private function skip(string $part, string $reason): array
    {
        return ['part' => $part, 'reason' => $reason, 'message' => self::SKIP_MESSAGES[$reason]];
    }
}

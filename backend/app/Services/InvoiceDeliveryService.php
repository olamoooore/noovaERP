<?php

namespace App\Services;

use App\Mail\CustomerEmail;
use App\Models\Invoice;
use App\Models\InvoiceActivityLog;
use App\Models\InvoiceDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Communication\Events\BusinessEvent;

class InvoiceDeliveryService
{
    public const NOT_DELIVERABLE_MESSAGE = 'Invoice is not ready to be delivered. It must be approved or already posted.';

    public const NO_RECIPIENT_MESSAGE = 'No recipient email available for invoice delivery.';

    public function __construct(
        private InvoiceActivityService $activityLog,
    ) {}

    /**
     * Issue #329: the reason deliver() would refuse this invoice, or null when
     * it can be delivered. Bulk Send checks this up front so every selected
     * invoice gets its own result before anything is queued.
     */
    public function deliverabilityError(Invoice $invoice, ?string $recipient = null): ?string
    {
        if (! $this->isDeliverable($invoice)) {
            return self::NOT_DELIVERABLE_MESSAGE;
        }

        if (! $this->resolveRecipient($invoice, $recipient)) {
            return self::NO_RECIPIENT_MESSAGE;
        }

        return null;
    }

    /**
     * An invoice is deliverable once it is posted, or while still in draft
     * after approval (the "send to customer for acceptance" step). A draft
     * that is pending approval, rejected, or plain must not be emailed yet.
     */
    private function isDeliverable(Invoice $invoice): bool
    {
        return $invoice->status !== 'draft'
            || $invoice->approval_status === Invoice::APPROVAL_APPROVED;
    }

    private function resolveRecipient(Invoice $invoice, ?string $override): ?string
    {
        return $override ?? $invoice->contact_email ?? $invoice->customer?->email;
    }

    /**
     * Issue #328: $user is null for a send made by the scheduler (automatic
     * follow-up reminders); the delivery then has no sender and the activity
     * is logged as a system action. $options['message_subject'] /
     * $options['message_body'] carry an already-rendered reminder text and
     * $options['metadata'] extra history fields (follow-up level, mode).
     */
    public function deliver(Invoice $invoice, string $channel, ?User $user, array $options = []): InvoiceDelivery
    {
        if (! $this->isDeliverable($invoice)) {
            throw new \InvalidArgumentException(self::NOT_DELIVERABLE_MESSAGE);
        }

        if (! in_array($channel, InvoiceDelivery::CHANNELS, true)) {
            throw new \InvalidArgumentException("Invalid delivery channel: {$channel}. Allowed: ".implode(', ', InvoiceDelivery::CHANNELS));
        }

        // Issue #617: when the caller does not pick a document type, the #539
        // "document type on send" setting decides. Without a configured
        // setting the existing status rule applies: a draft being sent is an
        // acceptance copy, a posted invoice is the final invoice.
        $documentType = $options['document_type']
            ?? app(SalesSettings::class)->documentTypeOnSend()
            ?? ($invoice->status === 'posted'
                ? InvoiceDelivery::DOCUMENT_FINAL_INVOICE
                : InvoiceDelivery::DOCUMENT_ACCEPTANCE_COPY);
        $recipient = $this->resolveRecipient($invoice, $options['recipient'] ?? null);

        if (! $recipient) {
            throw new \InvalidArgumentException(self::NO_RECIPIENT_MESSAGE);
        }

        $delivery = DB::transaction(function () use ($invoice, $channel, $user, $documentType, $recipient, $options) {
            $metadata = [
                'attempt' => 1,
                'invoice_number' => $invoice->invoice_number,
            ];

            if (! empty($options['portal_url'])) {
                $metadata['portal_url'] = $options['portal_url'];
            }

            // D8 (Oct-2026 invoice review): the same email can carry a
            // pay-now link (and its QR) alongside the acceptance link.
            if (! empty($options['payment_url'])) {
                $metadata['payment_url'] = $options['payment_url'];
            }

            // Issue #328: a follow-up reminder keeps the text it was sent
            // with and its level on the delivery row (the reminder history).
            foreach (['message_subject', 'message_body'] as $key) {
                if (! empty($options[$key])) {
                    $metadata[$key] = $options[$key];
                }
            }
            $metadata = array_merge($metadata, $options['metadata'] ?? []);

            $delivery = InvoiceDelivery::create([
                'invoice_id' => $invoice->id,
                'channel' => $channel,
                'document_type' => $documentType,
                'recipient' => $recipient,
                'status' => InvoiceDelivery::STATUS_QUEUED,
                'sent_by' => $user?->id,
                'metadata' => $metadata,
            ]);

            return $delivery;
        });

        $this->sendViaChannel($delivery, $invoice);

        $this->updateDeliveryStatus($invoice);

        $this->activityLog->log(
            $invoice,
            InvoiceActivityLog::EVENT_SENT,
            [
                'invoice_number' => $invoice->invoice_number,
                'delivery_id' => $delivery->id,
                'channel' => $channel,
                'recipient' => $recipient,
                'document_type' => $documentType,
                'delivered_by' => $user?->id,
            ] + array_intersect_key($options['metadata'] ?? [], array_flip(['followup_level', 'followup_mode', 'days_overdue'])),
            $invoice->getOriginal('delivery_status') ?? Invoice::DELIVERY_NOT_SENT,
            $invoice->fresh()->delivery_status,
            $user ? InvoiceActivityLog::ACTOR_USER : InvoiceActivityLog::ACTOR_SYSTEM,
            $user?->id,
        );

        Log::info('Invoice delivered', [
            'invoice_id' => $invoice->id,
            'delivery_id' => $delivery->id,
            'channel' => $channel,
            'recipient' => $recipient,
        ]);

        // Issue #84: route this domain event through the platform's
        // Communication module so subscribers, outbox, and delivery channels
        // (in-app, email, etc.) pick it up alongside the existing direct
        // Mail::send path.
        Event::dispatch(new BusinessEvent(
            model: 'invoice',
            recordId: $invoice->id,
            event: 'invoice.delivered',
            subject: "Invoice {$invoice->invoice_number} delivered",
            body: "Invoice {$invoice->invoice_number} was delivered to {$recipient} via {$channel}.",
            authorId: $user?->id,
            authorType: $user ? 'user' : 'system',
            tenantId: $invoice->tenant_id ?? (function () {
                try {
                    return tenant()?->id;
                } catch (\Throwable) {
                    return null;
                }
            })(),
            context: [
                'invoice_number' => $invoice->invoice_number,
                'channel' => $channel,
                'recipient' => $recipient,
                'document_type' => $documentType,
            ],
            metadata: [
                'delivery_id' => $delivery->id,
                'source' => 'InvoiceDeliveryService::deliver',
            ],
        ));

        return $delivery;
    }

    public function resend(Invoice $invoice, string $channel, User $user, array $options = []): InvoiceDelivery
    {
        $existingDelivery = InvoiceDelivery::where('invoice_id', $invoice->id)
            ->where('channel', $channel)
            ->whereIn('document_type', [InvoiceDelivery::DOCUMENT_ACCEPTANCE_COPY, InvoiceDelivery::DOCUMENT_FINAL_INVOICE])
            ->latest()
            ->first();

        if ($existingDelivery && $existingDelivery->status === InvoiceDelivery::STATUS_QUEUED) {
            throw new \InvalidArgumentException("A delivery is already in progress for channel {$channel}. Please wait or cancel it first.");
        }

        $options['resend'] = true;

        return $this->deliver($invoice, $channel, $user, $options);
    }

    private function sendViaChannel(InvoiceDelivery $delivery, Invoice $invoice): void
    {
        try {
            match ($delivery->channel) {
                InvoiceDelivery::CHANNEL_EMAIL => $this->sendEmail($delivery, $invoice),
                InvoiceDelivery::CHANNEL_WHATSAPP => $this->sendWhatsApp($delivery, $invoice),
                InvoiceDelivery::CHANNEL_PORTAL => $this->markPortalSent($delivery, $invoice),
            };
        } catch (\Throwable $e) {
            $this->markFailed($delivery, $e->getMessage());
            throw $e;
        }
    }

    private function sendEmail(InvoiceDelivery $delivery, Invoice $invoice): void
    {
        try {
            $salesSettings = app(SalesSettings::class);
            $paymentUrl = $delivery->metadata['payment_url'] ?? null;

            // #539: sales.delivery.attach_pdf decides whether the document PDF
            // travels with the email; sales.delivery.cc_internal_email copies
            // the tenant's internal mailbox.
            $attachments = $salesSettings->attachPdf()
                ? [[
                    'name' => "Invoice-{$invoice->invoice_number}.pdf",
                    // Issue #328: a reminder carries the invoice itself.
                    'data' => $this->generatePdfData($invoice, $delivery->document_type === InvoiceDelivery::DOCUMENT_REMINDER
                        ? InvoiceDelivery::DOCUMENT_FINAL_INVOICE
                        : $delivery->document_type),
                    'mime' => 'application/pdf',
                ]]
                : [];

            $mailer = Mail::to($delivery->recipient);
            if (($cc = $salesSettings->ccInternalEmail()) !== null) {
                $mailer->cc($cc);
            }

            $mailer->send(new CustomerEmail(
                emailSubject: $delivery->metadata['message_subject']
                    ?? $this->getEmailSubject($invoice, $delivery->document_type),
                emailBody: $delivery->metadata['message_body'] ?? $this->getEmailBody(
                    $invoice,
                    $delivery->document_type,
                    $delivery->metadata['portal_url'] ?? null,
                    $paymentUrl,
                    $attachments !== [],
                ),
                emailAttachments: $attachments,
                qrDataUri: $paymentUrl
                    ? app(QrCodeService::class)->dataUri($paymentUrl)
                    : null,
            ));

            $delivery->update([
                'status' => InvoiceDelivery::STATUS_SENT,
                'attempted_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->markFailed($delivery, $e->getMessage());
            throw $e;
        }
    }

    private function sendWhatsApp(InvoiceDelivery $delivery, Invoice $invoice): void
    {
        try {
            $message = $delivery->metadata['message_body'] ?? $this->getWhatsAppMessage($invoice, $delivery->document_type);

            app(\Modules\WhatsApp\src\Services\WhatsAppService::class)
                ->send($invoice->customer_id, $message, [
                    'reference' => $invoice->invoice_number,
                ]);

            $delivery->update([
                'status' => InvoiceDelivery::STATUS_SENT,
                'attempted_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $this->markFailed($delivery, $e->getMessage());
            throw $e;
        }
    }

    private function markPortalSent(InvoiceDelivery $delivery, Invoice $invoice): void
    {
        $delivery->update([
            'status' => InvoiceDelivery::STATUS_SENT,
            'attempted_at' => now(),
            'metadata' => array_merge($delivery->metadata ?? [], [
                'portal_url' => $this->getPortalUrl($invoice),
            ]),
        ]);
    }

    private function markFailed(InvoiceDelivery $delivery, string $error): void
    {
        $delivery->update([
            'status' => InvoiceDelivery::STATUS_FAILED,
            'error' => $error,
            'attempted_at' => now(),
        ]);

        Log::error('Invoice delivery failed', [
            'delivery_id' => $delivery->id,
            'invoice_id' => $delivery->invoice_id,
            'channel' => $delivery->channel,
            'error' => $error,
        ]);
    }

    public function updateDeliveryStatus(Invoice $invoice): void
    {
        $deliveries = InvoiceDelivery::where('invoice_id', $invoice->id)
            ->whereIn('document_type', [InvoiceDelivery::DOCUMENT_ACCEPTANCE_COPY, InvoiceDelivery::DOCUMENT_FINAL_INVOICE])
            ->get();

        if ($deliveries->isEmpty()) {
            $newStatus = Invoice::DELIVERY_NOT_SENT;
        } elseif ($deliveries->every(fn ($d) => $d->status === InvoiceDelivery::STATUS_SENT)) {
            $newStatus = Invoice::DELIVERY_SENT;
        } elseif ($deliveries->some(fn ($d) => $d->status === InvoiceDelivery::STATUS_SENT)) {
            $newStatus = Invoice::DELIVERY_PARTIALLY_SENT;
        } else {
            $newStatus = Invoice::DELIVERY_FAILED;
        }

        $invoice->update(['delivery_status' => $newStatus]);
    }

    private function generatePdfData(Invoice $invoice, string $documentType): string
    {
        // Render the invoice through the TemplateBuilder PDF service and return
        // the raw binary so it can be attached to the delivery email. Issue
        // #617: the selected document type must reach the renderer, otherwise
        // the acceptance copy and the final invoice are the same attachment.
        $pdfService = app(\Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract::class);

        $result = $pdfService->render('invoice', (string) $invoice->id, [
            'format' => 'pdf',
            'document_variant' => $documentType,
        ]);

        return $result['bytes'];
    }

    private function getEmailSubject(Invoice $invoice, string $documentType): string
    {
        $prefix = $documentType === InvoiceDelivery::DOCUMENT_ACCEPTANCE_COPY
            ? '[ACCEPTANCE COPY]'
            : 'Invoice';
        $company = $invoice->company_code ?? 'Our Company';

        return "{$prefix} {$invoice->invoice_number} from {$company}";
    }

    private function getEmailBody(Invoice $invoice, string $documentType, ?string $portalUrl = null, ?string $paymentUrl = null, bool $pdfAttached = true): string
    {
        $type = $documentType === InvoiceDelivery::DOCUMENT_ACCEPTANCE_COPY
            ? 'acceptance copy'
            : 'final invoice';
        $dueDate = $invoice->due_date ? $invoice->due_date->format('d M Y') : 'N/A';

        $body = "Dear {$invoice->customer?->name},\n\n".
            ($pdfAttached
                ? "Please find attached the {$type} for invoice {$invoice->invoice_number}.\n\n"
                : "This is the {$type} for invoice {$invoice->invoice_number}.\n\n").
            "Amount: {$invoice->currency_code} ".number_format($invoice->total, 2)."\n".
            "Due Date: {$dueDate}\n\n";

        if ($portalUrl) {
            $body .= "To review and electronically sign this invoice, open this link:\n".
                "{$portalUrl}\n\n";
        }

        if ($paymentUrl) {
            $body .= "To pay this invoice securely online, open this link or scan the QR code below:\n".
                "{$paymentUrl}\n\n";
        }

        $body .= 'Thank you for your business.';

        return $body;
    }

    private function getWhatsAppMessage(Invoice $invoice, string $documentType): string
    {
        $type = $documentType === InvoiceDelivery::DOCUMENT_ACCEPTANCE_COPY
            ? 'Acceptance Copy'
            : 'Invoice';

        return "{$type} {$invoice->invoice_number}\n".
            "Amount: {$invoice->currency_code} ".number_format($invoice->total, 2)."\n".
            "Due: {$invoice->due_date->format('d M Y')}\n\n".
            'Please check your email for the full document.';
    }

    private function getPortalUrl(Invoice $invoice): string
    {
        $frontendUrl = rtrim((string) config('frontend.url'), '/');

        return "{$frontendUrl}/invoice-accept/{$invoice->id}";
    }
}

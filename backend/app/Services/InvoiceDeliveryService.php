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
    public function __construct(
        private InvoiceActivityService $activityLog,
    ) {}

    public function deliver(Invoice $invoice, string $channel, User $user, array $options = []): InvoiceDelivery
    {
        // An invoice is deliverable once it is posted, or while still in draft
        // after approval (the "send to customer for acceptance" step). A draft
        // that is pending approval, rejected, or plain must not be emailed yet.
        $isDeliverable = $invoice->status !== 'draft'
            || $invoice->approval_status === Invoice::APPROVAL_APPROVED;

        if (! $isDeliverable) {
            throw new \InvalidArgumentException('Invoice is not ready to be delivered. It must be approved or already posted.');
        }

        if (! in_array($channel, InvoiceDelivery::CHANNELS, true)) {
            throw new \InvalidArgumentException("Invalid delivery channel: {$channel}. Allowed: ".implode(', ', InvoiceDelivery::CHANNELS));
        }

        $documentType = $options['document_type'] ?? InvoiceDelivery::DOCUMENT_ACCEPTANCE_COPY;
        $recipient = $options['recipient'] ?? $invoice->contact_email ?? $invoice->customer?->email;

        if (! $recipient) {
            throw new \InvalidArgumentException('No recipient email available for invoice delivery.');
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

            $delivery = InvoiceDelivery::create([
                'invoice_id' => $invoice->id,
                'channel' => $channel,
                'document_type' => $documentType,
                'recipient' => $recipient,
                'status' => InvoiceDelivery::STATUS_QUEUED,
                'sent_by' => $user->id,
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
                'delivered_by' => $user->id,
            ],
            $invoice->getOriginal('delivery_status') ?? Invoice::DELIVERY_NOT_SENT,
            $invoice->fresh()->delivery_status,
            InvoiceActivityLog::ACTOR_USER,
            $user->id,
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
            authorId: $user->id,
            authorType: 'user',
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
            $pdfData = $this->generatePdfData($invoice, $delivery->document_type);
            $paymentUrl = $delivery->metadata['payment_url'] ?? null;

            Mail::to($delivery->recipient)->send(new CustomerEmail(
                emailSubject: $this->getEmailSubject($invoice, $delivery->document_type),
                emailBody: $this->getEmailBody(
                    $invoice,
                    $delivery->document_type,
                    $delivery->metadata['portal_url'] ?? null,
                    $paymentUrl,
                ),
                emailAttachments: [
                    [
                        'name' => "Invoice-{$invoice->invoice_number}.pdf",
                        'data' => $pdfData,
                        'mime' => 'application/pdf',
                    ],
                ],
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
            $message = $this->getWhatsAppMessage($invoice, $delivery->document_type);

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
        // the raw binary so it can be attached to the delivery email.
        $pdfService = app(\Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract::class);

        $result = $pdfService->render('invoice', (string) $invoice->id, ['format' => 'pdf']);

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

    private function getEmailBody(Invoice $invoice, string $documentType, ?string $portalUrl = null, ?string $paymentUrl = null): string
    {
        $type = $documentType === InvoiceDelivery::DOCUMENT_ACCEPTANCE_COPY
            ? 'acceptance copy'
            : 'final invoice';
        $dueDate = $invoice->due_date ? $invoice->due_date->format('d M Y') : 'N/A';

        $body = "Dear {$invoice->customer?->name},\n\n".
            "Please find attached the {$type} for invoice {$invoice->invoice_number}.\n\n".
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

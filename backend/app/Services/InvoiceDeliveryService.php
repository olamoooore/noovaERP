<?php

namespace App\Services;

use App\Mail\CustomerEmail;
use App\Models\Invoice;
use App\Models\InvoiceActivityLog;
use App\Models\InvoiceDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class InvoiceDeliveryService
{
    public function __construct(
        private InvoiceActivityService $activityLog,
    ) {}

    public function deliver(Invoice $invoice, string $channel, User $user, array $options = []): InvoiceDelivery
    {
        $isDraftPendingApproval = $invoice->status === 'draft'
            && $invoice->approval_status === Invoice::APPROVAL_PENDING;

        if ($invoice->status === 'draft' && ! $isDraftPendingApproval) {
            throw new \InvalidArgumentException('Cannot deliver a draft invoice. Invoice must be in draft with approval or posted.');
        }

        if (! in_array($channel, InvoiceDelivery::CHANNELS, true)) {
            throw new \InvalidArgumentException("Invalid delivery channel: {$channel}. Allowed: ".implode(', ', InvoiceDelivery::CHANNELS));
        }

        $documentType = $options['document_type'] ?? InvoiceDelivery::DOCUMENT_ACCEPTANCE_COPY;
        $recipient = $options['recipient'] ?? $invoice->contact_email ?? $invoice->customer?->email;

        if (! $recipient) {
            throw new \InvalidArgumentException('No recipient email available for invoice delivery.');
        }

        $delivery = DB::transaction(function () use ($invoice, $channel, $user, $documentType, $recipient) {
            $delivery = InvoiceDelivery::create([
                'invoice_id' => $invoice->id,
                'channel' => $channel,
                'document_type' => $documentType,
                'recipient' => $recipient,
                'status' => InvoiceDelivery::STATUS_QUEUED,
                'sent_by' => $user->id,
                'metadata' => [
                    'attempt' => 1,
                    'invoice_number' => $invoice->invoice_number,
                ],
            ]);

            return $delivery;
        });

        $this->sendViaChannel($delivery, $invoice);

        $this->updateDeliveryStatus($invoice);

        $this->activityLog->log(
            $invoice,
            InvoiceActivityLog::EVENT_SENT,
            [
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

            Mail::to($delivery->recipient)->send(new CustomerEmail([
                'subject' => $this->getEmailSubject($invoice, $delivery->document_type),
                'body' => $this->getEmailBody($invoice, $delivery->document_type),
                'attachments' => [
                    [
                        'name' => "Invoice-{$invoice->invoice_number}.pdf",
                        'data' => $pdfData,
                        'mime' => 'application/pdf',
                    ],
                ],
            ]));

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
        $pdfService = app(\Modules\TemplateBuilder\Services\DocumentRenderService::class);

        return $pdfService->render($invoice, $documentType);
    }

    private function getEmailSubject(Invoice $invoice, string $documentType): string
    {
        $prefix = $documentType === InvoiceDelivery::DOCUMENT_ACCEPTANCE_COPY
            ? '[ACCEPTANCE COPY]'
            : 'Invoice';
        $company = $invoice->company_code ?? 'Our Company';

        return "{$prefix} {$invoice->invoice_number} from {$company}";
    }

    private function getEmailBody(Invoice $invoice, string $documentType): string
    {
        $type = $documentType === InvoiceDelivery::DOCUMENT_ACCEPTANCE_COPY
            ? 'acceptance copy'
            : 'final invoice';
        $dueDate = $invoice->due_date ? $invoice->due_date->format('d M Y') : 'N/A';

        return "Dear {$invoice->customer?->name},\n\n".
            "Please find attached the {$type} for invoice {$invoice->invoice_number}.\n\n".
            "Amount: {$invoice->currency_code} ".number_format($invoice->total, 2)."\n".
            "Due Date: {$dueDate}\n\n".
            'Thank you for your business.';
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
        return url("/invoice-accept/{$invoice->id}");
    }
}

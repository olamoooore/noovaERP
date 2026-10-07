<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\CustomerEmail;
use App\Models\Payment;
use App\Models\PaymentReceiptDelivery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Modules\Communication\Models\CommunicationPreference;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Modules\TemplateBuilder\Services\DataSource\DataSourceRegistry;
use Modules\WhatsApp\Models\WhatsAppApiKey;
use Modules\WhatsApp\Services\WhatsAppService;

/**
 * Issue #500 — send a customer payment's official receipt.
 *
 * Reuses the shared pieces rather than a parallel path: the receipt is the
 * TemplateBuilder 'receipt' document (PdfGenerationService, so the tenant's
 * resolved template and the #613 issued copy apply), the email is the shared
 * CustomerEmail mailable (InvoiceDeliveryService pattern, incl. the #539
 * internal CC), the recipient lookup is the statement send's
 * (CustomerStatementDeliveryService::recipientFor), the customer's
 * Communication preferences are honoured (CustomerFollowupService pattern),
 * and every attempt leaves one payment_receipt_deliveries row — a failed send
 * is recorded and reported, never shown as sent.
 */
class PaymentReceiptDeliveryService
{
    public const NOT_POSTED_MESSAGE = 'Only a posted customer payment has a receipt to send.';

    public function __construct(
        private readonly WhatsAppService $whatsApp,
    ) {}

    /**
     * The reason this payment's receipt cannot be sent on $channel, or null.
     */
    public function sendabilityError(Payment $payment, string $channel): ?string
    {
        if ($payment->customer_id === null
            || $payment->journal_entry_id === null
            || ! in_array($payment->payment_status, ['applied', 'unapplied'], true)) {
            return self::NOT_POSTED_MESSAGE;
        }

        $preference = $this->communicationPreference((int) $payment->customer_id);
        if ($preference !== null && ! $preference->isChannelEnabled($channel)) {
            return "Customer has turned {$channel} messages off.";
        }

        return null;
    }

    public function deliver(Payment $payment, string $channel, ?string $recipient = null): PaymentReceiptDelivery
    {
        if (! in_array($channel, PaymentReceiptDelivery::CHANNELS, true)) {
            throw new \InvalidArgumentException("Unsupported receipt channel: {$channel}");
        }

        if (($error = $this->sendabilityError($payment, $channel)) !== null) {
            throw new \InvalidArgumentException($error);
        }

        $payment->loadMissing('customer');
        $recipient = $recipient ?: app(CustomerStatementDeliveryService::class)->recipientFor($payment->customer, $channel);

        if (! $recipient) {
            throw new \InvalidArgumentException(
                $channel === PaymentReceiptDelivery::CHANNEL_WHATSAPP
                    ? 'No WhatsApp number is on file for this customer.'
                    : 'No email address is on file for this customer.'
            );
        }

        $delivery = PaymentReceiptDelivery::create([
            'payment_id' => $payment->id,
            'customer_id' => $payment->customer_id,
            'channel' => $channel,
            'recipient' => $recipient,
            'status' => PaymentReceiptDelivery::STATUS_SENT,
            'sent_by' => Auth::id(),
            'sent_at' => now(),
            'metadata' => ['payment_number' => $payment->payment_number],
        ]);

        try {
            match ($channel) {
                PaymentReceiptDelivery::CHANNEL_EMAIL => $this->sendEmail($payment, $recipient),
                PaymentReceiptDelivery::CHANNEL_WHATSAPP => $this->sendWhatsApp($payment, $recipient),
            };
        } catch (\Throwable $e) {
            $delivery->forceFill([
                'status' => PaymentReceiptDelivery::STATUS_FAILED,
                'error' => $e->getMessage(),
            ])->save();

            Log::error('Payment receipt delivery failed', [
                'payment_id' => $payment->id,
                'delivery_id' => $delivery->id,
                'channel' => $channel,
                'recipient' => $recipient,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $delivery;
    }

    private function sendEmail(Payment $payment, string $recipient): void
    {
        $bytes = app(PdfGenerationServiceContract::class)
            ->render('receipt', (string) $payment->id, ['format' => 'pdf'])['bytes'];

        $receipt = $this->receiptPayload($payment);
        $customerName = $payment->customer?->name ?: 'Sir/Madam';

        $body = "Dear {$customerName},\n\n"
            ."Thank you for your payment. Please find attached receipt {$payment->payment_number}.\n\n"
            .$this->summary($receipt)."\n\n"
            .'Thank you for your business.';

        $mailer = Mail::to($recipient);
        if (($cc = app(SalesSettings::class)->ccInternalEmail()) !== null) {
            $mailer->cc($cc);
        }

        $mailer->send(new CustomerEmail(
            emailSubject: "Payment receipt {$payment->payment_number}",
            emailBody: $body,
            emailAttachments: [[
                'name' => "Receipt-{$payment->payment_number}.pdf",
                'data' => $bytes,
                'mime' => 'application/pdf',
            ]],
        ));
    }

    private function sendWhatsApp(Payment $payment, string $recipient): void
    {
        $apiKey = $this->activeWhatsAppKey();

        if ($apiKey === null) {
            throw new \RuntimeException('WhatsApp is not configured for this tenant.');
        }

        $message = "Payment receipt {$payment->payment_number}\n"
            .$this->summary($this->receiptPayload($payment))."\n\n"
            .'Thank you for your payment.';

        $result = $this->whatsApp->sendMessage($apiKey, $recipient, $message);

        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException((string) ($result['error'] ?? 'WhatsApp send failed'));
        }
    }

    /** The receipt document's own payload, so the message matches the PDF. */
    private function receiptPayload(Payment $payment): array
    {
        return app(DataSourceRegistry::class)->loadPayload('receipt', (string) $payment->id);
    }

    private function summary(array $receipt): string
    {
        $currency = (string) ($receipt['meta']['currency_code'] ?? '');
        $money = fn ($v) => trim($currency.' '.number_format((float) $v, 2));

        $lines = ['Amount received: '.$money($receipt['totals']['amount'] ?? 0)];
        foreach ($receipt['allocations'] ?? [] as $line) {
            $lines[] = "Applied to {$line['invoice_number']}: ".$money($line['applied_amount']);
        }
        if ((float) ($receipt['totals']['unapplied'] ?? 0) > 0) {
            $lines[] = 'Unapplied credit: '.$money($receipt['totals']['unapplied']);
        }

        return implode("\n", $lines);
    }

    private function communicationPreference(int $customerId): ?CommunicationPreference
    {
        if (! Schema::hasTable('communication_preferences')) {
            return null;
        }

        // The bound tenant's key, also when the request finished without an
        // initialized tenancy flag (the row is tenant-scoped either way).
        $tenantId = tenancy()->initialized
            ? (string) tenant('id')
            : rescue(fn () => tenant('id') !== null ? (string) tenant('id') : null, null, false);

        return CommunicationPreference::forCustomer($customerId)
            ->where(fn ($q) => $tenantId !== null
                ? $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id')
                : $q->whereNull('tenant_id'))
            ->orderByRaw('tenant_id IS NULL ASC')
            ->first();
    }

    private function activeWhatsAppKey(): ?WhatsAppApiKey
    {
        if (! Schema::hasTable('whatsapp_api_keys')) {
            return null;
        }

        return WhatsAppApiKey::query()->valid()->latest('id')->first();
    }
}

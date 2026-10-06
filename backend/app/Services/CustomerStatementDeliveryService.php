<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\CustomerStatementMail;
use App\Models\Customer;
use App\Models\CustomerStatement;
use App\Models\CustomerStatementDelivery;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;
use Modules\WhatsApp\Models\WhatsAppApiKey;
use Modules\WhatsApp\Services\WhatsAppService;

/**
 * Issue #607 (RPT-F1) — the one place that persists a statement to history,
 * renders it through the template platform and delivers it over a channel,
 * so the single send action and the batch job cannot drift into parallel
 * implementations. The ledger math stays in CustomerStatementService.
 */
class CustomerStatementDeliveryService
{
    public function __construct(
        private readonly CustomerStatementService $statementService,
        private readonly WhatsAppService $whatsApp,
    ) {}

    /**
     * Persist the canonical payload as a statement history record.
     *
     * A range ($start !== null) keeps the settled documents switch
     * (include_paid); an as-of snapshot never includes an opening balance.
     */
    public function generate(
        Customer $customer,
        ?Carbon $start,
        Carbon $end,
        bool $includePaid = false,
        ?string $periodType = null,
        ?int $generatedBy = null,
    ): CustomerStatement {
        $payload = $start !== null
            ? $this->statementService->buildRangePayload(
                $customer,
                $start,
                $end,
                $includePaid,
                $periodType ?? 'custom',
            )
            : $this->statementService->buildAsOfPayload($customer, $end);

        $statement = CustomerStatement::create([
            'customer_id' => $customer->id,
            'start_date' => $start?->toDateString(),
            'end_date' => $end->toDateString(),
            'period_type' => $start !== null ? ($periodType ?? 'custom') : 'as_of',
            'include_paid' => (bool) ($payload['include_paid'] ?? false),
            'payload' => $payload,
            'generated_by' => $generatedBy ?? Auth::id(),
        ]);

        // Issue #632 (CH4): every persisted statement leaves a chatter trail.
        $this->logChatter(
            $statement,
            'generated',
            "Generated statement of account for {$customer->name}",
            [
                'period_type' => $statement->period_type,
                'start_date' => optional($statement->start_date)->toDateString(),
                'end_date' => optional($statement->end_date)->toDateString(),
            ],
        );

        return $statement;
    }

    /**
     * Render through the tenant's template (issue #616) and return the bytes.
     * The previous per-call code paths rendered the accounting Blade directly.
     */
    public function renderPdf(CustomerStatement $statement, ?string $templateId = null): string
    {
        $result = app(PdfGenerationServiceContract::class)->render(
            'customer_statement',
            (string) $statement->id,
            [
                'format' => 'pdf',
                'template_id' => $templateId,
            ],
        );

        return $result['bytes'];
    }

    /**
     * Render and persist the statement PDF, recording its public-disk path.
     */
    public function storePdf(CustomerStatement $statement, ?string $templateId = null): string
    {
        $bytes = $this->renderPdf($statement, $templateId);

        $relativePath = sprintf(
            'customer-statements/customer-%d/statement-%d.pdf',
            $statement->customer_id,
            $statement->id,
        );
        Storage::disk('public')->put($relativePath, $bytes);

        $statement->forceFill(['pdf_path' => $relativePath])->save();

        return $relativePath;
    }

    /**
     * Deliver one statement and always leave exactly one send-log row.
     */
    public function deliver(
        CustomerStatement $statement,
        string $channel,
        ?string $recipient = null,
        ?int $batchId = null,
        ?int $sentBy = null,
    ): CustomerStatementDelivery {
        $statement->loadMissing('customer');
        $customer = $statement->customer;

        $recipient = $recipient ?: $this->recipientFor($customer, $channel);

        if (! $recipient) {
            throw new \InvalidArgumentException(
                $channel === CustomerStatementDelivery::CHANNEL_WHATSAPP
                    ? 'No WhatsApp number is on file for this customer.'
                    : 'No statement email is on file for this customer.'
            );
        }

        $delivery = CustomerStatementDelivery::create([
            'customer_statement_id' => $statement->id,
            'customer_id' => $statement->customer_id,
            'customer_statement_batch_id' => $batchId,
            'channel' => $channel,
            'recipient' => $recipient,
            'status' => CustomerStatementDelivery::STATUS_SENT,
            'sent_by' => $sentBy ?? Auth::id(),
            'sent_at' => now(),
        ]);

        try {
            match ($channel) {
                CustomerStatementDelivery::CHANNEL_EMAIL => $this->sendEmail($statement, $recipient),
                CustomerStatementDelivery::CHANNEL_WHATSAPP => $this->sendWhatsApp($statement, $recipient),
                default => throw new \InvalidArgumentException("Unsupported statement channel: {$channel}"),
            };
        } catch (\Throwable $e) {
            $delivery->forceFill([
                'status' => CustomerStatementDelivery::STATUS_FAILED,
                'error' => $e->getMessage(),
            ])->save();

            Log::error('Customer statement delivery failed', [
                'statement_id' => $statement->id,
                'channel' => $channel,
                'recipient' => $recipient,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // Issue #632 (CH4): the send step is part of the statement trail.
        $this->logChatter(
            $statement,
            'sent',
            "Sent statement of account to {$recipient}",
            [
                'channel' => $channel,
                'recipient' => $recipient,
                'recipient_email' => $channel === CustomerStatementDelivery::CHANNEL_EMAIL ? $recipient : null,
            ],
        );

        return $delivery;
    }

    /**
     * Resolve the statement recipient: the customer's (primary/statement)
     * contact email, falling back to the customer's billing email; WhatsApp
     * uses the customer's mobile/phone.
     */
    public function recipientFor(Customer $customer, string $channel): ?string
    {
        if ($channel === CustomerStatementDelivery::CHANNEL_WHATSAPP) {
            $phone = trim((string) ($customer->mobile ?: $customer->phone));

            return $phone !== '' ? $phone : null;
        }

        $contactEmail = $customer->customerContacts()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderByDesc('is_primary')
            ->value('email');

        $email = trim((string) ($contactEmail ?: $customer->email));

        return $email !== '' ? $email : null;
    }

    private function sendEmail(CustomerStatement $statement, string $recipient): void
    {
        Mail::to($recipient)->send(new CustomerStatementMail($statement));

        $statement->forceFill([
            'email_sent' => true,
            'email_sent_at' => now(),
            'recipient_email' => $recipient,
        ])->save();
    }

    private function sendWhatsApp(CustomerStatement $statement, string $recipient): void
    {
        $apiKey = $this->activeWhatsAppKey();

        if ($apiKey === null) {
            throw new \RuntimeException('WhatsApp is not configured for this tenant.');
        }

        $payload = $statement->payload ?? [];
        $balance = number_format((float) ($payload['closing_balance'] ?? 0), 2);
        $currency = (string) ($payload['currency'] ?? '');
        $asOf = (string) ($payload['as_of_date'] ?? optional($statement->end_date)->toDateString());

        $message = "Statement of Account {$statement->customer?->code}\n"
            ."Closing balance: {$currency} {$balance} as of {$asOf}\n\n"
            .'Please check your email for the full statement.';

        $result = $this->whatsApp->sendMessage($apiKey, $recipient, $message);

        if (! ($result['success'] ?? false)) {
            throw new \RuntimeException((string) ($result['error'] ?? 'WhatsApp send failed'));
        }
    }

    private function activeWhatsAppKey(): ?WhatsAppApiKey
    {
        if (! Schema::hasTable('whatsapp_api_keys')) {
            return null;
        }

        return WhatsAppApiKey::query()->valid()->latest('id')->first();
    }

    /**
     * Issue #632 (CH4): mirror a statement's generate/send step into the
     * shared chatter History feed. Chatter failures must never break statement
     * delivery.
     */
    private function logChatter(
        CustomerStatement $statement,
        string $event,
        string $description,
        array $properties = [],
    ): void {
        try {
            app(\App\Services\Chatter\ChatterService::class)->logEvent(
                CustomerStatement::class,
                (int) $statement->id,
                $event,
                $description,
                $properties,
                Auth::id(),
                Auth::check() ? get_class(Auth::user()) : null,
            );
        } catch (\Throwable $e) {
            Log::warning('CustomerStatementDeliveryService: chatter mirror failed', [
                'statement_id' => $statement->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

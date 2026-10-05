<?php

namespace App\Services;

use App\Mail\CustomerEmail;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\TemplateBuilder\Contracts\PdfGenerationServiceContract;

/**
 * Issue #334 (Q5): send a quotation to the customer.
 *
 * Renders the quotation through the TemplateBuilder platform, issues the
 * #149 public acceptance token, transitions the quotation to `sent` through
 * the #900 WorkflowEngine and emails the customer the PDF plus the acceptance
 * link (reusing the shared CustomerEmail mailable — the same delivery path the
 * invoice and delivery-order sends use).
 */
class QuotationSendService
{
    public function __construct(
        private SalesOrderAcceptanceService $acceptance,
        private SalesWorkflowService $workflow,
    ) {}

    /**
     * @return array{
     *     quotation: Quotation,
     *     token: string,
     *     url: string,
     *     expires_at: ?string,
     *     delivery: ?array{recipient: string, cc: string[]},
     *     email_error: ?string
     * }
     */
    public function send(Quotation $quotation, User $user, array $options = []): array
    {
        if (! in_array((string) $quotation->status, ['draft', 'sent'], true)) {
            throw new \InvalidArgumentException(
                "Only draft or sent quotations can be sent (current status: {$quotation->status})."
            );
        }

        $token = $this->acceptance->issueQuotationToken($quotation);
        $url = rtrim((string) config('frontend.url'), '/')."/quotation-accept/{$token}";

        $sendEmail = (bool) ($options['send_email'] ?? true);
        $recipient = $options['recipient'] ?? $quotation->contact_email ?? $quotation->customer?->email;

        // Render first: without a PDF there is nothing to send, so a render
        // failure must abort before the status changes (never a silently
        // link-only "send").
        $pdfBytes = null;
        if ($sendEmail && $recipient) {
            $pdfBytes = app(PdfGenerationServiceContract::class)
                ->render(self::documentType(), (string) $quotation->id, ['format' => 'pdf'])['bytes'];
        }

        $fromStatus = (string) $quotation->status;

        // #900 (PC6): the status write goes through the engine. A re-send of an
        // already-sent quotation has nothing to transition.
        if ($quotation->status === 'draft') {
            $this->workflow->transition($quotation->fresh(), 'send');
            $quotation->refresh();
        }

        $delivery = null;
        $emailError = null;

        if ($sendEmail && $recipient) {
            [$delivery, $emailError] = $this->emailCustomer($quotation, $recipient, $options, $pdfBytes, $url);
        }

        $this->logChatter(
            $quotation,
            'status_changed',
            "Sent quotation {$quotation->quotation_number} to the customer",
            [
                'from_state' => $fromStatus,
                'to_state' => $quotation->status,
                'recipient' => $delivery['recipient'] ?? $recipient,
                'acceptance_url' => $url,
            ],
        );

        return [
            'quotation' => $quotation->fresh(['customer', 'lines.product']),
            'token' => $token,
            'url' => $url,
            'expires_at' => optional($quotation->fresh()->acceptance_expires_at)->toIso8601String(),
            'delivery' => $delivery,
            'email_error' => $emailError,
        ];
    }

    public static function documentType(): string
    {
        return 'quotation';
    }

    /**
     * @return array{0: ?array{recipient: string, cc: string[]}, 1: ?string}
     */
    private function emailCustomer(Quotation $quotation, string $recipient, array $options, ?string $pdfBytes, string $url): array
    {
        $ccList = collect($options['cc'] ?? [])
            ->map(fn ($email) => trim((string) $email))
            ->filter(fn ($email) => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();

        $customerName = $quotation->customer?->name ?: 'Sir/Madam';
        $subject = "Quotation {$quotation->quotation_number} — please review and accept";
        $body = "Dear {$customerName},\n\n".
            "Please find attached quotation {$quotation->quotation_number}.\n".
            "Amount: {$quotation->currency_code} ".number_format((float) $quotation->total, 2)."\n\n".
            "To review and electronically accept this quotation, open this link:\n".
            "{$url}\n\n".
            'Thank you for your business.';

        $attachments = [];
        if ($pdfBytes !== null) {
            $attachments[] = [
                'name' => "Quotation-{$quotation->quotation_number}.pdf",
                'data' => $pdfBytes,
                'mime' => 'application/pdf',
            ];
        }

        try {
            $mailer = Mail::to($recipient);
            if ($ccList !== []) {
                $mailer->cc($ccList);
            }
            $mailer->send(new CustomerEmail($subject, $body, $attachments));

            return [['recipient' => $recipient, 'cc' => $ccList], null];
        } catch (\Throwable $e) {
            // The link is already issued and the status is sent — report the
            // failure so the operator can copy the link, never pretend the
            // email went out.
            Log::error('Quotation send email failed', [
                'quotation_id' => $quotation->id,
                'recipient' => $recipient,
                'error' => $e->getMessage(),
            ]);

            return [null, $e->getMessage()];
        }
    }

    private function logChatter(Quotation $quotation, string $event, string $description, array $properties = []): void
    {
        try {
            app(\App\Services\Chatter\ChatterService::class)->logEvent(
                Quotation::class,
                (int) $quotation->id,
                $event,
                $description,
                $properties,
                \Illuminate\Support\Facades\Auth::id(),
                \Illuminate\Support\Facades\Auth::check() ? get_class(\Illuminate\Support\Facades\Auth::user()) : null,
            );
        } catch (\Throwable $e) {
            Log::warning('QuotationSendService: chatter mirror failed', [
                'quotation_id' => $quotation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

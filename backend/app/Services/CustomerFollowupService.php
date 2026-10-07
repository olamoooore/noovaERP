<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceActivityLog;
use App\Models\InvoiceDelivery;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Modules\Communication\Models\CommunicationPreference;
use Modules\Communication\Services\TemplateRenderer;

/**
 * Issue #328 (Odoo 20): customer follow-up reminders for overdue invoices.
 *
 * Reuses, rather than re-implements:
 *  - the overdue set of the follow-up report (AgedReceivablesService);
 *  - the follow-up levels and the on/off switch from Sales Settings
 *    (SalesSettings::followupLevels / followupAutoSendEnabled);
 *  - the reminder text from the Communication module's templates
 *    (TemplateRenderer; `invoice.overdue` is the system template);
 *  - the send itself through InvoiceDeliveryService (document_type
 *    `reminder`), whose delivery rows and invoice activity log are the
 *    follow-up history;
 *  - the customer's communication preferences: the customer master's
 *    preferred contact channel and the Communication module's per-customer
 *    preferences (channel switched off, or the `invoice.followup` subtype
 *    muted).
 *
 * Out of scope here (tracked in #501): letters / customer statements per
 * level, per-customer exclusions and a dedicated follow-up history screen.
 */
class CustomerFollowupService
{
    /** The Communication subtype a customer can mute to stop reminders. */
    public const SUBTYPE = 'invoice.followup';

    public const MODE_MANUAL = 'manual';

    public const MODE_AUTOMATIC = 'automatic';

    public const OUTCOME_SENT = 'sent';

    public const OUTCOME_SKIPPED = 'skipped';

    public const OUTCOME_FAILED = 'failed';

    public const OUTCOME_ACTION_DUE = 'action_due';

    public const NOTHING_OVERDUE_MESSAGE = 'There are no overdue invoices to follow up.';

    public function __construct(
        private AgedReceivablesService $agedReceivables,
        private InvoiceDeliveryService $deliveryService,
        private InvoiceActivityService $activityService,
        private SalesSettings $salesSettings,
        private TemplateRenderer $templates,
    ) {}

    /**
     * Manual send from the follow-up list: one reminder per overdue invoice
     * of the customer.
     *
     * @return list<array<string, mixed>>
     */
    public function sendForCustomer(Customer $customer, User $user): array
    {
        $overdue = $this->agedReceivables->overdueInvoices(customerId: (int) $customer->id);

        if ($overdue === []) {
            throw new \InvalidArgumentException(self::NOTHING_OVERDUE_MESSAGE);
        }

        $levels = $this->salesSettings->followupLevels();

        return array_map(
            fn (array $row) => $this->send($row, $this->manualLevel($levels, $row['days_overdue']), self::MODE_MANUAL, $user),
            $overdue,
        );
    }

    /**
     * Manual send from the invoice detail page.
     *
     * @return array<string, mixed>
     */
    public function sendForInvoice(Invoice $invoice, User $user): array
    {
        $overdue = $this->agedReceivables->overdueInvoices(invoiceId: (int) $invoice->id);

        if ($overdue === []) {
            throw new \InvalidArgumentException('This invoice is not overdue.');
        }

        $row = $overdue[0];

        return $this->send($row, $this->manualLevel($this->salesSettings->followupLevels(), $row['days_overdue']), self::MODE_MANUAL, $user);
    }

    /**
     * The scheduled run for the current tenant: for every overdue invoice
     * that reached a level not handled yet, send the reminder (action
     * `send`) or log that a manual follow-up is due (action `manual`).
     * Nothing happens while automatic follow-ups are off.
     *
     * @return list<array<string, mixed>>
     */
    public function runAutomatic(): array
    {
        if (! $this->salesSettings->followupAutoSendEnabled()) {
            return [];
        }

        $levels = $this->salesSettings->followupLevels();
        $results = [];

        foreach ($this->agedReceivables->overdueInvoices() as $row) {
            $level = $this->reachedLevel($levels, $row['days_overdue']);

            if ($level === null) {
                continue;
            }

            if ($this->levelHandled($row['invoice'], $level['days'])) {
                continue;
            }

            if ($level['action'] === SalesSettings::FOLLOWUP_ACTION_MANUAL) {
                $this->activityService->log(
                    $row['invoice'],
                    InvoiceActivityLog::EVENT_FOLLOWUP_ACTION_DUE,
                    $this->historyMetadata($row, $level, self::MODE_AUTOMATIC),
                    null,
                    null,
                    InvoiceActivityLog::ACTOR_SYSTEM,
                    null,
                );
                $results[] = $this->result($row, $level, self::OUTCOME_ACTION_DUE);

                continue;
            }

            $results[] = $this->send($row, $level, self::MODE_AUTOMATIC, null);
        }

        return $results;
    }

    /**
     * The highest level the invoice has reached, or null below the first.
     *
     * @param  list<array{days: int, action: string, template: string}>  $levels
     * @return array{days: int, action: string, template: string}|null
     */
    private function reachedLevel(array $levels, int $daysOverdue): ?array
    {
        $reached = null;
        foreach ($levels as $level) {
            if ($daysOverdue >= $level['days']) {
                $reached = $level;
            }
        }

        return $reached;
    }

    /**
     * A manual send is a user's decision: it uses the reached level's
     * template, or the first level's below it.
     *
     * @param  list<array{days: int, action: string, template: string}>  $levels
     * @return array{days: int, action: string, template: string}
     */
    private function manualLevel(array $levels, int $daysOverdue): array
    {
        return $this->reachedLevel($levels, $daysOverdue) ?? $levels[0];
    }

    /**
     * Whether the scheduler already acted on this level for the invoice: a
     * reminder delivered at it, a manual action logged for it, or a skip
     * logged for it (so a customer who prefers phone is not re-logged daily).
     */
    private function levelHandled(Invoice $invoice, int $level): bool
    {
        $delivered = InvoiceDelivery::query()
            ->where('invoice_id', $invoice->id)
            ->where('document_type', InvoiceDelivery::DOCUMENT_REMINDER)
            ->where('status', InvoiceDelivery::STATUS_SENT)
            ->get(['metadata'])
            ->contains(fn (InvoiceDelivery $d) => (int) ($d->metadata['followup_level'] ?? 0) === $level);

        if ($delivered) {
            return true;
        }

        return InvoiceActivityLog::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('event', [InvoiceActivityLog::EVENT_FOLLOWUP_ACTION_DUE, InvoiceActivityLog::EVENT_FOLLOWUP_SKIPPED])
            ->get(['metadata'])
            ->contains(fn (InvoiceActivityLog $log) => ($log->metadata['followup_mode'] ?? null) === self::MODE_AUTOMATIC
                && (int) ($log->metadata['followup_level'] ?? 0) === $level);
    }

    /**
     * @param  array{invoice: Invoice, days_overdue: int, overdue_amount: float}  $row
     * @param  array{days: int, action: string, template: string}  $level
     * @return array<string, mixed>
     */
    private function send(array $row, array $level, string $mode, ?User $user): array
    {
        $invoice = $row['invoice'];
        $customer = $invoice->customer;

        $channel = $this->channelFor($customer);
        if (is_array($channel)) {
            return $this->skip($row, $level, $mode, $user, $channel['reason']);
        }

        // Same refusal rules as every other send (e.g. no recipient email).
        $reason = $this->deliveryService->deliverabilityError($invoice);
        if ($reason !== null) {
            return $this->skip($row, $level, $mode, $user, $reason);
        }

        $message = $this->render($row, $level['template']);

        try {
            $delivery = $this->deliveryService->deliver($invoice, $channel, $user, [
                'document_type' => InvoiceDelivery::DOCUMENT_REMINDER,
                'message_subject' => $message['subject'],
                'message_body' => $message['body'],
                'metadata' => [
                    'followup_level' => $level['days'],
                    'followup_mode' => $mode,
                    'followup_template' => $level['template'],
                    'days_overdue' => $row['days_overdue'],
                ],
            ]);
        } catch (\Throwable $e) {
            // The failed delivery row and log are written by
            // InvoiceDeliveryService; the caller gets the failure per invoice.
            Log::warning('Follow-up reminder failed', [
                'invoice_id' => $invoice->id,
                'mode' => $mode,
                'error' => $e->getMessage(),
            ]);

            return $this->result($row, $level, self::OUTCOME_FAILED, ['reason' => $e->getMessage()]);
        }

        return $this->result($row, $level, self::OUTCOME_SENT, [
            'delivery_id' => $delivery->id,
            'channel' => $channel,
            'recipient' => $delivery->recipient,
        ]);
    }

    /**
     * The channel the customer wants reminders on, or ['reason' => ...] when
     * their communication preferences rule every automatic channel out.
     *
     * @return string|array{reason: string}
     */
    private function channelFor(?Customer $customer): string|array
    {
        if ($customer === null) {
            return ['reason' => 'The invoice has no customer.'];
        }

        $preferred = strtolower(trim((string) ($customer->preferred_contact ?? '')));
        $channel = match ($preferred) {
            '', 'email' => InvoiceDelivery::CHANNEL_EMAIL,
            'whatsapp' => InvoiceDelivery::CHANNEL_WHATSAPP,
            default => null,
        };

        if ($channel === null) {
            return ['reason' => "Customer prefers to be contacted by {$preferred}; follow up manually."];
        }

        $preference = $this->communicationPreference((int) $customer->id);

        if ($preference?->isSubtypeMuted(self::SUBTYPE)) {
            return ['reason' => 'Customer has muted payment follow-ups.'];
        }

        if ($preference !== null && ! $preference->isChannelEnabled($channel)) {
            return ['reason' => "Customer has turned {$channel} messages off."];
        }

        return $channel;
    }

    private function communicationPreference(int $customerId): ?CommunicationPreference
    {
        $tenantId = tenancy()->initialized ? (string) tenant('id') : null;

        return CommunicationPreference::forCustomer($customerId)
            ->where(fn ($q) => $tenantId !== null
                ? $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id')
                : $q->whereNull('tenant_id'))
            ->orderByRaw('tenant_id IS NULL ASC')
            ->first();
    }

    /**
     * The level's Communication template rendered for this invoice, or the
     * built-in reminder text when the tenant has no such active template.
     *
     * @param  array{invoice: Invoice, days_overdue: int, overdue_amount: float}  $row
     * @return array{subject: string, body: string}
     */
    private function render(array $row, string $templateKey): array
    {
        $invoice = $row['invoice'];
        $currency = $invoice->currency_code ?: '';
        $dueDate = $invoice->due_date ? $invoice->due_date->format('d M Y') : 'N/A';
        $balance = trim($currency.' '.number_format((float) $invoice->balance, 2));

        $tenantId = tenancy()->initialized ? (string) tenant('id') : null;
        $rendered = $this->templates->render($templateKey, [
            'customer' => ['name' => (string) ($invoice->customer?->name ?? '')],
            'invoice' => [
                'number' => (string) $invoice->invoice_number,
                'total' => trim($currency.' '.number_format((float) $invoice->total, 2)),
                'balance' => $balance,
                'due_date' => $dueDate,
                'days_overdue' => (string) $row['days_overdue'],
            ],
        ], 'en', $tenantId);

        $subject = trim((string) ($rendered['subject'] ?? ''));
        $body = trim((string) ($rendered['body_plain'] ?? ''));

        if ($subject === '' || $body === '') {
            $subject = "Payment reminder: invoice {$invoice->invoice_number} is overdue";
            $body = "Dear {$invoice->customer?->name},\n\n"
                ."Our records show that invoice {$invoice->invoice_number} is {$row['days_overdue']} day(s) overdue.\n\n"
                ."Outstanding: {$balance}\n"
                ."Due date: {$dueDate}\n\n"
                ."If you have already paid, please disregard this reminder. Otherwise, please arrange payment at your earliest convenience.\n\n"
                .'Thank you.';
        }

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * @param  array{invoice: Invoice, days_overdue: int, overdue_amount: float}  $row
     * @param  array{days: int, action: string, template: string}  $level
     * @return array<string, mixed>
     */
    private function skip(array $row, array $level, string $mode, ?User $user, string $reason): array
    {
        $this->activityService->log(
            $row['invoice'],
            InvoiceActivityLog::EVENT_FOLLOWUP_SKIPPED,
            $this->historyMetadata($row, $level, $mode) + ['reason' => $reason],
            null,
            null,
            $user ? InvoiceActivityLog::ACTOR_USER : InvoiceActivityLog::ACTOR_SYSTEM,
            $user?->id,
        );

        return $this->result($row, $level, self::OUTCOME_SKIPPED, ['reason' => $reason]);
    }

    /**
     * @param  array{invoice: Invoice, days_overdue: int, overdue_amount: float}  $row
     * @param  array{days: int, action: string, template: string}  $level
     * @return array<string, mixed>
     */
    private function historyMetadata(array $row, array $level, string $mode): array
    {
        return [
            'invoice_number' => $row['invoice']->invoice_number,
            'followup_level' => $level['days'],
            'followup_mode' => $mode,
            'days_overdue' => $row['days_overdue'],
        ];
    }

    /**
     * @param  array{invoice: Invoice, days_overdue: int, overdue_amount: float}  $row
     * @param  array{days: int, action: string, template: string}  $level
     * @return array<string, mixed>
     */
    private function result(array $row, array $level, string $outcome, array $extra = []): array
    {
        return array_merge([
            'invoice_id' => (int) $row['invoice']->id,
            'invoice_number' => $row['invoice']->invoice_number,
            'customer_id' => (int) $row['invoice']->customer_id,
            'days_overdue' => $row['days_overdue'],
            'overdue_amount' => $row['overdue_amount'],
            'followup_level' => $level['days'],
            'outcome' => $outcome,
        ], $extra);
    }
}

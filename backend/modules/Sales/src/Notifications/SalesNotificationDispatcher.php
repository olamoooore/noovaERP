<?php

declare(strict_types=1);

namespace Modules\Sales\Notifications;

use App\Models\User;
use App\Platform\Notifications\NotificationLog;
use App\Platform\Notifications\NotificationReferences;
use App\Platform\Notifications\NotificationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Exceptions\CreditLimitExceededException;

/**
 * SalesNotificationDispatcher — the single fan-out point for Sales
 * notifications (issue #903, PC9).
 *
 * Input is a notification event from SalesNotificationCatalogue plus the
 * documented event payload (the #901 envelope for bus-driven events, or the
 * same shape built by the scan commands / the credit-limit path). It:
 *   1. resolves recipients (SalesNotificationRecipientResolver) and drops the
 *      actor who triggered the transition;
 *   2. applies each recipient's per-event/per-channel preference over
 *      `platform_notification_preferences` on top of the catalogue defaults
 *      (a user with no row gets the defaults — "defaults per role"); a
 *      suppressed event/channel produces nothing;
 *   3. skips recipients already notified for the same event id (at-least-once
 *      bus redelivery) or, for the time-based scans, the same document;
 *   4. delivers through the platform NotificationService only, with a payload
 *      that always links the document (`primary` + `links` refs, a `url` and
 *      the legacy `invoice_id`/`document_number` fields).
 *
 * The method returns the number of notifications written, which the scan
 * commands report.
 */
class SalesNotificationDispatcher
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SalesNotificationRecipientResolver $recipients,
    ) {}

    /**
     * Dispatch one notification event to its audience.
     *
     * @param  array<string, mixed>  $payload
     * @return int Notifications written
     */
    public function dispatch(string $notificationEvent, array $payload): int
    {
        if (! SalesNotificationCatalogue::has($notificationEvent)) {
            return 0;
        }

        $defaults = SalesNotificationCatalogue::defaultChannels($notificationEvent);
        $actorId = $this->actorId($payload);

        $recipients = $this->recipients->resolve($notificationEvent, $payload);

        if ($actorId !== null) {
            $recipients = $recipients->reject(fn (User $user) => (int) $user->id === $actorId);
        }

        $written = 0;

        foreach ($recipients as $user) {
            try {
                if ($this->alreadyDelivered($notificationEvent, $user, $payload)) {
                    continue;
                }

                // Issue #907: a document-scoped mute suppresses every channel
                // of that document for that user only.
                if ($this->notifications->isDocumentMuted(
                    (int) $user->id,
                    $notificationEvent,
                    isset($payload['document_type']) ? (string) $payload['document_type'] : null,
                    $payload['document_id'] ?? null,
                )) {
                    continue;
                }

                $channels = $this->channelsFor($user, $notificationEvent, $defaults);

                if ($channels === []) {
                    continue;
                }

                $this->notifications->dispatchOnChannels(
                    $notificationEvent,
                    $user,
                    $this->dataFor($notificationEvent, $payload),
                    $channels,
                );

                $written++;
            } catch (\Throwable $e) {
                // A notification failure must not roll back the document action
                // that triggered it; surface it with context instead.
                Log::error('SalesNotificationDispatcher: notification failed', [
                    'event' => $notificationEvent,
                    'user_id' => $user->id,
                    'document_type' => $payload['document_type'] ?? null,
                    'document_id' => $payload['document_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $written;
    }

    /**
     * Dispatch the notification mapped to a #901 domain event name.
     *
     * @param  array<string, mixed>  $payload
     * @return int Notifications written
     */
    public function dispatchDomainEvent(string $domainEventName, array $payload): int
    {
        $notificationEvent = SalesNotificationCatalogue::notificationEventForDomainEvent($domainEventName);

        if ($notificationEvent === null) {
            return 0;
        }

        return $this->dispatch($notificationEvent, $payload);
    }

    /**
     * The credit-limit rejection path (#903): the check lives in
     * CreditCheckService/SalesOrderService, which throws the typed exception
     * *after* its transaction rolled back, so this is called from the catch
     * site and no notification row is rolled back with the failed order.
     *
     * @return int Notifications written
     */
    public function dispatchCreditLimitExceeded(CreditLimitExceededException $exception): int
    {
        $order = $exception->order;
        $customer = $exception->customer;
        $check = $exception->creditCheck;

        return $this->dispatch(SalesNotificationCatalogue::CREDIT_LIMIT_EXCEEDED, [
            'document_type' => 'sales_order',
            'document_id' => $order?->id,
            'document_number' => $order?->sales_order_number,
            'customer_id' => $customer?->id ?? $order?->customer_id,
            'total' => (float) ($order?->total ?? $check->orderTotal),
            'currency_code' => $order?->currency_code,
            'actor_user_id' => auth()->id(),
            'credit_limit' => $check->creditLimit,
            'current_balance' => $check->currentBalance,
            'available_credit' => $check->availableCredit,
            'order_total' => $check->orderTotal,
            'reason' => $check->reason,
        ]);
    }

    /**
     * The channels a recipient should get for an event: catalogue defaults
     * overridden by any explicit (event, channel) preference row.
     *
     * @param  string[]  $defaults
     * @return string[]
     */
    public function channelsFor(User $user, string $notificationEvent, array $defaults): array
    {
        $preferences = $this->preferences($user, $notificationEvent);
        $channels = [];

        foreach (SalesNotificationCatalogue::DELIVERABLE_CHANNELS as $channel) {
            $row = $preferences->get($channel);

            $enabled = $row !== null
                ? (bool) $row->mail_enabled
                : in_array($channel, $defaults, true);

            if ($enabled) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    /**
     * @return Collection<string, \App\Platform\Notifications\NotificationPreference>
     */
    private function preferences(User $user, string $notificationEvent): Collection
    {
        return \App\Platform\Notifications\NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('event', $notificationEvent)
            ->get()
            ->keyBy('channel');
    }

    /**
     * Has this user already been notified for this event (bus redelivery) or,
     * when the payload carries no event id, for this document (scan reruns)?
     *
     * @param  array<string, mixed>  $payload
     */
    private function alreadyDelivered(string $notificationEvent, User $user, array $payload): bool
    {
        $query = NotificationLog::query()
            ->where('event', $notificationEvent)
            ->where('user_id', $user->id);

        $eventId = $payload['event_id'] ?? null;

        if (is_string($eventId) && $eventId !== '') {
            return (clone $query)->where('data->event_id', $eventId)->exists();
        }

        $documentType = $payload['document_type'] ?? null;
        $documentId = $payload['document_id'] ?? null;

        if (is_string($documentType) && $documentType !== '' && $documentId !== null && $documentId !== '') {
            return (clone $query)
                ->where('data->document_type', $documentType)
                ->where('data->document_id', (string) $documentId)
                ->exists();
        }

        return false;
    }

    /**
     * The notification payload. Always carries the document reference block so
     * the bell and the notifications page can link to the document, plus a
     * plain `url` for the email leg.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function dataFor(string $notificationEvent, array $payload): array
    {
        $documentType = (string) ($payload['document_type'] ?? '');
        $documentId = $payload['document_id'] ?? null;
        $number = $payload['document_number'] ?? null;
        [$subject, $body] = $this->copyFor($notificationEvent, $payload);

        $document = $this->recipients->document($payload);
        $references = $document !== null
            ? NotificationReferences::forDocument($document)
            : $this->payloadReferences($payload, $documentType, $documentId, $number);

        $url = $this->urlFor($documentType, $documentId);

        // Payments and credit notes have no page of their own: point at the
        // invoice they relate to.
        if ($url === null && is_numeric($payload['invoice_id'] ?? null)) {
            $url = $this->urlFor('invoice', (int) $payload['invoice_id']);
        }

        if ($url !== null) {
            $body .= "\n\nOpen: {$url}";
        }

        return array_filter([
            'subject' => $subject,
            'body' => $body,
            'event_id' => $payload['event_id'] ?? null,
            'document_type' => $documentType !== '' ? $documentType : null,
            'document_id' => $documentId,
            'document_number' => $number,
            'invoice_id' => $payload['invoice_id'] ?? ($documentType === 'invoice' ? $documentId : null),
            'url' => $url,
            'primary' => $references['primary'] ?? null,
            'links' => $references['links'] ?? [],
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Readable subject/body per event. Currency formatting is deliberately
     * simple (plain amount + code) because the payload carries the document's
     * own currency snapshot.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private function copyFor(string $notificationEvent, array $payload): array
    {
        $number = (string) ($payload['document_number'] ?? '');
        $label = $number !== '' ? $number : '#'.($payload['document_id'] ?? '');
        $amount = isset($payload['total'])
            ? number_format((float) $payload['total'], 2).' '.trim((string) ($payload['currency_code'] ?? ''))
            : null;

        return match ($notificationEvent) {
            SalesNotificationCatalogue::QUOTATION_ACCEPTED => [
                "Quotation {$label} accepted",
                "Quotation {$label} was accepted and converted to a sales order.",
            ],
            SalesNotificationCatalogue::QUOTATION_EXPIRING => [
                "Quotation {$label} is about to expire",
                "Quotation {$label} expires on ".($payload['valid_until'] ?? 'its validity date').' and has not been accepted yet.',
            ],
            SalesNotificationCatalogue::SALES_ORDER_CONFIRMED => [
                "Sales order {$label} confirmed",
                "Sales order {$label} is confirmed and ready for fulfilment.".($amount ? " Total {$amount}." : ''),
            ],
            SalesNotificationCatalogue::DELIVERY_ORDER_COMPLETED => [
                "Delivery order {$label} completed",
                "Delivery order {$label} was completed and the goods left the warehouse.",
            ],
            SalesNotificationCatalogue::INVOICE_POSTED => [
                "Invoice {$label} posted",
                "Invoice {$label} was posted to the ledger.".($amount ? " Total {$amount}." : ''),
            ],
            SalesNotificationCatalogue::INVOICE_OVERDUE => [
                "Invoice {$label} is overdue",
                "Invoice {$label} passed its due date".(isset($payload['due_date']) ? ' ('.$payload['due_date'].')' : '')
                    .' with an outstanding balance of '.number_format((float) ($payload['balance'] ?? 0), 2).'.',
            ],
            SalesNotificationCatalogue::PAYMENT_RECEIVED => [
                "Payment {$label} received",
                'A payment of '.($amount ?? 'the recorded amount').' was received and posted to the ledger.',
            ],
            SalesNotificationCatalogue::CREDIT_NOTE_POSTED => [
                "Credit note {$label} posted",
                "Credit note {$label} was posted".($amount ? " for {$amount}" : '').' and reduced the receivable.',
            ],
            SalesNotificationCatalogue::CREDIT_LIMIT_EXCEEDED => [
                'Credit limit exceeded'.($label !== '#' ? " for {$label}" : ''),
                "Sales order {$label} was rejected by the credit check: ".($payload['reason'] ?? 'the order exceeds the available credit')
                    .'. Credit limit '.number_format((float) ($payload['credit_limit'] ?? 0), 2)
                    .', current exposure '.number_format((float) ($payload['current_balance'] ?? 0), 2)
                    .', order total '.number_format((float) ($payload['order_total'] ?? 0), 2).'.',
            ],
            SalesNotificationCatalogue::EINVOICE_STATUS_CHANGED => [
                "E-invoice {$label} status changed",
                "The e-invoice for {$label} changed status.",
            ],
            default => [
                ucfirst(str_replace(['.', '_'], ' ', $notificationEvent)),
                "Update on {$label}.",
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{primary: array<string, mixed>|null, links: array<int, array<string, mixed>>}
     */
    private function payloadReferences(array $payload, string $documentType, mixed $documentId, mixed $number): array
    {
        if ($documentType === '' || $documentId === null || $documentId === '') {
            return ['primary' => null, 'links' => []];
        }

        $primary = array_filter([
            'type' => $documentType,
            'id' => $documentId,
            'number' => is_string($number) && $number !== '' ? $number : null,
        ], fn ($value) => $value !== null);

        $links = [$primary];

        if (is_numeric($payload['invoice_id'] ?? null) && $documentType !== 'invoice') {
            $links[] = ['type' => 'invoice', 'id' => (int) $payload['invoice_id']];
        }

        return ['primary' => $primary, 'links' => $links];
    }

    /**
     * Deep link for the stored payload, matching frontend
     * src/lib/notificationLinks.ts routes.
     */
    private function urlFor(string $documentType, mixed $documentId): ?string
    {
        if ($documentId === null || $documentId === '') {
            return null;
        }

        $path = match ($documentType) {
            'invoice' => "/invoices/{$documentId}",
            'quotation' => "/quotations/{$documentId}",
            'sales_order' => "/sales/orders/{$documentId}",
            'delivery_order' => "/delivery-orders/{$documentId}",
            default => null,
        };

        if ($path === null) {
            return null;
        }

        return rtrim((string) config('frontend.url', 'http://localhost:5173'), '/').$path;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function actorId(array $payload): ?int
    {
        $actorId = $payload['actor_user_id'] ?? null;

        return is_numeric($actorId) ? (int) $actorId : null;
    }
}

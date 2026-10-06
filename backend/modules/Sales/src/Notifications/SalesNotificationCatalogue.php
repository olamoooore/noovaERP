<?php

declare(strict_types=1);

namespace Modules\Sales\Notifications;

use Modules\Sales\Events\SalesEventCatalogue;

/**
 * SalesNotificationCatalogue — the notification vocabulary for the Sales
 * domain (issue #903, PC9).
 *
 * One place maps:
 *   - each #901 domain event (e.g. `invoice.posted.v1`) to the notification
 *     event stored on `notification_logs` / `platform_notification_preferences`
 *     (e.g. `invoice.posted`);
 *   - each notification event to its default audience(s) and default delivery
 *     channels.
 *
 * The audience vocabulary (resolved by SalesNotificationRecipientResolver):
 *   - creator              the document's `created_by` / payment `received_by`
 *   - salesperson          the document's free-text `salesperson`, matched to a user
 *   - customer_salesperson the assigned salesperson on the customer record
 *   - accounts             tenant members with a finance role (Owner/Admin/Accountant)
 *   - fulfilment           tenant members holding `fulfillment.write` (no
 *                          warehouse-membership model exists — disclosed in #903)
 *
 * Delivery channels are `in_app` (the notification_logs row the bell renders)
 * and `mail` (the email leg of the same row). A `whatsapp` channel exists in
 * the Communication module but its Meta/Twilio drivers are unimplemented stubs
 * that fail closed, so it is deliberately not a delivered channel here.
 */
final class SalesNotificationCatalogue
{
    public const QUOTATION_ACCEPTED = 'quotation.accepted';

    public const QUOTATION_EXPIRING = 'quotation.expiring';

    public const SALES_ORDER_CONFIRMED = 'sales_order.confirmed';

    public const DELIVERY_ORDER_COMPLETED = 'delivery_order.completed';

    public const INVOICE_POSTED = 'invoice.posted';

    public const INVOICE_OVERDUE = 'invoice.overdue';

    public const PAYMENT_RECEIVED = 'payment.received';

    public const CREDIT_NOTE_POSTED = 'credit_note.posted';

    public const CREDIT_LIMIT_EXCEEDED = 'sales.credit_limit_exceeded';

    /**
     * The pre-existing #666 e-invoice status notification. It is dispatched by
     * ComplianceService (not the #901 bus) and only carries the mail channel,
     * so it is listed in the preference surface with that single channel.
     */
    public const EINVOICE_STATUS_CHANGED = 'einvoice.status_changed';

    public const AUDIENCE_CREATOR = 'creator';

    public const AUDIENCE_SALESPERSON = 'salesperson';

    public const AUDIENCE_CUSTOMER_SALESPERSON = 'customer_salesperson';

    public const AUDIENCE_ACCOUNTS = 'accounts';

    public const AUDIENCE_FULFILMENT = 'fulfilment';

    /** Channels the platform can actually deliver today. */
    public const DELIVERABLE_CHANNELS = ['in_app', 'mail'];

    /**
     * Issue #907: WhatsApp is a stored preference only — its drivers are
     * unimplemented stubs — but it is a column in the matrix so the user's
     * choice is captured now and honoured when a transport ships.
     */
    public const CHANNEL_WHATSAPP = 'whatsapp';

    /** The columns of the #907 preference matrix, in display order. */
    public const MATRIX_CHANNELS = ['in_app', 'mail', self::CHANNEL_WHATSAPP];

    /**
     * Document types a document-scoped mute may reference. Matches the
     * `document_type` values the Sales dispatcher stores on notification_logs.
     */
    public const DOCUMENT_TYPES = [
        'invoice',
        'quotation',
        'sales_order',
        'delivery_order',
        'payment',
        'credit_note',
    ];

    /**
     * Domain event name (SalesEventCatalogue) → notification event name.
     */
    public const DOMAIN_EVENT_MAP = [
        SalesEventCatalogue::QUOTATION_ACCEPTED => self::QUOTATION_ACCEPTED,
        SalesEventCatalogue::SALES_ORDER_CONFIRMED => self::SALES_ORDER_CONFIRMED,
        SalesEventCatalogue::DELIVERY_ORDER_COMPLETED => self::DELIVERY_ORDER_COMPLETED,
        SalesEventCatalogue::INVOICE_POSTED => self::INVOICE_POSTED,
        SalesEventCatalogue::INVOICE_OVERDUE => self::INVOICE_OVERDUE,
        SalesEventCatalogue::PAYMENT_RECEIVED => self::PAYMENT_RECEIVED,
        SalesEventCatalogue::CREDIT_NOTE_POSTED => self::CREDIT_NOTE_POSTED,
    ];

    /**
     * Notification event → {label, audiences, channels}.
     *
     * `channels` are the defaults for a user with no explicit preference row.
     * Every event is muteable: a `platform_notification_preferences` row for
     * (user, event, channel) with `mail_enabled = false` removes that channel.
     */
    private const EVENTS = [
        self::QUOTATION_ACCEPTED => [
            'label' => 'Quotation accepted',
            'audiences' => [
                self::AUDIENCE_CREATOR,
                self::AUDIENCE_SALESPERSON,
                self::AUDIENCE_CUSTOMER_SALESPERSON,
            ],
            'channels' => ['in_app', 'mail'],
        ],
        self::QUOTATION_EXPIRING => [
            'label' => 'Quotation about to expire',
            'audiences' => [
                self::AUDIENCE_CREATOR,
                self::AUDIENCE_SALESPERSON,
                self::AUDIENCE_CUSTOMER_SALESPERSON,
            ],
            'channels' => ['in_app', 'mail'],
        ],
        self::SALES_ORDER_CONFIRMED => [
            'label' => 'Sales order confirmed (warehouse)',
            'audiences' => [self::AUDIENCE_FULFILMENT],
            'channels' => ['in_app', 'mail'],
        ],
        self::DELIVERY_ORDER_COMPLETED => [
            'label' => 'Delivery order completed',
            'audiences' => [
                self::AUDIENCE_SALESPERSON,
                self::AUDIENCE_CUSTOMER_SALESPERSON,
                self::AUDIENCE_CREATOR,
            ],
            'channels' => ['in_app', 'mail'],
        ],
        self::INVOICE_POSTED => [
            'label' => 'Invoice posted (accounts)',
            'audiences' => [self::AUDIENCE_ACCOUNTS],
            'channels' => ['in_app', 'mail'],
        ],
        self::INVOICE_OVERDUE => [
            'label' => 'Invoice overdue',
            'audiences' => [
                self::AUDIENCE_CUSTOMER_SALESPERSON,
                self::AUDIENCE_SALESPERSON,
                self::AUDIENCE_ACCOUNTS,
            ],
            'channels' => ['in_app', 'mail'],
        ],
        self::PAYMENT_RECEIVED => [
            'label' => 'Payment received',
            'audiences' => [
                self::AUDIENCE_CUSTOMER_SALESPERSON,
                self::AUDIENCE_SALESPERSON,
                self::AUDIENCE_ACCOUNTS,
            ],
            'channels' => ['in_app', 'mail'],
        ],
        self::CREDIT_NOTE_POSTED => [
            'label' => 'Credit note posted',
            'audiences' => [
                self::AUDIENCE_CREATOR,
                self::AUDIENCE_SALESPERSON,
                self::AUDIENCE_CUSTOMER_SALESPERSON,
            ],
            'channels' => ['in_app', 'mail'],
        ],
        self::CREDIT_LIMIT_EXCEEDED => [
            'label' => 'Credit limit exceeded',
            'audiences' => [
                self::AUDIENCE_CREATOR,
                self::AUDIENCE_SALESPERSON,
                self::AUDIENCE_CUSTOMER_SALESPERSON,
                self::AUDIENCE_ACCOUNTS,
            ],
            'channels' => ['in_app', 'mail'],
        ],
        self::EINVOICE_STATUS_CHANGED => [
            'label' => 'E-invoice rejected / status changed',
            'audiences' => [],
            // Dispatched by the pre-existing #666 path, which honours the
            // mail-channel mute row only.
            'channels' => ['mail'],
        ],
    ];

    public static function has(string $notificationEvent): bool
    {
        return isset(self::EVENTS[$notificationEvent]);
    }

    public static function notificationEventForDomainEvent(string $domainEvent): ?string
    {
        return self::DOMAIN_EVENT_MAP[$domainEvent] ?? null;
    }

    /**
     * @return string[]
     */
    public static function domainEvents(): array
    {
        return array_keys(self::DOMAIN_EVENT_MAP);
    }

    /**
     * @return string[]
     */
    public static function audiences(string $notificationEvent): array
    {
        return self::EVENTS[$notificationEvent]['audiences'] ?? [];
    }

    /**
     * @return string[]
     */
    public static function defaultChannels(string $notificationEvent): array
    {
        return self::EVENTS[$notificationEvent]['channels'] ?? [];
    }

    /**
     * The event list the preference API/UI renders.
     *
     * @return array<string, array{label: string, channels: string[]}>
     */
    public static function preferenceEvents(): array
    {
        $events = [];

        foreach (self::EVENTS as $event => $meta) {
            $events[$event] = [
                'label' => $meta['label'],
                'channels' => array_values($meta['channels']),
            ];
        }

        return $events;
    }
}

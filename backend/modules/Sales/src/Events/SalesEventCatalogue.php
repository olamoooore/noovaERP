<?php

namespace Modules\Sales\Events;

/**
 * SalesEventCatalogue — the versioned business-event vocabulary for the Sales
 * domain (issue #901, PC7).
 *
 * Every sales event emitted onto the platform Domain Event Bus is declared
 * here once: its stable `event_name` (including the schema major version
 * suffix `.v1`), its version metadata and its payload schema. The
 * SalesDomainEventEmitter maps workflow transitions to these names and builds
 * the payload; consumers subscribe by name.
 *
 * Payload envelope (present on every event):
 *   - event_id        uuid   stable id of this event; consumer idempotency key
 *   - event_name      string this event's name (e.g. invoice.posted.v1)
 *   - version         string payload schema version (1.0.0)
 *   - tenant_id       uuid   tenant that produced the event
 *   - source_module   string always 'sales'
 *   - occurred_at     string ISO-8601 time the transition happened
 *   - actor_user_id   int|null user who triggered the transition
 *   - correlation_id  uuid   tracing id shared with the outbox record
 *   - document_type   string invoice|quotation|sales_order|delivery_order|
 *                            credit_note|payment
 *   - document_id     int    primary key of the source document
 *   - document_number string|null human-readable document number
 *   - legal_entity_id int|null owning legal entity (where the document has one)
 *   - customer_id     int|null customer the document belongs to
 *   - status_before   string|null workflow status before the transition
 *   - status_after    string|null workflow status after the transition
 *   - total           float  document total in document currency
 *   - currency_code   string|null document currency
 *   - base_currency_code string|null tenant base currency
 *   - exchange_rate   float|null document-to-base rate snapshot
 *
 * Payment events additionally carry payment `method`, `invoice_id` and (for
 * refunds) `original_payment_id`/`transaction_id`. Credit notes carry
 * `invoice_id`.
 */
final class SalesEventCatalogue
{
    public const EVENT_VERSION = '1.0.0';

    public const QUOTATION_SENT = 'quotation.sent.v1';

    public const QUOTATION_ACCEPTED = 'quotation.accepted.v1';

    public const QUOTATION_EXPIRED = 'quotation.expired.v1';

    public const SALES_ORDER_CONFIRMED = 'sales_order.confirmed.v1';

    public const SALES_ORDER_CANCELLED = 'sales_order.cancelled.v1';

    public const DELIVERY_ORDER_COMPLETED = 'delivery_order.completed.v1';

    public const INVOICE_POSTED = 'invoice.posted.v1';

    public const INVOICE_SENT = 'invoice.sent.v1';

    public const INVOICE_PAID = 'invoice.paid.v1';

    public const INVOICE_OVERDUE = 'invoice.overdue.v1';

    public const INVOICE_CANCELLED = 'invoice.cancelled.v1';

    public const CREDIT_NOTE_POSTED = 'credit_note.posted.v1';

    public const PAYMENT_RECEIVED = 'payment.received.v1';

    public const PAYMENT_REFUNDED = 'payment.refunded.v1';

    /**
     * The workflow transition (document type + action) that produces each
     * event. Actions not listed produce no domain event (e.g. settlement
     * `sync_partial` and `reset_to_draft` are internal state repairs, not
     * business events).
     */
    private const TRANSITION_MAP = [
        'quotation.send' => self::QUOTATION_SENT,
        'quotation.confirm' => self::QUOTATION_ACCEPTED,
        'quotation.expire' => self::QUOTATION_EXPIRED,
        'sales_order.confirm' => self::SALES_ORDER_CONFIRMED,
        'sales_order.cancel' => self::SALES_ORDER_CANCELLED,
        'delivery_order.complete' => self::DELIVERY_ORDER_COMPLETED,
        'invoice.post' => self::INVOICE_POSTED,
        'invoice.sync_sent' => self::INVOICE_SENT,
        'invoice.sync_paid' => self::INVOICE_PAID,
        'invoice.sync_overdue' => self::INVOICE_OVERDUE,
        'invoice.cancel' => self::INVOICE_CANCELLED,
    ];

    /**
     * Translate a workflow transition (document type + action) into its
     * catalogue event name, or null when the transition has no business event.
     */
    public static function eventForTransition(string $documentType, string $action): ?string
    {
        return self::TRANSITION_MAP[$documentType.'.'.$action] ?? null;
    }

    /**
     * The whole catalogue: event name → {version, description, payload schema}.
     *
     * @return array<string, array{version: string, description: string, payload: array<string, string>}>
     */
    public static function all(): array
    {
        $envelope = [
            'event_id' => 'uuid',
            'event_name' => 'string',
            'version' => 'string',
            'tenant_id' => 'uuid',
            'source_module' => 'string',
            'occurred_at' => 'ISO-8601 string',
            'actor_user_id' => 'int|null',
            'correlation_id' => 'uuid',
            'document_type' => 'string',
            'document_id' => 'int',
            'document_number' => 'string|null',
            'legal_entity_id' => 'int|null',
            'customer_id' => 'int|null',
            'status_before' => 'string|null',
            'status_after' => 'string|null',
            'total' => 'decimal:2',
            'currency_code' => 'string|null',
            'base_currency_code' => 'string|null',
            'exchange_rate' => 'decimal:6|null',
        ];

        $payment = $envelope + [
            'invoice_id' => 'int|null',
            'method' => 'string',
        ];

        return [
            self::QUOTATION_SENT => [
                'version' => self::EVENT_VERSION,
                'description' => 'A quotation was sent to the customer (draft → sent).',
                'payload' => $envelope,
            ],
            self::QUOTATION_ACCEPTED => [
                'version' => self::EVENT_VERSION,
                'description' => 'A quotation was accepted/confirmed and converted to a sales order.',
                'payload' => $envelope + ['sales_order_id' => 'int|null'],
            ],
            self::QUOTATION_EXPIRED => [
                'version' => self::EVENT_VERSION,
                'description' => 'A quotation passed its validity date without acceptance.',
                'payload' => $envelope + ['valid_until' => 'date|null'],
            ],
            self::SALES_ORDER_CONFIRMED => [
                'version' => self::EVENT_VERSION,
                'description' => 'A sales order was confirmed (draft → confirmed).',
                'payload' => $envelope,
            ],
            self::SALES_ORDER_CANCELLED => [
                'version' => self::EVENT_VERSION,
                'description' => 'A sales order was cancelled before invoicing.',
                'payload' => $envelope + ['reason' => 'string|null'],
            ],
            self::DELIVERY_ORDER_COMPLETED => [
                'version' => self::EVENT_VERSION,
                'description' => 'A delivery order completed and the goods left the warehouse.',
                'payload' => $envelope + ['sales_order_id' => 'int|null', 'warehouse_id' => 'int|null'],
            ],
            self::INVOICE_POSTED => [
                'version' => self::EVENT_VERSION,
                'description' => 'A customer invoice was posted to the ledger.',
                'payload' => $envelope + ['journal_entry_id' => 'int|null'],
            ],
            self::INVOICE_SENT => [
                'version' => self::EVENT_VERSION,
                'description' => 'A posted invoice became due (settlement state sent).',
                'payload' => $envelope,
            ],
            self::INVOICE_PAID => [
                'version' => self::EVENT_VERSION,
                'description' => 'An invoice was settled in full.',
                'payload' => $envelope + ['paid_amount' => 'decimal:2', 'balance' => 'decimal:2'],
            ],
            self::INVOICE_OVERDUE => [
                'version' => self::EVENT_VERSION,
                'description' => 'An invoice passed its due date with a balance outstanding.',
                'payload' => $envelope + ['due_date' => 'date|null', 'balance' => 'decimal:2'],
            ],
            self::INVOICE_CANCELLED => [
                'version' => self::EVENT_VERSION,
                'description' => 'A posted invoice was cancelled with its ledger reversal.',
                'payload' => $envelope + ['reason' => 'string|null'],
            ],
            self::CREDIT_NOTE_POSTED => [
                'version' => self::EVENT_VERSION,
                'description' => 'A customer credit note was posted, reducing the receivable.',
                'payload' => $envelope + ['invoice_id' => 'int|null', 'journal_entry_id' => 'int|null'],
            ],
            self::PAYMENT_RECEIVED => [
                'version' => self::EVENT_VERSION,
                'description' => 'A customer payment was posted to the ledger.',
                'payload' => $payment + ['journal_entry_id' => 'int|null'],
            ],
            self::PAYMENT_REFUNDED => [
                'version' => self::EVENT_VERSION,
                'description' => 'A posted customer payment was refunded (negative correction payment).',
                'payload' => $payment + ['original_payment_id' => 'int|null', 'journal_entry_id' => 'int|null'],
            ],
        ];
    }

    /**
     * Event names for subscription registration.
     *
     * @return string[]
     */
    public static function eventNames(): array
    {
        return array_keys(self::all());
    }
}

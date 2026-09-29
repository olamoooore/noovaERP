<?php

declare(strict_types=1);

namespace Modules\Communication\Support;

use InvalidArgumentException;

/**
 * Issue #82: communication subtypes were free-form strings — a typo such as
 * `invoice_sent` instead of `invoice.sent` silently failed subscriber matching
 * (and preferences could never reliably mute an inconsistently named event).
 *
 * This registry is the single source of truth: canonical codes are declared
 * here, wildcard prefixes cover dynamic families (`digest.`), and modules may
 * register additional codes at boot. CommunicationEngine validates every
 * inbound subtype against it and fails loudly on unknown values.
 */
final class EventSubtypeRegistry
{
    // ── Canonical business-event subtypes ─────────────────────────────
    public const SYSTEM_LOG = 'system.log';

    public const INTERNAL_NOTE = 'internal.note';

    public const RECORD_COMMENT = 'record.comment';

    public const INVOICE_SENT = 'invoice.sent';

    public const INVOICE_PAID = 'invoice.paid';

    public const INVOICE_OVERDUE = 'invoice.overdue';

    public const QUOTATION_SENT = 'quotation.sent';

    public const ORDER_CONFIRMED = 'order.confirmed';

    public const ORDER_CANCELLED = 'order.cancelled';

    public const PAYMENT_RECEIVED = 'payment.received';

    public const DELIVERY_COMPLETED = 'delivery.completed';

    public const STOCK_LOW = 'stock.low';

    public const USER_WELCOME = 'user.welcome';

    private const EXACT = [
        self::SYSTEM_LOG,
        self::INTERNAL_NOTE,
        self::RECORD_COMMENT,
        self::INVOICE_SENT,
        self::INVOICE_PAID,
        self::INVOICE_OVERDUE,
        self::QUOTATION_SENT,
        self::ORDER_CONFIRMED,
        self::ORDER_CANCELLED,
        self::PAYMENT_RECEIVED,
        self::DELIVERY_COMPLETED,
        self::STOCK_LOW,
        self::USER_WELCOME,
    ];

    /** Dynamic families whose suffix is caller-defined (e.g. digest.weekly). */
    private const PREFIXES = [
        'digest.',
    ];

    /** Codes registered at runtime by other modules. */
    private static array $registered = [];

    public static function register(string ...$subtypes): void
    {
        foreach ($subtypes as $subtype) {
            self::$registered[$subtype] = true;
        }
    }

    /**
     * @return string[]
     */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(self::EXACT, array_keys(self::$registered))));
    }

    /**
     * @return string[]
     */
    public static function prefixes(): array
    {
        return self::PREFIXES;
    }

    public static function isRegistered(string $subtype): bool
    {
        if (isset(self::$registered[$subtype]) || in_array($subtype, self::EXACT, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($subtype, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fail loudly on an unknown subtype — a typo must never silently skip
     * notification delivery.
     *
     * @throws InvalidArgumentException
     */
    public static function assertValid(string $subtype): void
    {
        if (! self::isRegistered($subtype)) {
            throw new InvalidArgumentException(sprintf(
                "Unknown communication subtype '%s'. Register it via EventSubtypeRegistry::register() or use a canonical code (known: %s; prefixes: %s).",
                $subtype,
                implode(', ', self::all()),
                implode(', ', self::PREFIXES),
            ));
        }
    }
}

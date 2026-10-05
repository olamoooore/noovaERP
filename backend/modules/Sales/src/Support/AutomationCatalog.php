<?php

namespace Modules\Sales\Support;

use Modules\Sales\Events\SalesEventCatalogue;

/**
 * AutomationCatalog — the single whitelist for #913 automation rules.
 *
 * Conditions and actions are evaluated and validated against these lists
 * (never against arbitrary user input). The frontend event/condition pickers
 * are served from here so the UI and the engine cannot drift.
 *
 * Virtual fields are resolved by the engine from context rather than taken
 * straight off the event payload:
 *   - customer_group: the triggering customer's `customer_group`
 *   - days_overdue:   whole days between the event's due date and its
 *                     occurrence (>= 0), e.g. 30 for "30 days overdue"
 */
final class AutomationCatalog
{
    public const OPERATOR_EQ = 'eq';

    public const OPERATOR_NEQ = 'neq';

    public const OPERATOR_GT = 'gt';

    public const OPERATOR_GTE = 'gte';

    public const OPERATOR_LT = 'lt';

    public const OPERATOR_LTE = 'lte';

    public const OPERATOR_IN = 'in';

    public const OPERATOR_NOT_IN = 'not_in';

    public const OPERATOR_CONTAINS = 'contains';

    public const OPERATOR_NOT_CONTAINS = 'not_contains';

    public const OPERATOR_IS_NULL = 'is_null';

    public const OPERATOR_IS_NOT_NULL = 'is_not_null';

    /** Comparison operators a condition may use (whitelist). */
    public const OPERATORS = [
        self::OPERATOR_EQ,
        self::OPERATOR_NEQ,
        self::OPERATOR_GT,
        self::OPERATOR_GTE,
        self::OPERATOR_LT,
        self::OPERATOR_LTE,
        self::OPERATOR_IN,
        self::OPERATOR_NOT_IN,
        self::OPERATOR_CONTAINS,
        self::OPERATOR_NOT_CONTAINS,
        self::OPERATOR_IS_NULL,
        self::OPERATOR_IS_NOT_NULL,
    ];

    /** Operators whose operands must both be numeric. */
    public const NUMERIC_OPERATORS = [
        self::OPERATOR_GT,
        self::OPERATOR_GTE,
        self::OPERATOR_LT,
        self::OPERATOR_LTE,
    ];

    /** Operators that take no value. */
    public const VALUELESS_OPERATORS = [
        self::OPERATOR_IS_NULL,
        self::OPERATOR_IS_NOT_NULL,
    ];

    public const ACTION_NOTIFY = 'notify';

    public const ACTION_WHATSAPP_CUSTOMER = 'whatsapp_customer';

    public const ACTION_CREATE_TASK = 'create_task';

    public const ACTION_HOLD_CUSTOMER = 'hold_customer';

    public const ACTION_WEBHOOK = 'webhook';

    /** Action types a rule may execute (whitelist). */
    public const ACTION_TYPES = [
        self::ACTION_NOTIFY,
        self::ACTION_WHATSAPP_CUSTOMER,
        self::ACTION_CREATE_TASK,
        self::ACTION_HOLD_CUSTOMER,
        self::ACTION_WEBHOOK,
    ];

    /** Fields resolved from context instead of the event payload. */
    public const VIRTUAL_FIELDS = [
        'customer_group' => 'Customer group',
        'days_overdue' => 'Days overdue',
    ];

    /**
     * Condition fields allowed for a specific catalogue event: the payload
     * keys documented in SalesEventCatalogue plus the virtual fields.
     *
     * @return string[]
     */
    public static function conditionFieldsFor(string $eventName): array
    {
        $catalogue = SalesEventCatalogue::all();
        $payloadFields = array_keys($catalogue[$eventName]['payload'] ?? []);

        return array_values(array_unique(array_merge($payloadFields, array_keys(self::VIRTUAL_FIELDS))));
    }

    public static function supportsEvent(string $eventName): bool
    {
        return array_key_exists($eventName, SalesEventCatalogue::all());
    }

    /**
     * The catalogue + builder metadata for the frontend.
     *
     * @return array{events: array<int, array<string, mixed>>, operators: string[], action_types: string[], virtual_fields: array<string, string>}
     */
    public static function toArray(): array
    {
        $events = [];

        foreach (SalesEventCatalogue::all() as $name => $definition) {
            $events[] = [
                'event_name' => $name,
                'version' => $definition['version'],
                'description' => $definition['description'],
                'payload_fields' => $definition['payload'],
                'condition_fields' => self::conditionFieldsFor($name),
            ];
        }

        return [
            'events' => $events,
            'operators' => self::OPERATORS,
            'action_types' => self::ACTION_TYPES,
            'virtual_fields' => self::VIRTUAL_FIELDS,
        ];
    }
}

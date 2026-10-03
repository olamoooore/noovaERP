<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-configurable sales approval rule (#649).
 *
 * Complements the amount threshold: the threshold is a single number per
 * document type, while a rule can express a condition over a metric the
 * document actually carries — its base-currency amount, its discount
 * percentage, its margin, or the customer's prospective credit exposure.
 *
 * Evaluation lives in App\Services\SalesApprovalGate; this model owns the
 * vocabulary and the operator comparison. A rule that compares on a metric
 * the document cannot supply is treated as matched (fail safe) by the gate.
 */
class SalesApprovalRule extends Model
{
    public const DOCUMENT_TYPE_QUOTATION = 'quotation';

    public const DOCUMENT_TYPE_SALES_ORDER = 'sales_order';

    public const DOCUMENT_TYPE_INVOICE = 'invoice';

    public const DOCUMENT_TYPE_DELIVERY_ORDER = 'delivery_order';

    /** Applies to every sales document type. */
    public const DOCUMENT_TYPE_ANY = '*';

    public const CONDITION_AMOUNT = 'amount';

    public const CONDITION_DISCOUNT_PCT = 'discount_pct';

    public const CONDITION_MARGIN = 'margin';

    public const CONDITION_CREDIT_EXPOSURE = 'credit_exposure';

    /**
     * @var string[]
     */
    public const OPERATORS = ['>=', '>', '<=', '<'];

    protected $fillable = [
        'document_type',
        'condition_kind',
        'operator',
        'value',
        'active',
    ];

    protected $casts = [
        'value' => 'decimal:4',
        'active' => 'boolean',
    ];

    /**
     * Whether the resolved metric satisfies this rule's configured operator
     * and value. An unknown operator cannot be evaluated and is treated as
     * matched (fail safe) so a corrupt row can never silently disable a gate.
     */
    public function matches(float $metric): bool
    {
        $value = (float) $this->value;

        return match ($this->operator) {
            '>=' => $metric >= $value,
            '>' => $metric > $value,
            '<=' => $metric <= $value,
            '<' => $metric < $value,
            default => true,
        };
    }
}

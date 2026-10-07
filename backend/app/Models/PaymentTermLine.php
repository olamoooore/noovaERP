<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #324: one instalment of a master payment term — a percentage of the
 * invoice total (or the remaining balance), due a number of days after the
 * invoice date or after the end of the invoice month.
 */
class PaymentTermLine extends Model
{
    public const VALUE_PERCENT = 'percent';

    public const VALUE_BALANCE = 'balance';

    public const VALUE_TYPES = [self::VALUE_PERCENT, self::VALUE_BALANCE];

    public const DELAY_TYPES = ['net_days', 'end_of_month'];

    protected $fillable = [
        'payment_term_id',
        'sequence',
        'value_type',
        'value',
        'days',
        'delay_type',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'value' => 'decimal:4',
        'days' => 'integer',
    ];

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }
}

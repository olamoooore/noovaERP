<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #882 (SA9) — the per-line deferred-revenue subledger.
 *
 * `amount` is the base-currency amount credited to the deferred-revenue
 * account when the invoice posted. The recognition job accumulates
 * `recognized_amount` as it releases the earned portion to `income_account`
 * through PostingEngine; credit notes / cancellations accumulate
 * `reversed_amount`. The unearned balance is therefore
 * `amount - recognized_amount - reversed_amount`.
 */
class DeferredRevenueSchedule extends Model
{
    protected $fillable = [
        'invoice_id',
        'invoice_line_id',
        'customer_id',
        'legal_entity_id',
        'deferred_account_id',
        'income_account_id',
        'amount',
        'recognized_amount',
        'reversed_amount',
        'start_date',
        'end_date',
        'department_id',
        'project_id',
        'location_id',
        'status',
        'last_recognized_on',
        'last_journal_entry_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'recognized_amount' => 'decimal:2',
        'reversed_amount' => 'decimal:2',
        'start_date' => 'date',
        'end_date' => 'date',
        'last_recognized_on' => 'date',
    ];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_REVERSED = 'reversed';

    public const STATUS_CANCELLED = 'cancelled';

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deferredAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'deferred_account_id');
    }

    public function incomeAccount(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'income_account_id');
    }

    /** The still-unearned base-currency balance. */
    public function remainingAmount(): float
    {
        return round(
            (float) $this->amount - (float) $this->recognized_amount - (float) $this->reversed_amount,
            2,
        );
    }
}

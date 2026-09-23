<?php

namespace Modules\Sales\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #151: recurrence definition for a recurring sales order.
 */
class SalesOrderRecurrence extends Model
{
    protected $table = 'sales_order_recurrences';

    public const INTERVALS = ['monthly', 'quarterly', 'annual'];

    protected $fillable = [
        'tenant_id',
        'sales_order_id',
        'customer_id',
        'interval',
        'start_date',
        'end_date',
        'max_cycles',
        'cycles_generated',
        'next_run_date',
        'status',
        'last_generated_at',
        'generated_order_ids',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'next_run_date' => 'date',
        'last_generated_at' => 'datetime',
        'generated_order_ids' => 'array',
        'cycles_generated' => 'integer',
        'max_cycles' => 'integer',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function isDueOn(Carbon $date): bool
    {
        return $this->status === 'active' && $this->next_run_date->lte($date);
    }

    /**
     * Next cycle date for this interval.
     */
    public function advanceNextRunDate(): Carbon
    {
        $next = Carbon::parse($this->next_run_date);

        // Issue #151 (hostile re-review): plain addMonth()/addYear() overflow —
        // a 31 Jan start drifts to 3 Mar, then 3 Apr, and so on. The
        // NoOverflow variants clamp to the last valid day of the target month
        // (31 Jan → 28/29 Feb → 28/29 Mar) so billing dates stay anchored.
        return match ($this->interval) {
            'monthly' => $next->addMonthsNoOverflow(1),
            'quarterly' => $next->addMonthsNoOverflow(3),
            'annual' => $next->addYearsNoOverflow(1),
            default => $next->addMonthsNoOverflow(1),
        };
    }
}

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

        return match ($this->interval) {
            'monthly' => $next->addMonth(),
            'quarterly' => $next->addMonths(3),
            'annual' => $next->addYear(),
            default => $next->addMonth(),
        };
    }
}

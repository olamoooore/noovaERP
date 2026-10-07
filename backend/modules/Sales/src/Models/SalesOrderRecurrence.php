<?php

namespace Modules\Sales\Models;

use App\Platform\Workflow\Contracts\WorkflowSubject;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Issue #151: recurrence definition for a recurring sales order.
 */
class SalesOrderRecurrence extends Model implements WorkflowSubject
{
    protected $table = 'sales_order_recurrences';

    public const INTERVALS = ['monthly', 'quarterly', 'annual'];

    /** Issue #330: what each cycle generates. */
    public const DOCUMENT_ORDER = 'order';

    public const DOCUMENT_INVOICE = 'invoice';

    public const DOCUMENT_TYPES = [self::DOCUMENT_ORDER, self::DOCUMENT_INVOICE];

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
        // Issue #330: invoice generation + auto-post / auto-send.
        'document_type',
        'auto_post',
        'auto_send',
        'generated_invoice_ids',
        'last_error',
        'last_error_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'next_run_date' => 'date',
        'last_generated_at' => 'datetime',
        'generated_order_ids' => 'array',
        'generated_invoice_ids' => 'array',
        'auto_post' => 'boolean',
        'auto_send' => 'boolean',
        'last_error_at' => 'datetime',
        'cycles_generated' => 'integer',
        'max_cycles' => 'integer',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function generatesInvoices(): bool
    {
        return $this->document_type === self::DOCUMENT_INVOICE;
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

    // ─── WorkflowEngine subject (#900, PC6) ───────────────────────

    public function workflowEntityType(): string
    {
        return 'sales_order_recurrence';
    }

    public function workflowEntityId(): string
    {
        return (string) $this->getKey();
    }

    public function workflowCurrentStatus(): string
    {
        return (string) ($this->status ?? '');
    }

    public function workflowContext(): array
    {
        return [
            'status' => $this->status,
            'sales_order_id' => $this->sales_order_id,
        ];
    }

    public function applyWorkflowStatus(string $status, array $context = []): void
    {
        $this->forceFill(['status' => $status])->save();
    }
}

<?php

namespace Modules\Sales\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderRecurrence;

/**
 * Issue #151: recurring/subscription sales. A recurrence on a sales order
 * generates the next order on each billing-cycle boundary (reusing the
 * canonical SalesOrderService::createOrder() path and cloning the source
 * order's lines), with active/paused/cancelled lifecycle and cycle caps.
 */
class RecurringSalesService
{
    public function __construct(
        private readonly SalesOrderService $salesOrderService,
    ) {}

    /**
     * @param  array{interval: string, start_date: string, end_date?: ?string, max_cycles?: ?int}  $definition
     */
    public function createFromOrder(SalesOrder $order, array $definition): SalesOrderRecurrence
    {
        if (! in_array($definition['interval'], SalesOrderRecurrence::INTERVALS, true)) {
            throw new \InvalidArgumentException('Interval must be monthly, quarterly or annual.');
        }

        $start = Carbon::parse($definition['start_date']);

        return SalesOrderRecurrence::create([
            'tenant_id' => $order->tenant_id ?? (function_exists('tenant') && tenant() ? tenant()->id : null),
            'sales_order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'interval' => $definition['interval'],
            'start_date' => $start->toDateString(),
            'end_date' => isset($definition['end_date']) ? Carbon::parse($definition['end_date'])->toDateString() : null,
            'max_cycles' => $definition['max_cycles'] ?? null,
            'cycles_generated' => 0,
            'next_run_date' => $start->toDateString(),
            'status' => 'active',
            'created_by' => $definition['created_by'] ?? null,
        ]);
    }

    /**
     * Generate the next order for every recurrence due on/before the date.
     *
     * @return array<int, SalesOrder> Generated orders
     */
    public function generateDue(?Carbon $asOf = null): array
    {
        $asOf ??= now();

        $generated = [];

        $due = SalesOrderRecurrence::query()
            ->with('salesOrder.lines')
            ->where('status', 'active')
            ->where('next_run_date', '<=', $asOf->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($due as $recurrence) {
            $order = $this->generateOne($recurrence, $asOf);

            if ($order) {
                $generated[] = $order;
            }
        }

        return $generated;
    }

    /**
     * Generate the next order for a single recurrence (idempotent per cycle).
     */
    public function generateOne(SalesOrderRecurrence $recurrence, ?Carbon $asOf = null): ?SalesOrder
    {
        $asOf ??= now();

        return DB::transaction(function () use ($recurrence, $asOf) {
            $recurrence = SalesOrderRecurrence::whereKey($recurrence->id)->lockForUpdate()->first();

            if (! $recurrence || ! $recurrence->isDueOn($asOf)) {
                return null;
            }

            $source = $recurrence->salesOrder()->with('lines.product')->first();

            if (! $source) {
                $recurrence->update(['status' => 'cancelled']);

                return null;
            }

            $order = $this->salesOrderService->createOrder([
                'customer_id' => $recurrence->customer_id,
                'order_date' => $asOf->toDateString(),
                'currency' => $source->currency,
                'notes' => "Recurring order (cycle ".($recurrence->cycles_generated + 1).") from {$source->sales_order_number}",
                // Issue #151 (hostile re-review): the generated order must
                // reproduce the source lines exactly — pricing and tax fields
                // (discount_type, discount_value, tax_rate, tax_inclusive,
                // variant_id, line_type, model_number, sku, product_notes,
                // list_price, income_account_id, invoicing_policy) were being
                // dropped, so every cycle was repriced without its discount or
                // tax.
                'lines' => $source->lines->map(fn ($line) => [
                    'line_type' => $line->line_type,
                    'sequence' => $line->sequence,
                    'product_id' => $line->product_id,
                    'model_number' => $line->model_number,
                    'variant_id' => $line->variant_id,
                    'description' => $line->description,
                    'product_notes' => $line->product_notes,
                    'sku' => $line->sku,
                    'quantity' => (float) $line->quantity,
                    'unit' => $line->unit,
                    'unit_price' => (float) $line->unit_price,
                    'list_price' => $line->list_price,
                    'discount_type' => $line->discount_type,
                    'discount_value' => $line->discount_value,
                    'discount_amount' => $line->discount_amount,
                    'tax_rate' => $line->tax_rate,
                    'tax_inclusive' => (bool) $line->tax_inclusive,
                    'income_account_id' => $line->income_account_id,
                    'invoicing_policy' => $line->invoicing_policy,
                ])->values()->all(),
            ]);

            $cycles = $recurrence->cycles_generated + 1;
            $nextRun = $recurrence->advanceNextRunDate();

            $finished = ($recurrence->max_cycles !== null && $cycles >= $recurrence->max_cycles)
                || ($recurrence->end_date !== null && $nextRun->gt($recurrence->end_date));

            $recurrence->update([
                'cycles_generated' => $cycles,
                'next_run_date' => $nextRun->toDateString(),
                'last_generated_at' => now(),
                'status' => $finished ? 'completed' : 'active',
                'generated_order_ids' => array_merge($recurrence->generated_order_ids ?? [], [$order->id]),
            ]);

            return $order;
        });
    }

    public function pause(SalesOrderRecurrence $recurrence): SalesOrderRecurrence
    {
        if ($recurrence->status !== 'active') {
            throw new \RuntimeException('Only active recurrences can be paused.');
        }

        $recurrence->update(['status' => 'paused']);

        return $recurrence->fresh();
    }

    public function resume(SalesOrderRecurrence $recurrence): SalesOrderRecurrence
    {
        if ($recurrence->status !== 'paused') {
            throw new \RuntimeException('Only paused recurrences can be resumed.');
        }

        $recurrence->update(['status' => 'active']);

        return $recurrence->fresh();
    }

    public function cancel(SalesOrderRecurrence $recurrence): SalesOrderRecurrence
    {
        if (in_array($recurrence->status, ['cancelled', 'completed'], true)) {
            throw new \RuntimeException('This recurrence is already finished.');
        }

        $recurrence->update(['status' => 'cancelled']);

        return $recurrence->fresh();
    }
}

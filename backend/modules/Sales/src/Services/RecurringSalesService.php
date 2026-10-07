<?php

namespace Modules\Sales\Services;

use App\Models\Invoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
    /**
     * Issue #330: recurrences that failed in the last generateDue() run.
     *
     * @var array<int, array{recurrence_id: int, error: string}>
     */
    private array $failures = [];

    public function __construct(
        private readonly SalesOrderService $salesOrderService,
        private readonly RecurringInvoiceService $recurringInvoiceService,
    ) {}

    /**
     * Issue #330: the recurrences that failed in the last generateDue() run
     * (also recorded on each recurrence as last_error).
     *
     * @return array<int, array{recurrence_id: int, error: string}>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * @param  array{interval: string, start_date: string, end_date?: ?string, max_cycles?: ?int}  $definition
     */
    public function createFromOrder(SalesOrder $order, array $definition): SalesOrderRecurrence
    {
        if (! in_array($definition['interval'], SalesOrderRecurrence::INTERVALS, true)) {
            throw new \InvalidArgumentException('Interval must be monthly, quarterly or annual.');
        }

        $documentType = $definition['document_type'] ?? SalesOrderRecurrence::DOCUMENT_ORDER;
        if (! in_array($documentType, SalesOrderRecurrence::DOCUMENT_TYPES, true)) {
            throw new \InvalidArgumentException('A recurrence generates either orders or invoices.');
        }
        $generatesInvoices = $documentType === SalesOrderRecurrence::DOCUMENT_INVOICE;

        $start = Carbon::parse($definition['start_date']);

        $recurrence = SalesOrderRecurrence::create([
            'tenant_id' => $order->tenant_id ?? (function_exists('tenant') && tenant() ? tenant()->id : null),
            'sales_order_id' => $order->id,
            'customer_id' => $order->customer_id,
            'interval' => $definition['interval'],
            'start_date' => $start->toDateString(),
            'end_date' => isset($definition['end_date']) ? Carbon::parse($definition['end_date'])->toDateString() : null,
            'max_cycles' => $definition['max_cycles'] ?? null,
            'cycles_generated' => 0,
            'next_run_date' => $start->toDateString(),
            'created_by' => $definition['created_by'] ?? null,
            // Issue #330: invoice generation with optional auto-post / auto-send.
            'document_type' => $documentType,
            'auto_post' => $generatesInvoices && ! empty($definition['auto_post']),
            'auto_send' => $generatesInvoices && ! empty($definition['auto_send']),
        ]);

        // #900 (PC6): initial status ('active', DB default) is recorded on the
        // workflow transition log rather than written by hand.
        app(\App\Services\SalesWorkflowService::class)->initialize($recurrence, ['source' => 'create']);

        return $recurrence;
    }

    /**
     * Generate the next document (order, or invoice for #330 schedules) for
     * every recurrence due on/before the date.
     *
     * Issue #330: one failing schedule (a credit block, a missing price) no
     * longer aborts the tenant's run — its cycle rolls back, stays due, and
     * the failure is recorded on the recurrence and returned by failures().
     *
     * @return array<int, SalesOrder|Invoice> Generated documents
     */
    public function generateDue(?Carbon $asOf = null): array
    {
        $asOf ??= now();

        $generated = [];
        $this->failures = [];

        $due = SalesOrderRecurrence::query()
            ->with('salesOrder.lines')
            ->where('status', 'active')
            ->where('next_run_date', '<=', $asOf->toDateString())
            ->orderBy('id')
            ->get();

        foreach ($due as $recurrence) {
            try {
                $document = $this->generateOne($recurrence, $asOf);
            } catch (\Throwable $e) {
                $this->recordFailure($recurrence, $e->getMessage());

                continue;
            }

            if ($document) {
                $generated[] = $document;
            }
        }

        return $generated;
    }

    /**
     * Generate the next document for a single recurrence (idempotent per
     * cycle: the cycle and the next run date advance in the same transaction
     * that creates the document, under a row lock).
     */
    public function generateOne(SalesOrderRecurrence $recurrence, ?Carbon $asOf = null): SalesOrder|Invoice|null
    {
        $asOf ??= now();

        $document = DB::transaction(function () use ($recurrence, $asOf) {
            $recurrence = SalesOrderRecurrence::whereKey($recurrence->id)->lockForUpdate()->first();

            if (! $recurrence || ! $recurrence->isDueOn($asOf)) {
                return null;
            }

            $source = $recurrence->salesOrder()->with('lines.product')->first();

            if (! $source) {
                app(\App\Services\SalesWorkflowService::class)->transition(
                    $recurrence,
                    'cancel',
                    ['reason' => 'Source sales order no longer exists'],
                    'Source sales order no longer exists',
                );

                return null;
            }

            if ($recurrence->generatesInvoices()) {
                // Issue #330: the invoice goes through the manual-invoice
                // services (tax resolver, pricing engine, backing order,
                // createInvoiceInternal, #326 credit check).
                $document = $this->recurringInvoiceService->createCycleInvoice($recurrence, $source, $asOf);
                $orderId = (int) $document->sales_order_id;
            } else {
                $document = $this->createCycleOrder($recurrence, $source, $asOf);
                $orderId = (int) $document->id;
            }

            $cycles = $recurrence->cycles_generated + 1;
            $nextRun = $recurrence->advanceNextRunDate();

            $finished = ($recurrence->max_cycles !== null && $cycles >= $recurrence->max_cycles)
                || ($recurrence->end_date !== null && $nextRun->gt($recurrence->end_date));

            $recurrence->update(array_filter([
                'cycles_generated' => $cycles,
                'next_run_date' => $nextRun->toDateString(),
                'last_generated_at' => now(),
                'generated_order_ids' => array_merge($recurrence->generated_order_ids ?? [], [$orderId]),
                'generated_invoice_ids' => $document instanceof Invoice
                    ? array_merge($recurrence->generated_invoice_ids ?? [], [$document->id])
                    : null,
            ], fn ($value) => $value !== null) + [
                'last_error' => null,
                'last_error_at' => null,
            ]);

            // #900 (PC6): finishing the schedule is a status change; it goes
            // through the engine (active → completed). A continuing schedule
            // keeps its current status untouched.
            if ($finished) {
                app(\App\Services\SalesWorkflowService::class)->transition(
                    $recurrence,
                    'complete',
                    ['cycles_generated' => $cycles],
                    'All scheduled cycles generated',
                );
            }

            return $document;
        });

        // Issue #330: approval submission, auto-post and auto-send run after
        // the cycle committed. The invoice exists either way; a failure is
        // recorded and reported, never swallowed.
        if ($document instanceof Invoice) {
            $error = $this->recurringInvoiceService->completeCycle($recurrence->fresh(), $document);

            if ($error !== null) {
                $this->recordFailure($recurrence, $error);
            }
        }

        return $document;
    }

    private function createCycleOrder(SalesOrderRecurrence $recurrence, SalesOrder $source, Carbon $asOf): SalesOrder
    {
        return $this->salesOrderService->createOrder([
            'customer_id' => $recurrence->customer_id,
            'order_date' => $asOf->toDateString(),
            'currency' => $source->currency,
            'notes' => 'Recurring order (cycle '.($recurrence->cycles_generated + 1).") from {$source->sales_order_number}",
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
    }

    /**
     * Issue #330: record a failed run on the recurrence (outside the rolled
     * back cycle transaction) and keep it for the command's report.
     */
    private function recordFailure(SalesOrderRecurrence $recurrence, string $error): void
    {
        SalesOrderRecurrence::whereKey($recurrence->id)->update([
            'last_error' => $error,
            'last_error_at' => now(),
        ]);

        $this->failures[] = ['recurrence_id' => (int) $recurrence->id, 'error' => $error];

        Log::warning('Recurring sales generation failed', [
            'recurrence_id' => $recurrence->id,
            'error' => $error,
        ]);
    }

    public function pause(SalesOrderRecurrence $recurrence): SalesOrderRecurrence
    {
        if ($recurrence->status !== 'active') {
            throw new \RuntimeException('Only active recurrences can be paused.');
        }

        app(\App\Services\SalesWorkflowService::class)->transition($recurrence, 'pause');

        return $recurrence->fresh();
    }

    public function resume(SalesOrderRecurrence $recurrence): SalesOrderRecurrence
    {
        if ($recurrence->status !== 'paused') {
            throw new \RuntimeException('Only paused recurrences can be resumed.');
        }

        app(\App\Services\SalesWorkflowService::class)->transition($recurrence, 'resume');

        return $recurrence->fresh();
    }

    public function cancel(SalesOrderRecurrence $recurrence): SalesOrderRecurrence
    {
        if (in_array($recurrence->status, ['cancelled', 'completed'], true)) {
            throw new \RuntimeException('This recurrence is already finished.');
        }

        app(\App\Services\SalesWorkflowService::class)->transition($recurrence, 'cancel');

        return $recurrence->fresh();
    }
}

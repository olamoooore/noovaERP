<?php

namespace Modules\Sales\Services;

use App\Models\Invoice;
use App\Models\InvoiceActivityLog;
use App\Models\User;
use App\Services\InvoiceActivityService;
use App\Services\InvoiceApprovalService;
use App\Services\InvoiceEvidenceService;
use App\Services\InvoiceSendBundleService;
use App\Services\InvoiceService;
use App\Services\LockDateValidationService;
use App\Services\SalesLineTaxResolver;
use App\Support\TenantUserHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Exceptions\CreditLimitExceededException;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;
use Modules\Sales\Models\SalesOrderRecurrence;

/**
 * Issue #330: the invoice side of the Sales Order recurrence (#151).
 *
 * A recurrence with document_type = 'invoice' generates, on each cycle, an
 * invoice through the SAME services a manual invoice uses
 * (InvoiceController::store → createInvoiceWithCreditGate):
 *
 *  - tax re-resolved at the run date by the #302 SalesLineTaxResolver;
 *  - price re-resolved by PricingService inside SalesOrderService::createOrder
 *    (engine-first: 'auto' for catalogue lines, 'manual' keeps a recorded
 *    override through the floor guard);
 *  - a backing order confirmed without its own credit gate and allocated,
 *    then SalesOrderService::createInvoice → InvoiceService::createInvoiceInternal;
 *  - the #326 CreditCheckService verdict on the invoice, throwing under the
 *    `block` policy so the caller's transaction rolls the whole cycle back.
 *
 * Auto-post runs the same gates as POST /invoices/{id}/post (approval,
 * evidence, period lock, credit) and books through InvoiceService::postInvoice
 * (PostingEngine). Auto-send uses InvoiceSendBundleService, the path behind
 * the Send button and Bulk Send.
 */
class RecurringInvoiceService
{
    public function __construct(
        private readonly SalesOrderService $salesOrderService,
        private readonly SalesLineTaxResolver $taxResolver,
        private readonly CreditCheckService $creditCheckService,
    ) {}

    /**
     * Create this cycle's invoice. Must run inside the caller's transaction:
     * a credit block throws and leaves nothing behind.
     *
     * @throws CreditLimitExceededException under the `block` credit policy
     */
    public function createCycleInvoice(SalesOrderRecurrence $recurrence, SalesOrder $source, Carbon $asOf): Invoice
    {
        $invoiceDate = $asOf->toDateString();
        $customerId = (int) $recurrence->customer_id;
        $cycle = $recurrence->cycles_generated + 1;

        $lines = $source->lines
            ->map(fn (SalesOrderLine $line) => $this->cycleLine($line))
            ->values()
            ->all();

        // #302 (X1): the line tax is resolved by the tax engine at the run
        // date — a rate that is no longer in force is not copied forward.
        $lines = $this->taxResolver->applyToLines($lines, $invoiceDate, $customerId);

        $order = $this->salesOrderService->createOrder(array_filter([
            'customer_id' => $customerId,
            'channel' => 'admin',
            'order_date' => $invoiceDate,
            'currency' => $source->currency,
            // FR-5.2: the agreement's document currency; the FX snapshot is
            // taken at the run date by the pricing context.
            'currency_code' => $source->currency_code,
            'payment_term_id' => $source->payment_term_id,
            'warehouse_id' => $source->warehouse_id,
            'notes' => "Recurring invoice (cycle {$cycle}) from {$source->sales_order_number}",
            'lines' => $lines,
        ], fn ($value) => $value !== null));

        // Issue #326: as for a manual invoice, the backing order skips its own
        // check — the invoice below carries the one credit verdict.
        $order = $this->salesOrderService->confirmOrder($order, creditGate: false);
        $order = $this->salesOrderService->allocateOrder($order);

        $invoice = $this->salesOrderService->createInvoice($order, [
            'invoice_date' => $invoiceDate,
            // Due date from the payment term / customer terms at the run date
            // (InvoiceService::createInvoiceInternal), not from "now".
            'due_date' => null,
        ]);

        $result = $invoice->status === 'draft'
            ? $this->creditCheckService->checkInvoice($invoice, lockCustomer: true)
            : null;
        $verdict = $result !== null ? $this->creditCheckService->invoiceVerdict($result) : null;

        if ($verdict !== null && $verdict['blocked']) {
            throw new CreditLimitExceededException($result, $invoice->customer);
        }

        if ($verdict !== null && ! $verdict['approved']) {
            Log::warning('Recurring invoice created over the customer credit limit (warn policy)', [
                'recurrence_id' => $recurrence->id,
                'invoice_id' => $invoice->id,
                'reason' => $verdict['reason'] ?? null,
            ]);
        }

        return $invoice;
    }

    /**
     * After the cycle committed: submit for approval when required, then
     * auto-post and auto-send as configured. The invoice already exists, so a
     * failure here does not undo the cycle — it is returned for the caller to
     * record on the recurrence and report.
     *
     * @return string|null the failure, or null when every requested step ran
     */
    public function completeCycle(SalesOrderRecurrence $recurrence, Invoice $invoice): ?string
    {
        $invoice = $invoice->fresh(['customer']);
        $actor = User::find(TenantUserHelper::requireTenantUserId($recurrence->created_by));

        app(InvoiceActivityService::class)->log(
            $invoice,
            InvoiceActivityLog::EVENT_CREATED,
            [
                'invoice_number' => $invoice->invoice_number,
                'total' => (float) $invoice->total,
                'recurrence_id' => $recurrence->id,
            ],
            null,
            null,
            InvoiceActivityLog::ACTOR_SYSTEM,
            null,
        );

        // Same as the create endpoint: an invoice that requires approval
        // (tenant policy or threshold, #647) is submitted right away.
        if ($invoice->requires_approval
            && $invoice->status === 'draft'
            && $invoice->approval_status !== Invoice::APPROVAL_PENDING
            && $invoice->approval_status !== Invoice::APPROVAL_APPROVED) {
            if (! $actor) {
                return "Invoice {$invoice->invoice_number} requires approval but no user is available to submit it.";
            }

            try {
                app(InvoiceApprovalService::class)->submit($invoice, $actor, 'Automatically submitted by the recurring schedule.');
                $invoice = $invoice->fresh(['customer']);
            } catch (\Throwable $e) {
                return "Invoice {$invoice->invoice_number} could not be submitted for approval: {$e->getMessage()}";
            }
        }

        if ($recurrence->auto_post) {
            $gate = $this->postGateError($invoice);
            if ($gate !== null) {
                return "Invoice {$invoice->invoice_number} was created as a draft but not auto-posted: {$gate}";
            }

            try {
                $invoice = app(InvoiceService::class)->postInvoice($invoice)->fresh(['customer']);
            } catch (\Throwable $e) {
                return "Invoice {$invoice->invoice_number} was created as a draft but auto-posting failed: {$e->getMessage()}";
            }
        }

        if ($recurrence->auto_send) {
            $bundle = app(InvoiceSendBundleService::class);
            $reason = $bundle->unsendableReason($invoice);

            if ($reason !== null) {
                return "Invoice {$invoice->invoice_number} was not auto-sent: {$reason}";
            }
            if (! $actor) {
                return "Invoice {$invoice->invoice_number} was not auto-sent: no user is available to send it.";
            }

            try {
                $bundle->send($invoice, $actor);
            } catch (\Throwable $e) {
                return "Invoice {$invoice->invoice_number} was not auto-sent: {$e->getMessage()}";
            }
        }

        return null;
    }

    /**
     * The hard gates of POST /invoices/{id}/post (InvoiceController::post),
     * in the same order, through the same services.
     */
    private function postGateError(Invoice $invoice): ?string
    {
        $approval = app(InvoiceApprovalService::class)->checkGuard($invoice);
        if ($approval['blocked']) {
            return $approval['message'];
        }

        $evidence = app(InvoiceEvidenceService::class)->checkGuard($invoice);
        if ($evidence['blocked']) {
            return $evidence['message'];
        }

        $lock = app(LockDateValidationService::class)->validateTransactionDate('sales', $invoice->invoice_date);
        if ($lock['is_locked']) {
            return (string) ($lock['message'] ?? 'The accounting period is locked.');
        }

        $result = $this->creditCheckService->checkInvoice($invoice);
        $verdict = $result !== null ? $this->creditCheckService->invoiceVerdict($result) : null;
        if ($verdict !== null && $verdict['blocked']) {
            return 'Credit check failed: '.$verdict['reason'];
        }

        return null;
    }

    /**
     * One source-order line as a fresh line payload for this cycle. Price and
     * tax identity are deliberately NOT copied: PricingService and the tax
     * resolver decide them again at the run date.
     *
     * @return array<string, mixed>
     */
    private function cycleLine(SalesOrderLine $line): array
    {
        $lineType = $line->line_type ?? 'product';
        $isProductLine = $lineType === 'product' && $line->product_id !== null;
        $trace = is_array($line->pricing_trace) ? $line->pricing_trace : [];
        $manualOverride = ($trace['source'] ?? null) === 'manual';

        $payload = [
            'line_type' => $lineType,
            'sequence' => $line->sequence,
            'product_id' => $line->product_id,
            'model_number' => $line->model_number,
            'variant_id' => $line->variant_id,
            'description' => $line->description,
            'description_html' => $line->description_html,
            'product_notes' => $line->product_notes,
            'sku' => $line->sku,
            // Order lines store base (stock) quantities; uom_id is left out
            // so the quantity is not converted a second time.
            'quantity' => (float) $line->quantity,
            'unit' => $line->unit,
            'discount_type' => $line->discount_type,
            'discount_value' => $line->discount_value,
            'discount_amount' => $line->discount_amount,
            // A sent rate is read by the resolver as a choice among the rules
            // in force at the run date (#302 rule 4); an explicit 0% stays.
            'tax_rate' => $line->tax_rate,
            'tax_inclusive' => (bool) $line->tax_inclusive,
            'income_account_id' => $line->income_account_id,
            'project_id' => $line->project_id,
            'department_id' => $line->department_id,
        ];

        if (! $isProductLine) {
            // Text / free lines: no catalogue price to resolve.
            $payload['price_mode'] = 'legacy';
            $payload['unit_price'] = (float) $line->unit_price;
        } elseif ($manualOverride) {
            // A recorded manual override stays an override and goes through
            // the engine's floor guard again (MRD FR-6).
            $payload['price_mode'] = 'manual';
            $payload['unit_price'] = (float) $line->unit_price;
            $payload['override_reason'] = $trace['override_reason'] ?? null;
        } else {
            $payload['price_mode'] = 'auto';
        }

        return array_filter($payload, fn ($value) => $value !== null);
    }
}

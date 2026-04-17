<?php

namespace Modules\FieldService\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Modules\FieldService\Models\WorkOrder;
use Modules\Sales\Services\SalesOrderService;

class InvoiceGenerationService
{
    /**
     * Generate a draft invoice from a completed work order.
     * Returns the created Invoice model.
     */
    public function generateFromWorkOrder(WorkOrder $wo): Invoice
    {
        if ($wo->invoice_id) {
            return Invoice::findOrFail($wo->invoice_id);
        }

        return DB::transaction(function () use ($wo) {
            $wo->load(['parts.product', 'timeLogs', 'customer']);

            /** @var SalesOrderService $salesOrderService */
            $salesOrderService = app(SalesOrderService::class);
            $salesOrder = $salesOrderService->createFromWorkOrder($wo);

            if ($salesOrder->isDraft()) {
                $salesOrder = $salesOrderService->confirmOrder($salesOrder);
            }
            if ($salesOrder->isConfirmed()) {
                $salesOrder = $salesOrderService->allocateOrder($salesOrder);
            }
            if ($salesOrder->isAllocated()) {
                $salesOrder = $salesOrderService->fulfilOrder($salesOrder);
            }

            $invoice = $salesOrderService->createInvoice($salesOrder);

            // ── Link invoice back to WO ──────────────────────────────
            $wo->invoice_id = $invoice->id;
            $wo->sales_order_id = $salesOrder->id;
            $wo->save();

            return $invoice;
        });
    }
}

<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Quotation;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Services\SalesOrderService;

/**
 * Issue #334 (Q5): the single quotation → Sales Order release path.
 *
 * Extracted verbatim from QuotationController::releaseQuotation() so the
 * explicit Confirm action, the quote→invoice / quote→DO conversions AND the
 * customer-portal acceptance all release through one code path. The approval
 * gate stays in QuotationConfirmationService; callers must have cleared it
 * before calling release().
 */
class QuotationReleaseService
{
    public function __construct(private SalesOrderService $salesOrderService) {}

    /**
     * Create and confirm the quotation's linked Sales Order and mark the
     * quotation confirmed. A quotation that is already confirmed (e.g. a
     * downstream DO created after an earlier confirmation) only gets the order
     * link re-applied.
     */
    public function release(Quotation $quotation): Quotation
    {
        // Issue #335 (Q6): final backstop — no caller may release a past-due
        // quotation. Confirmed quotations are exempt (their validity is
        // already accepted), so a downstream DO created after an earlier
        // confirmation still gets its link re-applied.
        app(QuotationExpiryGuard::class)->assertNotExpired($quotation);

        return DB::transaction(function () use ($quotation) {
            // Build SalesOrder lines from quotation lines
            // FR-5.5: quotation → SO is a CONVERSION — currency, rate
            // snapshot and line prices propagate unchanged; nothing re-prices.
            // Issue #685: a line quoted in a sales UoM hands the order the
            // same quantity + UoM (the order service converts to stock units)
            // and a per-stock-unit price, so the accepted per-Box price is
            // preserved exactly.
            $salesLines = $quotation->lines->map(function ($line) {
                $uomConverter = app(\App\Services\ProductUomConverter::class);
                $lineUom = ($line->uom_id && $line->product_id)
                    ? $uomConverter->resolveOrNull((int) $line->product_id, (int) $line->uom_id)
                    : null;

                $unitPrice = $line->unit_price;
                if ($lineUom && ! $lineUom->isStockUnit() && $unitPrice !== null) {
                    $unitPrice = $uomConverter->salesPriceToBase($lineUom, (float) $unitPrice);
                }

                return array_filter([
                    'line_type' => $line->line_type ?? 'product',
                    'sequence' => $line->sequence ?? 0,
                    'product_id' => $line->product_id,
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'uom_id' => $lineUom?->id,
                    'unit_price' => $unitPrice,
                    'price_mode' => 'carried',
                    'base_unit_price' => $line->base_unit_price,
                    'pricing_trace' => $line->pricing_trace,
                    'tax_rate' => $line->tax_rate,
                    // #303 (X3): the accepted price mode travels with the line.
                    'tax_inclusive' => (bool) $line->tax_inclusive,
                    // #302: the quoted line's tax identity travels with its rate.
                    'tax_rule_id' => $line->tax_rule_id,
                    'tax_code' => $line->tax_code,
                    'tax_treatment' => $line->tax_treatment,
                    'discount_amount' => $line->discount_amount,
                    // Issue #884 (UX2): the accepted line income account and
                    // analytic dimensions travel with the order (and from
                    // there to the invoice) — a null keeps the product default.
                    'income_account_id' => $line->income_account_id,
                    'project_id' => $line->project_id,
                    'department_id' => $line->department_id,
                    // Issue #332: the accepted line's analytic distribution.
                    'analytic_distribution' => $line->analytic_distribution,
                ], fn ($v) => $v !== null);
            })->toArray();

            // Resolve warehouse if needed
            $warehouseId = null;
            $productIds = array_filter(array_column($salesLines, 'product_id'));
            if (! empty($productIds)) {
                $hasStockTracked = Product::whereIn('id', $productIds)
                    ->where('track_inventory', true)
                    ->exists();
                if ($hasStockTracked) {
                    $warehouse = Warehouse::getDefault() ?? Warehouse::first();
                    $warehouseId = $warehouse?->id;
                }
            }

            // Create SalesOrder
            $salesOrder = $this->salesOrderService->createOrder([
                'customer_id' => $quotation->customer_id,
                'channel' => 'admin',
                'notes' => "Quotation #{$quotation->quotation_number}",
                // Carry the customer's references onto the order so the whole
                // chain (SO → DO → invoice) stays traceable from any of them.
                'customer_reference' => $quotation->customer_reference,
                'internal_reference' => $quotation->internal_reference,
                // Issue #408: the accepted promise date and partial-delivery
                // policy travel with the order (and from there to its DOs).
                'commitment_date' => $quotation->commitment_date?->toDateString(),
                'shipping_policy' => $quotation->shipping_policy ?: 'asap',
                // Issue #333: the accepted trade term travels with the order
                // (and from there to its invoices).
                'incoterm_code' => $quotation->incoterm_code,
                'incoterm_place' => $quotation->incoterm_place,
                // Issue #321: the accepted payment term travels with the order
                // (and from there sets the invoice due date).
                'payment_term_id' => $quotation->payment_term_id,
                // #291: the header discount is part of the accepted price — it
                // must travel onto the Sales Order (and from there the invoice),
                // otherwise a quote accepted at 990 was ordered/billed at 1,100.
                'discount_amount' => (float) ($quotation->discount_amount ?? 0),
                // Issue #553: the accepted quote's salesperson/team travel with
                // the order so the creation defaults never overwrite them.
                'salesperson' => $quotation->salesperson,
                'sales_team' => $quotation->sales_team,
                'lines' => $salesLines,
                'warehouse_id' => $warehouseId,
                // FR-5.5: document currency + FX snapshot propagate verbatim.
                'currency_code' => $quotation->currency_code,
                'exchange_rate' => $quotation->exchange_rate,
                'base_currency_code' => $quotation->base_currency_code,
            ]);

            // Confirm the SalesOrder
            $salesOrder = $this->salesOrderService->confirmOrder($salesOrder);

            // #900 (PC6): status through the workflow engine; the sales order
            // link keeps its own write. A quotation that is already confirmed
            // (e.g. the DO is created later for an earlier confirmation) has
            // nothing to transition — only the order link is added below.
            if ($quotation->status !== 'confirmed') {
                app(SalesWorkflowService::class)->transition(
                    $quotation,
                    'confirm',
                    ['sales_order_id' => $salesOrder->id],
                );
            }

            // Link the SalesOrder to the quotation
            $quotation->update([
                'sales_order_id' => $salesOrder->id,
            ]);

            return $quotation->fresh(['customer', 'lines.product', 'salesOrder']);
        });
    }
}

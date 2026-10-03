<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use App\Models\Quotation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Sales\Models\DeliveryOrder;
use Modules\Sales\Models\DeliveryOrderLine;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;

/**
 * Medical-case creation for the invoice / quotation / delivery order imports
 * (issue #564).
 *
 * The "Case Tracking" CSV format from Acme contains:
 *   - Case No       → case_no (e.g. "1st", "2nd")
 *   - Case Tag      → case_tag (e.g. "FOC", "GEH", "HTx 60s", "Clinic", "CCK")
 *   - Case Date     → case_date
 *   - Patient Name  → patient_reference (non-identifying)
 *   - Delivered To  → delivered_to
 *   - Other Note    → notes
 *
 * Issue #564 rules enforced here:
 *   - A case tag is stored as a case tag ONLY. Imports never create or
 *     assign bonus schemes: tags describe locations and FOC markers, not
 *     scheme configuration, and the old resolveOrCreateBonusScheme() filled
 *     the scheme list with empty `Auto: <tag>` schemes.
 *   - Imports record bonus usage for each imported case item via
 *     recordUsageForCase(), quantity taken from the linked delivery order
 *     line when there is one. Quotation imports are the exception — spec
 *     "Medical Case and Bonus Scheme Models.md" §6.5 forbids consuming
 *     scheme entitlement while the quotation is still draft.
 *   - Imported FOC lines are matched to an entitlement as a redemption
 *     instead of counting as qualifying usage.
 */
class CaseImportService
{
    /**
     * After invoice import, create a medical case if case fields are present.
     *
     * @param  Invoice  $invoice  The freshly created invoice
     * @param  array  $data  Case data: case_no, case_tag, case_date,
     *                       patient_name, delivered_to, other_note
     */
    public function createCaseFromInvoiceImport(Invoice $invoice, array $data): ?MedicalCase
    {
        if (empty($data['case_no']) && empty($data['case_tag']) && empty($data['patient_name'])) {
            return null;
        }

        $caseNo = $this->buildCaseNo($data['case_no'] ?? null, $invoice);

        return DB::transaction(function () use ($invoice, $data, $caseNo) {
            $case = MedicalCase::create([
                'case_no' => $caseNo,
                'case_tag' => $data['case_tag'] ?? null,
                'customer_id' => $invoice->customer_id,
                'invoice_id' => $invoice->id,
                'patient_reference' => $data['patient_name'] ?? null,
                'case_date' => $data['case_date'] ?? null,
                'delivered_to' => $data['delivered_to'] ?? null,
                'notes' => $data['other_note'] ?? null,
                'status' => $this->determineCaseStatus($invoice),
                'created_by' => $invoice->created_by ?? Auth::id(),
            ]);

            $this->linkInvoiceLinesToCase($invoice, $case);
            $this->recordUsageForCase($case);

            return $case;
        });
    }

    /**
     * After quotation import, create a medical case if case fields are present.
     *
     * No usage is recorded: the quotation is still draft and spec §6.5 of the
     * medical-case module documentation forbids consuming scheme entitlement
     * for draft quotations. Usage happens when the goods actually move (DO)
     * or the invoice is created.
     */
    public function createCaseFromQuotationImport(Quotation $quotation, array $data): ?MedicalCase
    {
        if (empty($data['case_no']) && empty($data['case_tag']) && empty($data['patient_name'])) {
            return null;
        }

        $caseNo = $this->buildCaseNo($data['case_no'] ?? null, null, $quotation);

        return DB::transaction(function () use ($quotation, $data, $caseNo) {
            $case = MedicalCase::create([
                'case_no' => $caseNo,
                'case_tag' => $data['case_tag'] ?? null,
                'customer_id' => $quotation->customer_id,
                'patient_reference' => $data['patient_name'] ?? null,
                'case_date' => $data['case_date'] ?? null,
                'delivered_to' => $data['delivered_to'] ?? null,
                'notes' => $data['other_note'] ?? null,
                'status' => 'draft',
                'created_by' => $quotation->created_by ?? Auth::id(),
            ]);

            $this->linkQuotationLinesToCase($quotation, $case);

            return $case;
        });
    }

    /**
     * After delivery order import, create the medical case for the DO (the
     * block used to live inline in DeliveryOrderController and rolled back
     * half-way when an item insert failed — it now runs in one transaction).
     *
     * Case numbers keep the controller's historical shape:
     * `DO-{doNumber}-{caseNo}`, falling back to `DO-CASE-{doNumber}`.
     */
    public function createCaseFromDeliveryOrderImport(DeliveryOrder $do, array $data): ?MedicalCase
    {
        if (empty($data['case_no']) && empty($data['case_tag']) && empty($data['patient_name'])) {
            return null;
        }

        return DB::transaction(function () use ($do, $data) {
            $isFoc = $this->isFocTag($data['case_tag'] ?? null);

            $case = MedicalCase::create([
                'case_no' => $this->buildDeliveryOrderCaseNo($data['case_no'] ?? null, $do->delivery_order_number),
                'case_tag' => $data['case_tag'] ?? null,
                'customer_id' => $do->customer_id,
                'delivery_address_id' => null,
                'patient_reference' => $data['patient_name'] ?? null,
                'case_date' => $data['case_date'] ?? null,
                'delivered_to' => $data['delivered_to'] ?? null,
                'notes' => $data['other_note'] ?? null,
                'status' => 'delivered',
                'created_by' => Auth::id() ?? $do->created_by,
            ]);

            foreach ($do->lines as $line) {
                $soLine = $this->resolveSalesOrderLineForCustomer((int) $do->customer_id, $line->product_id);

                if (! $soLine) {
                    // medical_case_items.sales_order_item_id is NOT NULL and
                    // pointing an item at another product's line would corrupt
                    // the bonus counter — skip this line loudly instead.
                    Log::warning('Cannot create medical case item from DO import: no sales order line for product', [
                        'delivery_order_id' => $do->id,
                        'delivery_order_line_id' => $line->id,
                        'product_id' => $line->product_id,
                    ]);

                    continue;
                }

                MedicalCaseItem::create([
                    'medical_case_id' => $case->id,
                    'sales_order_item_id' => $soLine->id,
                    'delivery_order_item_id' => $line->id,
                    'product_id' => $line->product_id,
                    'delivered_qty' => $line->quantity_delivered,
                    'allocated_qty' => $line->quantity_ordered,
                    'unit_price' => $isFoc ? 0 : $line->unit_price,
                    'is_foc' => $isFoc,
                ]);
            }

            $this->recordUsageForCase($case);

            return $case;
        });
    }

    /**
     * Issue #564 AC1 — record bonus usage for every item of an imported case.
     *
     * Usage quantity: the linked delivery order line when there is one (the
     * AC's "from DO lines when linked"), else the invoiced quantity, else the
     * delivered quantity. FOC lines never count as qualifying usage; they are
     * matched to an entitlement as a redemption instead (AC3).
     *
     * This is the single seam for issue #559: when BonusCounterService
     * lands, point this method at it and the import call sites stay
     * unchanged. Both the qualifying accrual (accrueForItem) and the FOC
     * redemption (recordFocRedemption) already go through the shared
     * MedicalCaseBonusAccrualService.
     */
    public function recordUsageForCase(MedicalCase $case): void
    {
        foreach ($case->items as $item) {
            if ($item->is_foc) {
                $this->matchFocRedemption($item);

                continue;
            }

            if (! $item->sales_order_item_id) {
                Log::warning('Cannot record usage for imported case item without a sales order line', [
                    'medical_case_item_id' => $item->id,
                ]);

                continue;
            }

            $qty = $this->usageQuantityFor($item);
            if ($qty <= 0) {
                Log::warning('No usage quantity for imported case item', [
                    'medical_case_item_id' => $item->id,
                ]);

                continue;
            }

            app(MedicalCaseBonusAccrualService::class)->accrueForItem($item, $qty);
        }
    }

    /**
     * Determine if a case tag represents a FOC (Free of Charge) item.
     */
    public function isFocTag(?string $tag): bool
    {
        if (! $tag) {
            return false;
        }

        return stripos($tag, 'FOC') !== false;
    }

    /**
     * Issue #564 AC3 — match an imported FOC line to an entitlement as a
     * redemption (feeding issue #561's replay). The scheme/assignment
     * matching lives in the shared MedicalCaseBonusAccrualService so imports
     * and the interactive flows cannot drift apart. An unmatched FOC line
     * records nothing — it stays on the case item (`is_foc`) for review.
     */
    private function matchFocRedemption(MedicalCaseItem $item): void
    {
        $qty = $this->usageQuantityFor($item);

        if ($qty <= 0 || ! $item->sales_order_item_id) {
            Log::warning('Imported FOC line without quantity or sales order line — recorded nothing', [
                'medical_case_item_id' => $item->id,
            ]);

            return;
        }

        $usage = app(MedicalCaseBonusAccrualService::class)->recordFocRedemption($item, $qty);

        if (! $usage) {
            Log::warning('Imported FOC line has no matching entitlement — recorded nothing', [
                'medical_case_item_id' => $item->id,
                'product_id' => $item->product_id,
                'sales_order_item_id' => $item->sales_order_item_id,
            ]);
        }
    }

    /**
     * Quantity an imported case item contributes: the linked delivery order
     * line's delivered quantity when linked (issue #564 AC1), else the
     * invoiced quantity, else the delivered quantity.
     */
    private function usageQuantityFor(MedicalCaseItem $item): float
    {
        if ($item->delivery_order_item_id) {
            $line = DeliveryOrderLine::find($item->delivery_order_item_id);
            if ($line && (float) $line->quantity_delivered > 0) {
                return (float) $line->quantity_delivered;
            }
        }

        if ((float) $item->invoiced_qty > 0) {
            return (float) $item->invoiced_qty;
        }

        return (float) $item->delivered_qty;
    }

    /**
     * Resolve the sales order line a case item must link (its
     * sales_order_item_id is NOT NULL): the invoice's own sales order by
     * product first, then any sales order line of the same customer and
     * product — imports create standalone documents with no guaranteed
     * sales_order_id of their own. Returns null when the customer never
     * ordered the product rather than linking a wrong product's line.
     */
    private function resolveSalesOrderLineForCustomer(int $customerId, ?int $productId): ?SalesOrderLine
    {
        if (! $productId) {
            return null;
        }

        return SalesOrderLine::query()
            ->where('product_id', $productId)
            ->whereHas('salesOrder', fn ($q) => $q->where('customer_id', $customerId))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Build a unique case number from the imported data.
     */
    private function buildCaseNo(?string $caseNo, ?Invoice $invoice = null, ?Quotation $quotation = null): string
    {
        if ($caseNo && trim($caseNo) !== '') {
            $prefix = '';
            $docId = '';
            if ($invoice) {
                $prefix = 'INV-';
                $docId = (string) $invoice->id;
            } elseif ($quotation) {
                $prefix = 'QT-';
                $docId = (string) $quotation->id;
            }
            $cleanCaseNo = trim($caseNo);
            $baseNumber = $prefix.$docId.'-'.$cleanCaseNo;

            // Ensure uniqueness — append counter if needed
            $finalNumber = $baseNumber;
            $counter = 1;
            while (MedicalCase::withTrashed()->where('case_no', $finalNumber)->exists()) {
                $finalNumber = $baseNumber.'-'.$counter;
                $counter++;
                if ($counter > 100) {
                    // Fallback: use generated number
                    return MedicalCase::generateCaseNumber();
                }
            }

            return $finalNumber;
        }

        return MedicalCase::generateCaseNumber();
    }

    /**
     * Case number for delivery-order imports (shape kept from the block
     * formerly inlined in DeliveryOrderController).
     */
    private function buildDeliveryOrderCaseNo(?string $caseNo, string $doNumber): string
    {
        if ($caseNo && trim($caseNo) !== '') {
            $base = 'DO-'.trim($doNumber).'-'.trim($caseNo);
            $final = $base;
            $counter = 1;
            while (MedicalCase::withTrashed()->where('case_no', $final)->exists()) {
                $final = $base.'-'.$counter;
                $counter++;
                if ($counter > 100) {
                    return 'DO-CASE-'.$doNumber;
                }
            }

            return $final;
        }

        return 'DO-CASE-'.$doNumber;
    }

    /**
     * Determine the case status based on the invoice status.
     */
    private function determineCaseStatus(Invoice $invoice): string
    {
        if ($invoice->lifecycle_status === 'posted' || $invoice->status === 'posted') {
            return 'invoiced';
        }

        return 'draft';
    }

    /**
     * Link invoice line items to the medical case.
     */
    private function linkInvoiceLinesToCase(Invoice $invoice, MedicalCase $case): void
    {
        $isFoc = $this->isFocTag($case->case_tag);
        $salesOrder = $invoice->sales_order_id ? SalesOrder::find($invoice->sales_order_id) : null;

        foreach ($invoice->lines as $line) {
            $productId = $line->product_id;

            // The invoice line's own sales-order line is the exact link …
            $soLine = $line->sales_order_line_id
                ? SalesOrderLine::find($line->sales_order_line_id)
                : null;

            // … else match by product inside the invoice's sales order …
            if (! $soLine && $salesOrder && $productId) {
                $soLine = $salesOrder->lines->firstWhere('product_id', $productId);
            }

            // … else fall back to any line of the same customer + product.
            if (! $soLine && $productId) {
                $soLine = $this->resolveSalesOrderLineForCustomer((int) $invoice->customer_id, $productId);
            }

            if (! $soLine) {
                Log::warning('Cannot create medical case item: no sales order line available', [
                    'invoice_id' => $invoice->id,
                    'line_id' => $line->id,
                ]);

                continue;
            }

            $productId = $productId ?: $soLine->product_id;
            if (! $productId) {
                Log::warning('Cannot create medical case item: line has no product', [
                    'invoice_id' => $invoice->id,
                    'line_id' => $line->id,
                ]);

                continue;
            }

            MedicalCaseItem::create([
                'medical_case_id' => $case->id,
                'sales_order_item_id' => $soLine->id,
                'invoice_item_id' => $line->id,
                'product_id' => $productId,
                'allocated_qty' => $line->quantity,
                'invoiced_qty' => $line->quantity,
                'unit_price' => $isFoc ? 0 : $line->unit_price,
                'is_foc' => $isFoc,
                'notes' => $line->product_notes ?? null,
            ]);
        }
    }

    /**
     * Link quotation line items to the medical case.
     */
    private function linkQuotationLinesToCase(Quotation $quotation, MedicalCase $case): void
    {
        $isFoc = $this->isFocTag($case->case_tag);
        $so = $quotation->sales_order_id ? SalesOrder::find($quotation->sales_order_id) : null;

        foreach ($quotation->lines as $line) {
            $productId = $line->product_id;
            $soLine = null;

            if ($so && $productId) {
                $soLine = $so->lines->firstWhere('product_id', $productId);
            }
            if (! $soLine && $productId) {
                $soLine = $this->resolveSalesOrderLineForCustomer((int) $quotation->customer_id, $productId);
            }

            if (! $soLine || ! $productId) {
                Log::warning('Cannot create medical case item: no sales order line available', [
                    'quotation_id' => $quotation->id,
                    'product_id' => $productId,
                ]);

                continue;
            }

            MedicalCaseItem::create([
                'medical_case_id' => $case->id,
                'sales_order_item_id' => $soLine->id,
                'product_id' => $productId,
                'allocated_qty' => $line->quantity,
                'unit_price' => $isFoc ? 0 : $line->unit_price,
                'is_foc' => $isFoc,
                'notes' => $line->product_notes ?? null,
            ]);
        }
    }
}

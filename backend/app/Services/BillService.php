<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\BillLine;
use App\Models\GoodsReceipt;
use Illuminate\Support\Facades\DB;
use Modules\OCRBookkeeping\Helpers\TenantUserHelper;

class BillService
{
    public function __construct(
        private readonly ThreeWayMatchService $threeWayMatchService,
    ) {}

    /**
     * Create a vendor bill with full procurement-lineage enforcement (WS-07 FIX-02/FIX-03).
     *
     * When bill_type is 'procurement' (or auto-inferred from PO/GRN linkage):
     *  - purchase_order_id or goods_receipt_id must be provided.
     *  - ThreeWayMatchService::validate() is always run; rolls back on failure.
     *
     * When bill_type is 'expense':
     *  - No PO/GRN required.
     *  - Three-way match is skipped (no procurement lineage to validate).
     *
     * @param  array{
     *   bill_type?: string,
     *   bill_number?: string|null,
     *   vendor_id: int,
     *   purchase_order_id?: int|null,
     *   goods_receipt_id?: int|null,
     *   bill_date: string,
     *   due_date?: string|null,
     *   vendor_reference?: string|null,
     *   internal_reference?: string|null,
     *   attention_to?: string|null,
     *   contact_email?: string|null,
     *   contact_phone?: string|null,
     *   vendor_address_snapshot?: string|null,
     *   remittance_address_snapshot?: string|null,
     *   company_registration_number?: string|null,
     *   tax_registration_number?: string|null,
     *   notes?: string|null,
     *   terms?: string|null,
     *   discount_amount?: float,
     *   lines: array<array{
     *     account_id: int,
     *     description?: string|null,
     *     quantity: float,
     *     unit_price: float,
     *     tax_rate?: float,
     *     purchase_order_item_id?: int|null,
     *     goods_receipt_line_id?: int|null,
     *   }>,
     * } $data
     * @param  int $createdBy  Tenant user ID.
     * @throws \InvalidArgumentException When procurement bill has no PO/GRN linkage.
     * @throws \RuntimeException         When three-way match validation fails.
     */
    public function createBill(array $data, int $createdBy): Bill
    {
        return DB::transaction(function () use ($data, $createdBy) {
            // ── Resolve GRN / PO consistency ──────────────────────────────────────
            $goodsReceipt = null;
            if (!empty($data['goods_receipt_id'])) {
                $goodsReceipt = GoodsReceipt::findOrFail((int) $data['goods_receipt_id']);
            }

            $resolvedPoId = $data['purchase_order_id'] ?? null;
            if ($goodsReceipt) {
                if ($resolvedPoId && (int) $resolvedPoId !== (int) $goodsReceipt->purchase_order_id) {
                    throw new \InvalidArgumentException(
                        'goods_receipt_id does not belong to the provided purchase_order_id'
                    );
                }
                $resolvedPoId = $goodsReceipt->purchase_order_id;
            }

            // ── FIX-02: Infer and enforce bill_type ───────────────────────────────
            // Auto-promote to 'procurement' when PO or GRN linkage is provided,
            // regardless of whether the caller declared it.
            $billType = $data['bill_type'] ?? 'expense';
            if ($resolvedPoId || $goodsReceipt) {
                $billType = 'procurement';
            }

            // Procurement bills MUST carry PO or GRN lineage.
            if ($billType === 'procurement' && !$resolvedPoId && !$goodsReceipt) {
                throw new \InvalidArgumentException(
                    "A bill with bill_type 'procurement' must be linked to a purchase_order_id or goods_receipt_id."
                );
            }

            // ── Create bill ───────────────────────────────────────────────────────
            $bill = Bill::create([
                'bill_number'                    => $data['bill_number'] ?? null,
                'vendor_id'                      => $data['vendor_id'],
                'purchase_order_id'              => $resolvedPoId,
                'goods_receipt_id'               => $goodsReceipt?->id,
                'bill_type'                      => $billType,
                'bill_date'                      => $data['bill_date'],
                'due_date'                       => $data['due_date'] ?? null,
                'vendor_reference'               => $data['vendor_reference'] ?? null,
                'internal_reference'             => $data['internal_reference'] ?? null,
                'attention_to'                   => $data['attention_to'] ?? null,
                'contact_email'                  => $data['contact_email'] ?? null,
                'contact_phone'                  => $data['contact_phone'] ?? null,
                'vendor_address_snapshot'        => $data['vendor_address_snapshot'] ?? null,
                'remittance_address_snapshot'    => $data['remittance_address_snapshot'] ?? null,
                'company_registration_number'    => $data['company_registration_number'] ?? null,
                'tax_registration_number'        => $data['tax_registration_number'] ?? null,
                'status'                         => 'draft',
                'notes'                          => $data['notes'] ?? null,
                'terms'                          => $data['terms'] ?? null,
                'discount_amount'                => $data['discount_amount'] ?? 0,
                'subtotal'                       => 0,
                'tax_amount'                     => 0,
                'total'                          => 0,
                'paid_amount'                    => 0,
                'balance'                        => 0,
                'created_by'                     => $createdBy,
            ]);

            // ── Create bill lines ─────────────────────────────────────────────────
            foreach ($data['lines'] as $lineData) {
                $quantity   = (float) $lineData['quantity'];
                $unitPrice  = (float) $lineData['unit_price'];
                $taxRate    = (float) ($lineData['tax_rate'] ?? 0);
                $lineTotal  = $quantity * $unitPrice;
                $taxAmount  = $lineTotal * ($taxRate / 100);

                BillLine::create([
                    'bill_id'                  => $bill->id,
                    'account_id'               => $lineData['account_id'],
                    'description'              => $lineData['description'] ?? null,
                    'quantity'                 => $quantity,
                    'unit_price'               => $unitPrice,
                    'tax_rate'                 => $taxRate,
                    'tax_amount'               => $taxAmount,
                    'line_total'               => $lineTotal,
                    'purchase_order_item_id'   => $lineData['purchase_order_item_id'] ?? null,
                    'goods_receipt_line_id'    => $lineData['goods_receipt_line_id'] ?? null,
                ]);
            }

            // ── Calculate totals ──────────────────────────────────────────────────
            $bill->load('lines');
            $bill->subtotal  = $bill->lines->sum('line_total');
            $bill->tax_amount = $bill->lines->sum('tax_amount');
            $bill->total     = $bill->subtotal + $bill->tax_amount - $bill->discount_amount;
            $bill->balance   = $bill->total - $bill->paid_amount;
            $bill->save();

            // ── FIX-02: Three-way match enforcement for procurement bills ─────────
            // For procurement bills, validate AFTER lines exist so the service can
            // compare bill line quantities/prices against PO/GRN.
            // Unlike the old controller condition (which only ran when PO/GRN was
            // declared), this is now tied to bill_type so it cannot be bypassed by
            // omitting purchase_order_id.
            if ($billType === 'procurement') {
                $bill->load(['purchaseOrder.items.product', 'goodsReceipt.lines.product']);
                $matchResult = $this->threeWayMatchService->validate($bill);
                if (!$matchResult['valid']) {
                    throw new \RuntimeException(
                        'Three-way match validation failed: ' . implode('; ', $matchResult['errors'])
                    );
                }
            }

            return $bill->load(['vendor', 'lines.account', 'purchaseOrder', 'goodsReceipt']);
        });
    }
}

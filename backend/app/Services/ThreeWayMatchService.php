<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\GoodsReceipt;
use Modules\Inventory\Models\PurchaseOrder;

class ThreeWayMatchService
{
    public function validate(Bill $bill, array $tolerances = []): array
    {
        $tolerances = array_merge([
            'quantity_tolerance' => 0,
            'price_tolerance_percent' => 0,
        ], $tolerances);

        if (! $bill->purchase_order_id && ! $bill->goods_receipt_id) {
            return [
                'valid' => false,
                'errors' => ['Bill must be linked to a purchase order or goods receipt for three-way matching.'],
                'warnings' => [],
            ];
        }

        $errors = [];
        $warnings = [];

        $purchaseOrder = $bill->purchaseOrder;
        $goodsReceipt = $bill->goodsReceipt;

        if ($purchaseOrder) {
            $poErrors = $this->validateAgainstPO($bill, $purchaseOrder, $tolerances);
            $errors = array_merge($errors, $poErrors['errors']);
            $warnings = array_merge($warnings, $poErrors['warnings']);
        }

        if ($goodsReceipt) {
            $grnErrors = $this->validateAgainstGRN($bill, $goodsReceipt, $tolerances);
            $errors = array_merge($errors, $grnErrors['errors']);
            $warnings = array_merge($warnings, $grnErrors['warnings']);
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    private function validateAgainstPO(Bill $bill, PurchaseOrder $purchaseOrder, array $tolerances): array
    {
        $errors = [];
        $warnings = [];

        foreach ($bill->lines as $billLine) {
            if (! $billLine->purchase_order_item_id) {
                $errors[] = "Bill line {$billLine->id} is missing purchase order lineage for PO {$purchaseOrder->po_number}.";

                continue;
            }

            $poLine = $purchaseOrder->items->firstWhere('id', $billLine->purchase_order_item_id);

            if (! $poLine) {
                $errors[] = "Bill line references a purchase order item that does not exist on PO {$purchaseOrder->po_number}.";

                continue;
            }

            $qtyVariance = (float) $billLine->quantity - (float) $poLine->quantity_ordered;
            if ($qtyVariance > $tolerances['quantity_tolerance']) {
                $errors[] = "Bill line quantity ({$billLine->quantity}) exceeds PO quantity ({$poLine->quantity_ordered}) for product {$poLine->product?->name}.";
            }

            if ((float) $poLine->unit_cost > 0) {
                $priceVariancePercent = abs((float) $billLine->unit_price - (float) $poLine->unit_cost) / (float) $poLine->unit_cost * 100;
                if ($priceVariancePercent > $tolerances['price_tolerance_percent']) {
                    $errors[] = 'Bill line unit price ('.number_format((float) $billLine->unit_price, 2).') differs from PO price ('.number_format((float) $poLine->unit_cost, 2).') by '.number_format($priceVariancePercent, 1)."% for product {$poLine->product?->name}.";
                }
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function validateAgainstGRN(Bill $bill, GoodsReceipt $goodsReceipt, array $tolerances): array
    {
        $errors = [];
        $warnings = [];

        if ($goodsReceipt->status !== 'confirmed') {
            $errors[] = "Goods receipt {$goodsReceipt->grn_number} is not confirmed (status: {$goodsReceipt->status}).";

            return ['errors' => $errors, 'warnings' => $warnings];
        }

        foreach ($bill->lines as $billLine) {
            if (! $billLine->goods_receipt_line_id) {
                $errors[] = "Bill line {$billLine->id} is missing goods receipt lineage for GRN {$goodsReceipt->grn_number}.";

                continue;
            }

            $grnLine = $goodsReceipt->lines->firstWhere('id', $billLine->goods_receipt_line_id);

            if (! $grnLine) {
                $errors[] = "Bill line references a goods receipt line that does not exist on GRN {$goodsReceipt->grn_number}.";

                continue;
            }

            $qtyVariance = $billLine->quantity - $grnLine->quantity_accepted;
            if ($qtyVariance > $tolerances['quantity_tolerance']) {
                $errors[] = "Bill line quantity ({$billLine->quantity}) exceeds accepted GRN quantity ({$grnLine->quantity_accepted}) for product {$grnLine->product?->name}.";
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }
}

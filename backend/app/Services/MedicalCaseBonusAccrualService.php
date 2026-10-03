<?php

namespace App\Services;

use App\Models\BonusSchemeProduct;
use App\Models\BonusSchemeUsage;
use App\Models\CustomerBonusScheme;
use App\Models\MedicalCase;
use App\Models\MedicalCaseItem;
use Carbon\Carbon;

/**
 * Issue #184: case-count bonus accrual.
 *
 * Per the module spec, accrual is triggered per MedicalCaseItem as
 * delivered_qty is recorded, and fans out over EVERY active
 * CustomerBonusScheme assignment the customer holds whose scheme has the
 * item's product as a qualifying product. qualifying_qty is the newly
 * delivered quantity (the delta), not the item's cumulative total.
 */
class MedicalCaseBonusAccrualService
{
    /**
     * Accrue bonus usage rows for one case item delivery.
     *
     * @return int Number of usage rows created
     */
    public function accrueForItem(MedicalCaseItem $item, float $newlyDeliveredQty): int
    {
        if ($newlyDeliveredQty <= 0) {
            return 0;
        }

        // Issue #563 (MC2): a free-of-charge line never counts toward a
        // scheme — no percentage or fixed-amount discount and no FOC-quantity
        // entitlement (module spec §9 rule 8 marks FOC lines).
        if ($item->is_foc) {
            return 0;
        }

        $case = $item->medicalCase ?: MedicalCase::find($item->medical_case_id);

        if (! $case) {
            return 0;
        }

        $today = Carbon::today();

        $assignments = CustomerBonusScheme::query()
            ->with('bonusScheme')
            ->where('customer_id', $case->customer_id)
            ->where('status', 'active')
            ->get()
            ->filter(function (CustomerBonusScheme $assignment) use ($today): bool {
                $scheme = $assignment->bonusScheme;

                if (! $scheme || $scheme->status !== 'active') {
                    return false;
                }

                if ($scheme->start_date && $scheme->start_date->gt($today)) {
                    return false;
                }

                if ($scheme->end_date && $scheme->end_date->lt($today)) {
                    return false;
                }

                return true;
            });

        $created = 0;

        foreach ($assignments as $assignment) {
            $rule = BonusSchemeProduct::query()
                ->where('bonus_scheme_id', $assignment->bonus_scheme_id)
                ->where('qualifying_product_id', $item->product_id)
                ->where(function ($query) {
                    $query->where('status', 'active')->orWhereNull('status');
                })
                ->orderBy('priority')
                ->first();

            if (! $rule) {
                continue;
            }

            // The minimum-quantity requirement applies to the cumulative
            // delivered quantity for the item.
            if ($rule->minimum_purchase_qty !== null
                && (float) $item->delivered_qty < (float) $rule->minimum_purchase_qty) {
                continue;
            }

            $discount = 0.0;

            if ($rule->discount_amount !== null) {
                $discount = (float) $rule->discount_amount;
            } elseif ($rule->discount_percentage !== null) {
                $discount = round(
                    $newlyDeliveredQty * (float) $item->unit_price * ((float) $rule->discount_percentage / 100),
                    2
                );
            }

            $usage = BonusSchemeUsage::create([
                'customer_bonus_scheme_id' => $assignment->id,
                'sales_order_item_id' => $item->sales_order_item_id,
                'medical_case_item_id' => $item->id,
                'qualifying_qty' => $newlyDeliveredQty,
                'discount_amount' => $discount,
                'foc_qty' => (float) ($rule->foc_qty ?? 0),
                'status' => 'pending',
            ]);

            $usage->apply();

            $created++;
        }

        return $created;
    }
}

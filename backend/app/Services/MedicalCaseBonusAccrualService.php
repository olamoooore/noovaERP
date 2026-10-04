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

        $usageDate = $this->usageDateFor($item, $case);

        $assignments = CustomerBonusScheme::query()
            ->with('bonusScheme')
            ->where('customer_id', $case->customer_id)
            ->where('status', 'active')
            ->get()
            ->filter(fn (CustomerBonusScheme $assignment): bool => $this->assignmentCoversDate($assignment, $usageDate));

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

            $calculation = $rule->calculateDiscount($newlyDeliveredQty, (float) $item->unit_price);

            $usage = BonusSchemeUsage::create([
                'customer_bonus_scheme_id' => $assignment->id,
                'sales_order_item_id' => $item->sales_order_item_id,
                'medical_case_item_id' => $item->id,
                'qualifying_qty' => $newlyDeliveredQty,
                'discount_amount' => round($calculation['discount'], 2),
                'foc_qty' => $calculation['foc_qty'],
                'status' => 'pending',
            ]);

            $usage->apply();

            $created++;
        }

        return $created;
    }

    /**
     * Issue #564 (AC3) — record an FOC case item as a redemption against the
     * customer's active entitlement, shared by the import paths and the
     * #561 redemption flow it feeds.
     *
     * An FOC line never counts toward the qualifying counter, so
     * `qualifying_qty` stays 0 and `foc_qty` carries the redeemed units. The
     * first active assignment (in assignment order) whose scheme is active,
     * in period and has an active rule for the item's product with a FOC
     * quantity absorbs the redemption. Returns null when nothing matches —
     * no scheme is invented and no assignment is created.
     */
    public function recordFocRedemption(MedicalCaseItem $item, float $focQty): ?BonusSchemeUsage
    {
        if ($focQty <= 0) {
            return null;
        }

        $case = $item->medicalCase ?: MedicalCase::find($item->medical_case_id);

        if (! $case) {
            return null;
        }

        $usageDate = $this->usageDateFor($item, $case);

        $assignments = CustomerBonusScheme::query()
            ->with('bonusScheme')
            ->where('customer_id', $case->customer_id)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        foreach ($assignments as $assignment) {
            if (! $this->assignmentCoversDate($assignment, $usageDate)) {
                continue;
            }

            $rule = BonusSchemeProduct::query()
                ->where('bonus_scheme_id', $assignment->bonus_scheme_id)
                ->where('qualifying_product_id', $item->product_id)
                ->where('foc_qty', '>', 0)
                ->where(function ($query) {
                    $query->where('status', 'active')->orWhereNull('status');
                })
                ->orderBy('priority')
                ->first();

            if (! $rule) {
                continue;
            }

            $usage = BonusSchemeUsage::create([
                'customer_bonus_scheme_id' => $assignment->id,
                'sales_order_item_id' => $item->sales_order_item_id,
                'medical_case_item_id' => $item->id,
                'qualifying_qty' => 0,
                'discount_amount' => 0,
                'foc_qty' => $focQty,
                'status' => 'pending',
            ]);

            $usage->apply();

            return $usage;
        }

        return null;
    }

    /**
     * Issue #606 — project the customer's next FOC entitlement from the
     * accrual rows this service already writes.
     *
     * The #559 bonus counter does not exist on main, so the digest's "earns
     * N × Product FOC in ~M cases" line is derived here, in the shared
     * accrual service, from the same data `accrueForItem()` maintains:
     *
     *   - a `buy_x_get_y` rule grants `foc_qty` per `minimum_purchase_qty`
     *     qualifying units (BonusSchemeProduct::calculateDiscount());
     *   - accrued qualifying units = sum of non-reversed usages'
     *     `qualifying_qty`;
     *   - the next entitlement lands `minimum_purchase_qty - (accrued mod
     *     minimum_purchase_qty)` qualifying units from now;
     *   - "cases" approximates that quantity at the customer's average
     *     qualifying units per case so far (1 unit/case when there is no
     *     history) — hence the `~` in the digest line.
     *
     * The most imminent rule across the given customers wins (smallest
     * remaining quantity; lowest customer id breaks a tie). Returns null when
     * no active rule is in progress.
     *
     * @param  int[]  $customerIds
     * @return array{customer_id: int, customer_name: string, product_id: ?int, product_name: string, foc_qty: float, remaining_qty: float, cases: int}|null
     */
    public function nextFocDueForCustomers(array $customerIds): ?array
    {
        if ($customerIds === []) {
            return null;
        }

        $assignments = CustomerBonusScheme::query()
            ->with(['bonusScheme', 'customer'])
            ->whereIn('customer_id', $customerIds)
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->filter(fn (CustomerBonusScheme $assignment): bool => $this->assignmentCoversDate($assignment, Carbon::today()));

        if ($assignments->isEmpty()) {
            return null;
        }

        $usagesByAssignment = BonusSchemeUsage::query()
            ->whereIn('customer_bonus_scheme_id', $assignments->pluck('id')->all())
            ->where('status', '!=', 'reversed')
            ->get(['customer_bonus_scheme_id', 'qualifying_qty'])
            ->groupBy('customer_bonus_scheme_id');

        $best = null;

        foreach ($assignments as $assignment) {
            $rules = BonusSchemeProduct::query()
                ->with(['focProduct', 'qualifyingProduct'])
                ->where('bonus_scheme_id', $assignment->bonus_scheme_id)
                ->where('scheme_type', 'buy_x_get_y')
                ->where('foc_qty', '>', 0)
                ->where(function ($query) {
                    $query->where('status', 'active')->orWhereNull('status');
                })
                ->orderBy('priority')
                ->get();

            if ($rules->isEmpty()) {
                continue;
            }

            $usages = $usagesByAssignment->get($assignment->id, collect());
            $accrued = (float) $usages->sum('qualifying_qty');
            $qualifyingCases = $usages->filter(fn ($usage): bool => (float) $usage->qualifying_qty > 0)->count();

            foreach ($rules as $rule) {
                $minimum = (float) ($rule->minimum_purchase_qty ?? 0);

                if ($minimum <= 0) {
                    continue;
                }

                $remainder = fmod($accrued, $minimum);
                $remainingQty = $remainder > 0 ? $minimum - $remainder : $minimum;
                $averagePerCase = $qualifyingCases > 0 ? $accrued / $qualifyingCases : 1.0;
                $cases = (int) max(1, (int) ceil($remainingQty / max($averagePerCase, 0.0001)));

                $candidate = [
                    'customer_id' => (int) $assignment->customer_id,
                    'customer_name' => $assignment->customer?->name ?? 'Customer',
                    'product_id' => $rule->foc_product_id !== null
                        ? (int) $rule->foc_product_id
                        : (int) $rule->qualifying_product_id,
                    'product_name' => $rule->focProduct?->name
                        ?? $rule->qualifyingProduct?->name
                        ?? 'Product',
                    'foc_qty' => (float) $rule->foc_qty,
                    'remaining_qty' => round($remainingQty, 4),
                    'cases' => $cases,
                ];

                if ($best === null
                    || $candidate['remaining_qty'] < $best['remaining_qty']
                    || ($candidate['remaining_qty'] === $best['remaining_qty']
                        && $candidate['customer_id'] < $best['customer_id'])) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    /**
     * Issue #572 (BS6): the usage date validity is judged against — the case's
     * own business date when set, else the linked delivery order's delivery
     * date, else today. Never `today` when the document carries a date, so
     * back-dated and imported usage is judged by the scheme in force then.
     */
    private function usageDateFor(MedicalCaseItem $item, MedicalCase $case): Carbon
    {
        if ($case->case_date) {
            return $case->case_date->copy()->startOfDay();
        }

        $order = $item->deliveryOrderItem?->deliveryOrder;

        if ($order?->delivery_date) {
            return $order->delivery_date->copy()->startOfDay();
        }

        if ($order?->delivered_at) {
            return Carbon::parse($order->delivered_at)->startOfDay();
        }

        return Carbon::today();
    }

    /**
     * Issue #572 (BS6): a rule applies only when the scheme is active and both
     * the scheme's and the customer's assignment date windows contain the
     * usage date — the assignment dates were previously ignored.
     */
    private function assignmentCoversDate(CustomerBonusScheme $assignment, Carbon $usageDate): bool
    {
        $scheme = $assignment->bonusScheme;

        if (! $scheme || $scheme->status !== 'active') {
            return false;
        }

        foreach ([$assignment, $scheme] as $window) {
            if ($window->start_date && $window->start_date->gt($usageDate)) {
                return false;
            }

            if ($window->end_date && $window->end_date->lt($usageDate)) {
                return false;
            }
        }

        return true;
    }
}

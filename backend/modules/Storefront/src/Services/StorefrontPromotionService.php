<?php

declare(strict_types=1);

namespace Modules\Storefront\Services;

use Illuminate\Support\Carbon;
use Modules\Storefront\Exceptions\CheckoutException;
use Modules\Storefront\Models\StorefrontPromotion;
use Modules\Storefront\Models\StorefrontPromotionRedemption;

/**
 * FR-CO-09 (P3): server-validated discounts/coupons, single-use per order.
 *
 * The ONLY authority for whether a code applies and what it deducts. The
 * browser submits a code (never an amount); every number is recomputed from
 * the canonical cart quote. A whole-order discount is spread PRO-RATA across
 * the lines so per-line tax stays internally consistent (tax = gross - net)
 * and the aggregate totals equal the sum of lines — which is what the
 * PostingEngine tax determination reads on completion. There is no second
 * pricing/tax path here (MRD non-negotiable): the discount scales the
 * canonical per-line net/gross the quote already produced.
 */
final class StorefrontPromotionService
{
    /**
     * @param  array<string,mixed>  $quote  output of CartService::quote()
     * @return array{promotion: StorefrontPromotion, discount: float, free_shipping: bool}
     *
     * @throws CheckoutException when the code is unknown/inactive/out of
     *                           window/below threshold/at its usage cap for this buyer.
     */
    public function evaluate(string $code, array $quote, ?int $accountId): array
    {
        $promotion = StorefrontPromotion::query()
            ->where('code', StorefrontPromotion::normalizeCode($code))
            ->first();

        if ($promotion === null || ! $promotion->is_active) {
            throw new CheckoutException('promotion_not_found', 'That promo code is not valid.', 422);
        }

        $now = Carbon::now();
        if ($promotion->starts_at !== null && $promotion->starts_at->gt($now)) {
            throw new CheckoutException('promotion_not_started', 'That promo code is not active yet.', 422);
        }
        if ($promotion->ends_at !== null && $promotion->ends_at->lt($now)) {
            throw new CheckoutException('promotion_expired', 'That promo code has expired.', 422);
        }

        $subtotal = round((float) $quote['totals']['subtotal'], 2);

        if ($promotion->min_subtotal !== null && $subtotal < round((float) $promotion->min_subtotal, 2)) {
            throw new CheckoutException('promotion_min_subtotal', 'Your cart does not meet the minimum for that promo code.', 422);
        }

        $this->assertUsageCaps($promotion, $accountId);

        $discount = match ($promotion->type) {
            'percent' => round($subtotal * ((float) $promotion->value / 100), 2),
            'fixed' => min(round((float) $promotion->value, 2), $subtotal),
            'free_shipping' => 0.0,
            default => 0.0,
        };

        return [
            'promotion' => $promotion,
            'discount' => $discount,
            'free_shipping' => $promotion->type === 'free_shipping',
        ];
    }

    /**
     * Apply an evaluated discount to the quote: every line net & gross scale
     * by (1 - r) where r = discount / subtotal (uniform, so line tax = gross
     * - net stays valid and rounding reconciles on the last line). Returns
     * adjusted line rows + the authoritative order-level totals.
     *
     * @param  array<string,mixed>  $quote
     * @return array{items:array<int,array<string,mixed>>, totals:array<string,string>, discount:string, free_shipping:bool}
     */
    public function applyToQuote(array $quote, array $evaluation): array
    {
        $subtotal = round((float) $quote['totals']['subtotal'], 2);
        $discount = round((float) $evaluation['discount'], 2);
        $ratio = $subtotal > 0 ? $discount / $subtotal : 0.0;
        $keep = 1 - $ratio;

        $items = $quote['items'];
        $lineDiscounts = [];
        $remaining = $discount;
        $count = count($items);

        foreach ($items as $i => &$line) {
            $originalGross = round((float) $line['line_total'], 2);
            $originalTax = round((float) $line['tax_amount'], 2);
            $originalNet = round($originalGross - $originalTax, 2);

            // Rounding reconciles on the final line so Σ line discount == order discount.
            $share = $i === $count - 1 ? $remaining : round($originalNet * $ratio, 2);
            $share = min($share, $remaining);
            $remaining = round($remaining - $share, 2);

            $newNet = round($originalNet - $share, 2);
            $newGross = round($originalGross * $keep, 2);
            if ($newGross < $newNet) {
                $newGross = $newNet;
            }
            $newTax = round($newGross - $newNet, 2);

            $line['discount_amount'] = number_format($share, 2, '.', '');
            $line['line_net'] = number_format($newNet, 2, '.', '');
            $line['tax_amount'] = number_format($newTax, 2, '.', '');
            $line['line_total'] = number_format($newGross, 2, '.', '');
            $lineDiscounts[] = $share;
        }
        unset($line);

        $newSubtotal = $subtotal - $discount;               // discounted NET
        $newTax = round(array_sum(array_map(fn ($l) => (float) $l['tax_amount'], $items)), 2);
        $newTotal = round($newSubtotal + $newTax, 2);

        return [
            'items' => $items,
            'discount' => number_format($discount, 2, '.', ''),
            'free_shipping' => (bool) $evaluation['free_shipping'],
            'totals' => [
                // subtotal reported at the order level is the DISCOUNTED net;
                // order.discount_amount records the reduction, so the JE
                // (CR revenue=subtotal_pre? see CheckoutService) stays balanced.
                'subtotal' => number_format($subtotal, 2, '.', ''),
                'net_after_discount' => number_format($newSubtotal, 2, '.', ''),
                'tax' => number_format($newTax, 2, '.', ''),
                'total' => number_format($newTotal, 2, '.', ''),
            ],
            'tax_breakdown' => $discount > 0 ? $this->rebuildBreakdown($items) : $quote['tax_breakdown'],
        ];
    }

    /**
     * Single-use guard + counter enforcement. Usage counters are DERIVED from
     * committed redemptions (never a mutable balance column), and the order's
     * own UNIQUE(promotion_id, order_id) redemption index prevents an order
     * double-redeeming the same code.
     */
    private function assertUsageCaps(StorefrontPromotion $promotion, ?int $accountId): void
    {
        if ($promotion->usage_limit !== null) {
            $used = StorefrontPromotionRedemption::query()
                ->where('promotion_id', $promotion->id)
                ->count();
            if ($used >= (int) $promotion->usage_limit) {
                throw new CheckoutException('promotion_exhausted', 'That promo code has reached its usage limit.', 422);
            }
        }

        if ($promotion->per_account_limit !== null && $accountId !== null) {
            $usedByAccount = StorefrontPromotionRedemption::query()
                ->where('promotion_id', $promotion->id)
                ->where('account_id', $accountId)
                ->count();
            if ($usedByAccount >= (int) $promotion->per_account_limit) {
                throw new CheckoutException('promotion_per_account_limit', 'You have already used that promo code the maximum number of times.', 422);
            }
        }
    }

    /**
     * Rebuild the tax_breakdown from discounted line tax amounts, keyed by
     * rate, so response tax lines still sum to the authoritative discounted
     * tax total. Used only when a discount changed the tax.
     *
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function rebuildBreakdown(array $items): array
    {
        $byRate = [];
        foreach ($items as $line) {
            $rateKey = (string) $line['tax_rate'];
            $byRate[$rateKey] ??= ['rate' => (float) $rateKey, 'tax' => 0.0];
            $byRate[$rateKey]['tax'] += (float) $line['tax_amount'];
        }

        return array_values(array_map(static fn (array $b): array => [
            'rate' => $b['rate'],
            'tax' => number_format(round($b['tax'], 2), 2, '.', ''),
        ], $byRate));
    }
}

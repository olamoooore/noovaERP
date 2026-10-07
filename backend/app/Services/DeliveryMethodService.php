<?php

namespace App\Services;

use App\Models\DeliveryMethod;

/**
 * Issue #409: turns a delivery method into ONE shipping line for a quotation
 * or sales order.
 *
 * This class only decides the price (fixed / percentage of the untaxed order
 * amount / free above a threshold). The returned line is then handled exactly
 * like any typed line by its caller: tax through SalesLineTaxResolver (#302)
 * at the document date, price provenance through PricingService (carried
 * mode, with a delivery-method trace), and from there quote → order → invoice
 * and PostingEngine like every other line.
 */
class DeliveryMethodService
{
    public function __construct(private PricingService $pricing) {}

    /**
     * Untaxed amount the method prices against: the document's selected
     * product lines, net of line discount and tax, excluding any existing
     * shipping line and any optional (unselected) line.
     *
     * @param  iterable<object>  $lines  quotation or sales-order lines
     */
    public function untaxedBase(iterable $lines): float
    {
        $base = 0.0;
        foreach ($lines as $line) {
            if (in_array($line->line_type ?? 'product', ['note', 'section'], true)
                || (bool) ($line->is_optional ?? false)
                || (bool) ($line->is_delivery ?? false)) {
                continue;
            }
            $base += (float) $line->line_total - (float) $line->tax_amount;
        }

        return $base;
    }

    /**
     * Build the shipping line (before tax resolution) for a document.
     *
     * Money on the method (fixed price, free threshold) is in the tenant base
     * currency; the document's own FX snapshot (base = document × rate)
     * converts it — the delivery charge never re-derives the document rate.
     *
     * @param  iterable<object>  $lines
     * @return array<string, mixed>
     */
    public function shippingLine(
        DeliveryMethod $method,
        iterable $lines,
        string $documentCurrency,
        string $baseCurrency,
        float $exchangeRate,
    ): array {
        $foreign = strtoupper($documentCurrency) !== strtoupper($baseCurrency) && $exchangeRate > 0;
        $baseDoc = $this->untaxedBase($lines);
        $baseInBase = $foreign ? $baseDoc * $exchangeRate : $baseDoc;

        $free = $method->free_over_amount !== null && $baseInBase >= (float) $method->free_over_amount;

        if ($free) {
            $price = 0.0;
        } elseif ($method->pricing_type === DeliveryMethod::PRICING_PERCENTAGE) {
            $price = $baseDoc * (float) $method->percentage / 100;
        } else {
            $price = $foreign ? (float) $method->fixed_price / $exchangeRate : (float) $method->fixed_price;
        }

        $price = $this->pricing->roundToMinor($price, $documentCurrency);

        return [
            'line_type' => 'product',
            'product_id' => (int) $method->product_id,
            'description' => $method->name.($free ? ' (free shipping)' : ''),
            'quantity' => 1,
            'unit_price' => $price,
            'price_mode' => 'carried',
            'is_delivery' => true,
            'pricing_trace' => [
                'source' => 'delivery_method',
                'delivery_method_id' => (int) $method->id,
                'pricing_type' => $method->pricing_type,
                'fixed_price' => (float) $method->fixed_price,
                'percentage' => (float) $method->percentage,
                'untaxed_base' => round($baseDoc, 4),
                'free_over_amount' => $method->free_over_amount !== null ? (float) $method->free_over_amount : null,
                'free' => $free,
                'currency' => strtoupper($documentCurrency),
                'rate_used' => $exchangeRate,
            ],
        ];
    }
}

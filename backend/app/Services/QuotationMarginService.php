<?php

namespace App\Services;

use App\Models\Quotation;
use App\Models\QuotationLine;
use Illuminate\Support\Facades\Auth;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;

/**
 * Issue #338 ([SALES-ODOO]): margin per quotation line and for the document.
 *
 * Cost basis = the one the COGS posting falls back to when no lot layer is
 * consumed: {@see BundleDecompositionService::cost()} — the product's
 * weighted-average cost (#107, maintained by {@see ProductCostingService}),
 * summed over components for a bundle. Quantities in a sales UoM are first
 * converted to stock units through {@see ProductUomConverter::toStock()}, the
 * same rule every stock/COGS path uses. A quotation is forward-looking, so
 * this is the cost the sale WOULD be posted at today; the historical-COGS
 * question in #359 concerns margins of sales already posted, not quotes.
 *
 * Cost is held in base currency; line revenue is in the quotation's document
 * currency, so cost is re-expressed in document currency through the
 * quotation's own rate snapshot (base = document × exchange_rate).
 *
 * Visibility: only roles holding the existing `pricelists.cost` capability
 * see margins (and the product cost behind them) — the same literal the price
 * list and pricing endpoints already mask cost with.
 */
class QuotationMarginService
{
    public const COST_PERMISSION = 'pricelists.cost';

    public function __construct(
        private BundleDecompositionService $bundles,
        private ProductUomConverter $uoms,
    ) {}

    /**
     * Attach margin figures to a loaded quotation (lines.product) for a role
     * with the cost capability, or strip product cost for one without.
     */
    public function present(Quotation $quotation): void
    {
        $quotation->loadMissing('lines.product');

        if (! $this->actorMaySeeCost()) {
            foreach ($quotation->lines as $line) {
                $line->product?->makeHidden(['cost', 'last_cost']);
            }

            return;
        }

        $margin = $this->calculate($quotation);

        foreach ($quotation->lines as $line) {
            if (! isset($margin['lines'][$line->id])) {
                continue;
            }

            $row = $margin['lines'][$line->id];
            $line->setAttribute('cost_amount', $row['cost']);
            $line->setAttribute('margin_amount', $row['margin']);
            $line->setAttribute('margin_percent', $row['margin_percent']);
        }

        $quotation->setAttribute('margin', $margin['total']);
    }

    /**
     * Issue #412: also used for a sales order (the order health panel) —
     * same cost basis, same revenue rule; a sales order has no optional
     * lines, so every line counts.
     *
     * @return array{lines: array<int, array{revenue: float, cost: float, margin: float, margin_percent: float|null}>, total: array{revenue: float, cost: float, margin: float, margin_percent: float|null, currency_code: string|null}}
     */
    public function calculate(Quotation|SalesOrder $quotation): array
    {
        $rate = (float) ($quotation->exchange_rate ?? 1);
        $rate = $rate > 0 ? $rate : 1.0;

        $lines = [];
        $documentCost = 0.0;

        foreach ($quotation->lines as $line) {
            if (in_array($line->line_type ?? 'product', ['note', 'section'], true)) {
                continue;
            }

            $revenue = round((float) $line->line_total - (float) $line->tax_amount, 2);
            $cost = round($this->baseCost($line) / $rate, 2);
            $marginAmount = round($revenue - $cost, 2);

            $lines[$line->id] = [
                'revenue' => $revenue,
                'cost' => $cost,
                'margin' => $marginAmount,
                'margin_percent' => $revenue != 0.0 ? round($marginAmount / $revenue * 100, 2) : null,
            ];

            // Optional lines show their own margin but, like the totals,
            // do not count until the customer selects them.
            if (! $line->is_optional) {
                $documentCost += $cost;
            }
        }

        // Document revenue is the net the customer pays before tax: the
        // subtotal of the counted lines less the header discount.
        $documentRevenue = round(max((float) $quotation->subtotal - (float) ($quotation->discount_amount ?? 0), 0), 2);
        $documentCost = round($documentCost, 2);
        $documentMargin = round($documentRevenue - $documentCost, 2);

        return [
            'lines' => $lines,
            'total' => [
                'revenue' => $documentRevenue,
                'cost' => $documentCost,
                'margin' => $documentMargin,
                'margin_percent' => $documentRevenue != 0.0 ? round($documentMargin / $documentRevenue * 100, 2) : null,
                'currency_code' => $quotation->currency_code,
            ],
        ];
    }

    /**
     * The line's cost in base currency, on the COGS cost basis.
     */
    private function baseCost(QuotationLine|SalesOrderLine $line): float
    {
        $product = $line->product;

        if (! $product) {
            return 0.0;
        }

        $quantity = (float) $line->quantity;

        if ($line->uom_id) {
            $uom = $this->uoms->resolveOrNull((int) $product->id, (int) $line->uom_id);
            if ($uom && ! $uom->isStockUnit()) {
                $quantity = $this->uoms->toStock($uom, $quantity);
            }
        }

        return $this->bundles->cost($product, $quantity);
    }

    public function actorMaySeeCost(): bool
    {
        $user = Auth::user();

        if (! $user) {
            return false;
        }

        if ($user->is_super_admin ?? false) {
            return true;
        }

        try {
            return app(TenantRoleService::class)
                ->hasPermission((int) $user->id, (string) tenant('id'), self::COST_PERMISSION);
        } catch (\Throwable) {
            // Fail closed: an unresolvable permission hides cost.
            return false;
        }
    }
}

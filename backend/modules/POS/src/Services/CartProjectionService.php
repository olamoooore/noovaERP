<?php

namespace Modules\POS\Services;

use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosOrder;
use Modules\POS\Models\PosOrderItem;

class CartProjectionService
{
    /**
     * Build a customer-safe cart projection for the given session.
     * Only returns confirmed cart/order lines — never raw cashier state.
     *
     * @return array{currency:string,item_count:int,items:array,totals:array}
     */
    public function build(PosSession $session): array
    {
        // Find the most recent open/draft order for the session
        $order = PosOrder::where('session_id', $session->id)
            ->whereIn('status', ['draft', 'pending', 'open'])
            ->latest()
            ->first();

        if (! $order) {
            return $this->emptyCart();
        }

        $items = PosOrderItem::where('pos_order_id', $order->id)
            ->where('quantity', '>', 0)
            ->get();

        $itemLines = $items->map(fn ($item) => [
            'line_id'        => 'L-' . $item->id,
            'sku'            => $item->sku ?? '',
            'name'           => $item->product_name ?? $item->name ?? '',
            'qty'            => (int) $item->quantity,
            'unit_price'     => (float) ($item->unit_price ?? $item->price ?? 0),
            'line_total'     => (float) ($item->line_total ?? ($item->quantity * ($item->unit_price ?? 0))),
            'discount_label' => $item->discount_amount > 0
                ? '-' . number_format($item->discount_amount, 2)
                : null,
        ])->values()->all();

        $subtotal = collect($itemLines)->sum('line_total');
        $taxAmount = (float) ($order->tax_amount ?? 0);
        $discountAmount = (float) ($order->discount_amount ?? 0);
        $grandTotal = (float) ($order->total ?? ($subtotal + $taxAmount - $discountAmount));

        return [
            'currency'   => $order->currency ?? 'MYR',
            'item_count' => count($itemLines),
            'items'      => $itemLines,
            'totals'     => [
                'subtotal'    => round($subtotal, 2),
                'discount'    => round($discountAmount, 2),
                'tax'         => round($taxAmount, 2),
                'grand_total' => round($grandTotal, 2),
            ],
        ];
    }

    private function emptyCart(): array
    {
        return [
            'currency'   => 'MYR',
            'item_count' => 0,
            'items'      => [],
            'totals'     => [
                'subtotal'    => 0.00,
                'discount'    => 0.00,
                'tax'         => 0.00,
                'grand_total' => 0.00,
            ],
        ];
    }
}

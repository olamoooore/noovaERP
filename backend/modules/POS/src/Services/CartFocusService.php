<?php

namespace Modules\POS\Services;

use Modules\POS\Models\PosSession;
use Modules\POS\Models\PosFocusHistory;

class CartFocusService
{
    /**
     * Resolve the cart_focus object for CART_ACTIVE state.
     *
     * Priority:
     *  1. Most recently added line
     *  2. Most recently quantity-changed line
     *  3. Most recently removed line (brief removal feedback)
     *  4. Null if cart is empty
     */
    public function resolve(PosSession $session, array $cartItems, string $focusReason = 'item_added', ?array $focusItem = null): ?array
    {
        if (empty($cartItems)) {
            return null;
        }

        // Use provided focus item if given (e.g. from the triggering event)
        $item = $focusItem ?? end($cartItems);

        if (! $item) {
            return null;
        }

        $statusLabel = match ($focusReason) {
            'item_added'      => 'Latest scanned',
            'qty_changed'     => 'Quantity updated',
            'item_removed'    => 'Removed from order',
            default           => 'Latest scanned',
        };

        $imageUrl = $this->resolveImageUrl($item);

        // Log focus history
        PosFocusHistory::create([
            'session_id'   => $session->id,
            'line_id'      => $item['line_id'] ?? ('L-' . ($item['id'] ?? 0)),
            'product_id'   => $item['product_id'] ?? $item['id'] ?? null,
            'focus_reason' => $focusReason,
            'status_label' => $statusLabel,
            'image_url'    => $imageUrl,
            'created_at'   => now(),
        ]);

        return [
            'mode'          => 'LATEST_ACTIVE_LINE',
            'line_id'       => $item['line_id'] ?? ('L-' . ($item['id'] ?? 0)),
            'product_id'    => $item['product_id'] ?? $item['id'] ?? null,
            'sku'           => $item['sku'] ?? '',
            'name'          => $item['name'] ?? '',
            'variant_label' => $item['variant_label'] ?? null,
            'qty'           => (int) ($item['qty'] ?? $item['quantity'] ?? 1),
            'unit_price'    => (float) ($item['unit_price'] ?? $item['price'] ?? 0),
            'line_total'    => (float) ($item['line_total'] ?? 0),
            'image_url'     => $imageUrl,
            'status_label'  => $statusLabel,
            'focus_reason'  => $focusReason,
        ];
    }

    private function resolveImageUrl(array $item): ?string
    {
        $raw = $item['image_url'] ?? $item['image'] ?? null;

        if (! $raw) {
            return null;
        }

        // Ensure URL is absolute; reject local filesystem paths
        if (filter_var($raw, FILTER_VALIDATE_URL)) {
            return $raw;
        }

        // Relative path — prefix with app URL
        $appUrl = rtrim(config('app.url', ''), '/');
        return $appUrl . '/storage/' . ltrim($raw, '/');
    }
}

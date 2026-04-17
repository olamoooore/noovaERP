<?php

namespace Modules\POS\Services;

use App\Models\Customer;
use Modules\POS\Models\PosMemberDisplayProfile;

class MemberDisplayProfileService
{
    /**
     * Build or refresh the customer-safe member profile for the display.
     * Limits to display_name, one favorite_order, and 3 recent summarized orders.
     */
    public function buildForSession(int $memberId): ?array
    {
        $customer = Customer::find($memberId);
        if (! $customer) {
            return null;
        }

        $profile = PosMemberDisplayProfile::firstOrNew(['member_id' => $memberId]);

        // Use short display name (first name only for privacy)
        $displayName = $this->shortName($customer->name);

        // Refresh recent orders summary (last 3 completed orders)
        $recentOrders = $this->buildRecentOrders($memberId);
        $favoriteOrder = $this->buildFavoriteOrder($memberId);

        $profile->fill([
            'display_name'          => $displayName,
            'favorite_order_label'  => $favoriteOrder['label'] ?? null,
            'favorite_order_json'   => $favoriteOrder,
            'recent_orders_json'    => $recentOrders,
        ]);
        $profile->save();

        return [
            'enabled'        => true,
            'is_identified'  => true,
            'display_name'   => $displayName,
            'favorite_order' => $favoriteOrder,
            'recent_orders'  => $recentOrders,
        ];
    }

    public function unidentified(): array
    {
        return [
            'enabled'        => false,
            'is_identified'  => false,
            'display_name'   => null,
            'favorite_order' => null,
            'recent_orders'  => [],
        ];
    }

    private function shortName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? $fullName;
    }

    private function buildRecentOrders(int $memberId): array
    {
        // Pull last 3 paid POS orders for this customer
        $orders = \Modules\POS\Models\PosOrder::where('customer_id', $memberId)
            ->where('status', 'paid')
            ->orderByDesc('paid_at')
            ->limit(3)
            ->get();

        return $orders->map(function ($order) {
            $itemNames = \Modules\POS\Models\PosOrderItem::where('pos_order_id', $order->id)
                ->pluck('name')
                ->filter()
                ->take(2)
                ->implode(' + ');

            return [
                'label'      => $itemNames ?: 'Order #' . $order->order_number,
                'ordered_at' => $order->paid_at?->toDateString() ?? $order->created_at->toDateString(),
            ];
        })->values()->all();
    }

    private function buildFavoriteOrder(int $memberId): ?array
    {
        // Most ordered item combination (top 2 items from most frequent order)
        $topItems = \Modules\POS\Models\PosOrderItem::whereHas('order', function ($q) use ($memberId) {
                $q->where('customer_id', $memberId)->where('status', 'paid');
            })
            ->select('name')
            ->selectRaw('COUNT(*) as cnt')
            ->groupBy('name')
            ->orderByDesc('cnt')
            ->limit(2)
            ->pluck('name');

        if ($topItems->isEmpty()) {
            return null;
        }

        return [
            'label'         => $topItems->implode(' + '),
            'product_count' => $topItems->count(),
        ];
    }
}

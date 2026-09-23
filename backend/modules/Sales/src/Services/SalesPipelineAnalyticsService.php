<?php

namespace Modules\Sales\Services;

use App\Models\Quotation;
use App\Models\User;
use Carbon\Carbon;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesOrderLine;

/**
 * Issue #150: sales-pipeline analytics (pre-invoice). The FinancialReporting
 * module's SalesAnalyticsService only covers posted revenue; this service
 * answers the sales-management questions: pipeline by status, quotation win
 * rate and aging, expected revenue by month, salesperson performance and the
 * product mix of open orders.
 */
class SalesPipelineAnalyticsService
{
    /** Order statuses that still represent open pipeline value. */
    public const OPEN_STATUSES = ['draft', 'confirmed', 'allocated', 'fulfilled'];

    /** Order statuses that count as won business. */
    public const WON_STATUSES = ['invoiced'];

    public function pipeline(Carbon $from, Carbon $to): array
    {
        $orders = SalesOrder::query()
            ->whereBetween('order_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $quotations = Quotation::query()
            ->whereBetween('quotation_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $byStatus = $orders
            ->groupBy('status')
            ->map(fn ($group, $status) => [
                'status' => $status,
                'count' => $group->count(),
                'value' => round((float) $group->sum('total'), 2),
            ])
            ->values()
            ->all();

        $openOrders = $orders->whereIn('status', self::OPEN_STATUSES);
        $wonOrders = $orders->whereIn('status', self::WON_STATUSES);

        // ── Quotation win rate + aging ───────────────────────────────
        $wonQuotations = $quotations->whereIn('status', ['confirmed', 'accepted'])->count();
        $lostQuotations = $quotations->where('status', 'cancelled')->count();
        $decided = $wonQuotations + $lostQuotations;

        $openQuotations = $quotations->whereIn('status', ['draft', 'sent']);

        $aging = ['0_7_days' => 0, '8_30_days' => 0, '31_60_days' => 0, 'over_60_days' => 0];

        foreach ($openQuotations as $quotation) {
            $days = (int) Carbon::parse($quotation->quotation_date)->diffInDays(now(), absolute: true);

            if ($days <= 7) {
                $aging['0_7_days']++;
            } elseif ($days <= 30) {
                $aging['8_30_days']++;
            } elseif ($days <= 60) {
                $aging['31_60_days']++;
            } else {
                $aging['over_60_days']++;
            }
        }

        // ── Expected revenue by order month (open pipeline) ──────────
        $forecast = $openOrders
            ->groupBy(fn (SalesOrder $order) => Carbon::parse($order->order_date)->format('Y-m'))
            ->map(fn ($group, $month) => [
                'month' => $month,
                'count' => $group->count(),
                'value' => round((float) $group->sum('total'), 2),
            ])
            ->sortKeys()
            ->values()
            ->all();

        // ── Salesperson performance (by order creator) ───────────────
        $userNames = User::whereIn('id', $orders->pluck('created_by')->filter()->unique())
            ->pluck('name', 'id');

        $salespeople = $orders
            ->groupBy('created_by')
            ->map(function ($group, $userId) use ($userNames) {
                return [
                    'user_id' => $userId !== '' ? (int) $userId : null,
                    'name' => $userNames[$userId] ?? 'Unassigned',
                    'orders' => $group->count(),
                    'value' => round((float) $group->sum('total'), 2),
                    'won' => $group->whereIn('status', self::WON_STATUSES)->count(),
                ];
            })
            ->sortByDesc('value')
            ->values()
            ->all();

        // ── Product mix of open orders ───────────────────────────────
        $productMix = SalesOrderLine::query()
            ->with('product:id,name,sku')
            ->whereIn('sales_order_id', $openOrders->pluck('id'))
            ->get()
            ->groupBy('product_id')
            ->map(function ($lines, $productId) {
                $first = $lines->first();

                return [
                    'product_id' => $productId !== '' ? (int) $productId : null,
                    'product_name' => $first?->product?->name,
                    'sku' => $first?->product?->sku,
                    'quantity' => round((float) $lines->sum('quantity'), 4),
                    'value' => round((float) $lines->sum('line_total'), 2),
                ];
            })
            ->sortByDesc('value')
            ->values()
            ->all();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'orders_by_status' => $byStatus,
            'pipeline' => [
                'open_orders' => $openOrders->count(),
                'open_value' => round((float) $openOrders->sum('total'), 2),
                'won_orders' => $wonOrders->count(),
                'won_value' => round((float) $wonOrders->sum('total'), 2),
            ],
            'quotations' => [
                'total' => $quotations->count(),
                'open' => $openQuotations->count(),
                'won' => $wonQuotations,
                'lost' => $lostQuotations,
                'win_rate_pct' => $decided > 0 ? round(($wonQuotations / $decided) * 100, 1) : null,
                'aging' => $aging,
            ],
            'expected_revenue_by_month' => $forecast,
            'salespeople' => $salespeople,
            'product_mix' => $productMix,
        ];
    }
}

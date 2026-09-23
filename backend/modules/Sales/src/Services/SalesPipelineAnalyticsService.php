<?php

namespace Modules\Sales\Services;

use App\Models\Quotation;
use App\Models\User;
use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
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

    /**
     * @param  array<int, int>  $accessibleEntityIds  Issue #150: entity scope.
     *                                                Empty = legacy unfiltered.
     */
    public function pipeline(Carbon $from, Carbon $to, array $accessibleEntityIds = []): array
    {
        $baseCurrency = 'USD';
        try {
            $baseCurrency = (string) (DB::table('company_settings')->value('base_currency') ?: 'USD');
        } catch (\Throwable) {
            // company_settings absent (partial schema) — fall back to USD.
        }

        $orders = SalesOrder::query()
            ->whereBetween('order_date', [$from->toDateString(), $to->toDateString()])
            ->when($accessibleEntityIds !== [], fn ($query) => $query->accessibleBy($accessibleEntityIds))
            ->get();

        $quotations = Quotation::query()
            ->whereBetween('quotation_date', [$from->toDateString(), $to->toDateString()])
            ->when(
                $accessibleEntityIds !== [],
                fn ($query) => $query->whereHas(
                    'customer',
                    fn ($customer) => $customer->whereIn('legal_entity_id', $accessibleEntityIds)
                )
            )
            ->get();

        // Issue #150 (hostile re-review): order values can be in different
        // currencies — converting each to the tenant base currency before
        // summing. A document with no available rate is excluded from the
        // converted sums and surfaced under `unconverted`.
        $rateService = app(ExchangeRateService::class);
        $unconverted = [];

        $convertTotal = function ($document, Carbon $asOf) use ($rateService, $baseCurrency, &$unconverted): float {
            $currency = (string) ($document->currency_code ?? $document->currency ?? $baseCurrency);

            if ($currency === '' || $currency === $baseCurrency) {
                return (float) $document->total;
            }

            try {
                $result = $rateService->convert((float) $document->total, $currency, $baseCurrency, $asOf->toDateString());

                return (float) $result['converted_amount'];
            } catch (\Throwable) {
                $unconverted[$currency] = round(($unconverted[$currency] ?? 0) + (float) $document->total, 2);

                return 0.0;
            }
        };

        foreach ($orders as $order) {
            $order->setAttribute('base_total', $convertTotal($order, Carbon::parse($order->order_date)));
        }

        foreach ($quotations as $quotation) {
            // Quotations carry no currency column — treated as base currency.
            $quotation->setAttribute('base_total', (float) $quotation->total);
        }

        $sumBase = fn ($collection) => round((float) $collection->sum(fn ($d) => (float) ($d->base_total ?? 0)), 2);

        $byStatus = $orders
            ->groupBy('status')
            ->map(fn ($group, $status) => [
                'status' => $status,
                'count' => $group->count(),
                'value' => $sumBase($group),
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
                'value' => $sumBase($group),
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
                    'value' => round((float) $group->sum(fn ($d) => (float) ($d->base_total ?? 0)), 2),
                    'won' => $group->whereIn('status', self::WON_STATUSES)->count(),
                ];
            })
            ->sortByDesc('value')
            ->values()
            ->all();

        // ── Product mix of open orders ───────────────────────────────
        $orderCurrencies = $openOrders->pluck('currency_code', 'id');
        $orderDates = $openOrders->mapWithKeys(fn ($o) => [$o->id => Carbon::parse($o->order_date)]);

        $productMix = SalesOrderLine::query()
            ->with('product:id,name,sku')
            ->whereIn('sales_order_id', $openOrders->pluck('id'))
            ->get()
            ->groupBy('product_id')
            ->map(function ($lines, $productId) use ($orderCurrencies, $orderDates, $rateService, $baseCurrency, &$unconverted) {
                $first = $lines->first();

                $value = round((float) $lines->sum(function ($line) use ($orderCurrencies, $orderDates, $rateService, $baseCurrency, &$unconverted) {
                    $currency = (string) ($orderCurrencies[$line->sales_order_id] ?? $baseCurrency);
                    if ($currency === '' || $currency === $baseCurrency) {
                        return (float) $line->line_total;
                    }

                    try {
                        $asOf = $orderDates[$line->sales_order_id] ?? now();

                        return (float) $rateService->convert((float) $line->line_total, $currency, $baseCurrency, $asOf->toDateString())['converted_amount'];
                    } catch (\Throwable) {
                        $unconverted[$currency] = round(($unconverted[$currency] ?? 0) + (float) $line->line_total, 2);

                        return 0.0;
                    }
                }), 2);

                return [
                    'product_id' => $productId !== '' ? (int) $productId : null,
                    'product_name' => $first?->product?->name,
                    'sku' => $first?->product?->sku,
                    'quantity' => round((float) $lines->sum('quantity'), 4),
                    'value' => $value,
                ];
            })
            ->sortByDesc('value')
            ->values()
            ->all();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'orders_by_status' => $byStatus,
            'base_currency' => $baseCurrency,
            'unconverted' => $unconverted,
            'pipeline' => [
                'open_orders' => $openOrders->count(),
                'open_value' => $sumBase($openOrders),
                'won_orders' => $wonOrders->count(),
                'won_value' => $sumBase($wonOrders),
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

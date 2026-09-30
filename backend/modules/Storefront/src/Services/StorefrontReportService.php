<?php

declare(strict_types=1);

namespace Modules\Storefront\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\PaymentGateway\Models\PaymentSettlement;
use Modules\PaymentGateway\Models\PaymentTransaction;
use Modules\Storefront\Models\StorefrontCart;
use Modules\Storefront\Models\StorefrontOrder;
use Modules\Storefront\Models\StorefrontPaymentProof;
use Modules\Storefront\Models\StorefrontSettings;

/**
 * FR-ADM-06 / P3 ops: read-only, tenant-scoped reporting (the tenant schema
 * IS the scope — every query runs inside it; no cross-tenant surface).
 * Aggregations are computed from the same rows the transactional flows
 * write (orders, transactions, settlements, carts) — no parallel counters.
 * PostgreSQL-safe date functions only.
 */
final class StorefrontReportService
{
    /**
     * @return array<string,mixed>
     */
    public function sales(Carbon $from, Carbon $to): array
    {
        $orders = StorefrontOrder::query()
            ->whereBetween('placed_at', [$from, $to]);

        $byStatus = (clone $orders)
            ->select('status', 'currency', DB::raw('COUNT(*) as orders'))
            ->groupBy('status', 'currency')
            ->get()
            ->map(fn ($r) => ['status' => $r->status, 'currency' => $r->currency, 'orders' => (int) $r->orders])
            ->all();

        $paidRows = StorefrontOrder::query()
            ->whereBetween('paid_at', [$from, $to])
            ->whereNotNull('paid_at')
            ->select('currency',
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(subtotal) as subtotal'),
                DB::raw('SUM(discount_amount) as discount'),
                DB::raw('SUM(tax_amount) as tax'),
                DB::raw('SUM(shipping_amount) as shipping'),
                DB::raw('SUM(total) as gross'))
            ->groupBy('currency')
            ->get()
            ->map(fn ($r) => [
                'currency' => $r->currency,
                'orders' => (int) $r->orders,
                'subtotal' => self::amt($r->subtotal),
                'discount' => self::amt($r->discount),
                'tax' => self::amt($r->tax),
                'shipping' => self::amt($r->shipping),
                'gross' => self::amt($r->gross),
            ])
            ->all();

        $daily = StorefrontOrder::query()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->select(DB::raw('date(paid_at) as day'), 'currency',
                DB::raw('COUNT(*) as orders'),
                DB::raw('SUM(total) as gross'))
            ->groupBy('day', 'currency')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => [
                'day' => (string) $r->day,
                'currency' => $r->currency,
                'orders' => (int) $r->orders,
                'gross' => self::amt($r->gross),
            ])
            ->all();

        $channel = PaymentTransaction::query()
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->select('payment_gateway_id', DB::raw('COUNT(*) as transactions'), DB::raw('SUM(amount) as captured'))
            ->with('gateway:id,gateway_name')
            ->groupBy('payment_gateway_id')
            ->get()
            ->map(fn ($r) => [
                'channel' => (string) $r->gateway?->gateway_name ?? 'unknown',
                'transactions' => (int) $r->transactions,
                'captured' => self::amt($r->captured),
            ])
            ->all();

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'paid_by_currency' => $paidRows,
            'orders_by_status' => $byStatus,
            'daily' => $daily,
            'channel_capture' => $channel,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function tax(Carbon $from, Carbon $to): array
    {
        $rows = DB::table('storefront_order_items as oi')
            ->join('storefront_orders as o', 'o.id', '=', 'oi.order_id')
            ->whereNotNull('o.paid_at')
            ->whereBetween('o.paid_at', [$from, $to])
            ->select('oi.tax_rate', 'o.currency',
                DB::raw('SUM(oi.line_total - oi.tax_amount) as base'),
                DB::raw('SUM(oi.tax_amount) as tax'))
            ->groupBy('oi.tax_rate', 'o.currency')
            ->orderBy('oi.tax_rate')
            ->get()
            ->map(fn ($r) => [
                'tax_rate' => (float) $r->tax_rate,
                'currency' => $r->currency,
                'base' => self::amt($r->base),
                'tax' => self::amt($r->tax),
            ])
            ->all();

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'basis' => 'orders with payment captured in range; tax from the discounted line amounts (matches the booked JE)',
            'by_rate' => $rows,
        ];
    }

    /**
     * A-19 payment reconciliation: captured vs refunded vs settled, plus an
     * explicit ledger tie (every settlement carries its clearing->cash JE).
     *
     * @return array<string,mixed>
     */
    public function reconciliation(Carbon $from, Carbon $to): array
    {
        $txns = PaymentTransaction::query()->whereBetween('created_at', [$from, $to]);

        $captured = (clone $txns)->where('status', 'completed');
        $capturedTotals = [
            'transactions' => $captured->count(),
            'amount' => self::amt($captured->sum('amount')),
            'refunded' => self::amt($captured->sum('refunded_amount')),
        ];

        $settled = PaymentSettlement::query()
            ->whereBetween('settled_at', [$from->toDateString(), $to->toDateString()]);

        $settledCount = (clone $settled)->count();
        $settlementTotals = [
            'settlements' => $settledCount,
            'gross' => self::amt((clone $settled)->sum('gross_amount')),
            'fees' => self::amt((clone $settled)->sum('fee_amount')),
            'net' => self::amt((clone $settled)->sum('net_amount')),
        ];

        $withoutEntry = PaymentSettlement::query()
            ->whereBetween('settled_at', [$from->toDateString(), $to->toDateString()])
            ->whereNull('journal_entry_id')
            ->count();

        $unsettled = $this->unsettledTransactions($from, $to);

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'captured' => $capturedTotals,
            'settlements' => $settlementTotals,
            'unsettled' => [
                'count' => $unsettled->count(),
                'amount' => self::amt($unsettled->sum(fn ($t) => (float) $t->amount - (float) ($t->refunded_amount ?? 0))),
            ],
            'ledger_tie' => [
                'settlements_without_gl' => $withoutEntry,
                'balanced' => $withoutEntry === 0,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function funnel(Carbon $from, Carbon $to): array
    {
        $carts = StorefrontCart::query()->whereBetween('created_at', [$from, $to]);

        $placed = StorefrontOrder::query()->whereBetween('placed_at', [$from, $to]);
        $placedCount = (clone $placed)->count();
        $paidCount = (clone $placed)->whereNotNull('paid_at')->count();

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'carts' => [
                'created' => (clone $carts)->count(),
                'converted_to_orders' => (clone $carts)->where('status', 'ordered')->count(),
                'abandoned' => (clone $carts)->whereIn('status', ['abandoned', 'expired'])->count(),
                'still_active' => (clone $carts)->where('status', 'active')->count(),
            ],
            'orders' => [
                'placed' => $placedCount,
                'paid' => $paidCount,
                'placement_to_payment_pct' => $placedCount > 0 ? round($paidCount / $placedCount * 100, 1) : null,
            ],
        ];
    }

    /**
     * Ops dashboard (Appendix-F: revenue / failed payments / low stock /
     * webhook & mail health / pending manual work). All today/7d/24h windows.
     *
     * @return array<string,mixed>
     */
    public function overview(): array
    {
        $today = Carbon::today();
        $settings = StorefrontSettings::current();

        $todayPaid = StorefrontOrder::query()
            ->whereBetween('paid_at', [$today, $today->copy()->endOfDay()]);

        $revenueToday = $todayPaid
            ->select('currency', DB::raw('COUNT(*) as orders'), DB::raw('SUM(total) as gross'))
            ->groupBy('currency')
            ->get()
            ->map(fn ($r) => ['currency' => $r->currency, 'orders' => (int) $r->orders, 'gross' => self::amt($r->gross)])
            ->all();

        $failedPayments7d = PaymentTransaction::query()
            ->where('status', 'failed')
            ->whereBetween('created_at', [$today->copy()->subDays(7), $today->endOfDay()])
            ->count();

        $threshold = (int) ($settings->low_stock_threshold ?? 5);
        $lowStock = DB::table('products')
            ->where('track_inventory', true)
            ->where('is_active', true)
            ->where('stock_quantity', '<=', $threshold)
            ->count();

        $mailFailed24h = DB::table('communication_messages')
            ->where('status', 'failed')
            ->whereBetween('created_at', [now()->subDay(), now()])
            ->count();

        return [
            'generated_at' => now()->toIso8601String(),
            'revenue_today' => $revenueToday,
            'awaiting_payment_orders' => StorefrontOrder::query()
                ->where('payment_status', 'pending_payment')
                ->where('status', '!=', 'cancelled')
                ->count(),
            'offline_proofs_pending' => StorefrontPaymentProof::query()->where('status', 'submitted')->count(),
            'unsettled_gateway_funds' => [
                'count' => $this->unsettledTransactions($today->copy()->subDays(30), $today->endOfDay())->count(),
                'amount' => self::amt($this->unsettledTransactions($today->copy()->subDays(30), $today->endOfDay())->sum(fn ($t) => (float) $t->amount - (float) ($t->refunded_amount ?? 0))),
            ],
            'failed_payments_7d' => $failedPayments7d,
            'low_stock_products' => $lowStock,
            'low_stock_threshold' => $threshold,
            'failed_messages_24h' => $mailFailed24h,
        ];
    }

    /**
     * Completed gateway money the provider has not paid out (or we have not
     * reconciled) — excludes offline anchors and fully refunded transactions.
     */
    private function unsettledTransactions(Carbon $from, Carbon $to): \Illuminate\Support\Collection
    {
        $settledIds = PaymentSettlement::query()->pluck('payment_transaction_id')->all();

        return PaymentTransaction::query()
            ->with('gateway:id,gateway_name')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->when($settledIds !== [], fn ($q) => $q->whereNotIn('id', $settledIds))
            ->get()
            ->filter(function (PaymentTransaction $t): bool {
                if ((string) ($t->gateway?->gateway_name ?? '') === 'offline') {
                    return false;
                }

                return (float) $t->amount - (float) ($t->refunded_amount ?? 0) > 0.004;
            });
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array{headers:list<string>,rows:list<array<int,string>>}
     */
    public function toCsv(array $report, string $kind): array
    {
        return match ($kind) {
            'sales' => ['headers' => ['day', 'currency', 'orders', 'gross'], 'rows' => array_map(
                fn (array $r) => [$r['day'], $r['currency'], (string) $r['orders'], $r['gross']],
                $report['daily']
            )],
            'tax' => ['headers' => ['tax_rate', 'currency', 'base', 'tax'], 'rows' => array_map(
                fn (array $r) => [(string) $r['tax_rate'], $r['currency'], $r['base'], $r['tax']],
                $report['by_rate']
            )],
            'reconciliation' => ['headers' => ['metric', 'currency', 'value'], 'rows' => [
                ['captured_transactions', '', (string) $report['captured']['transactions']],
                ['captured_amount', '', $report['captured']['amount']],
                ['refunded_amount', '', $report['captured']['refunded']],
                ['settlements', '', (string) $report['settlements']['settlements']],
                ['settlement_gross', '', $report['settlements']['gross']],
                ['settlement_fees', '', $report['settlements']['fees']],
                ['settlement_net', '', $report['settlements']['net']],
                ['unsettled_count', '', (string) $report['unsettled']['count']],
                ['unsettled_amount', '', $report['unsettled']['amount']],
                ['ledger_balanced', '', $report['ledger_tie']['balanced'] ? 'yes' : 'no'],
            ]],
            'funnel' => ['headers' => ['stage', 'value'], 'rows' => [
                ['carts_created', (string) $report['carts']['created']],
                ['carts_converted', (string) $report['carts']['converted_to_orders']],
                ['carts_abandoned', (string) $report['carts']['abandoned']],
                ['orders_placed', (string) $report['orders']['placed']],
                ['orders_paid', (string) $report['orders']['paid']],
            ]],
            default => ['headers' => [], 'rows' => []],
        };
    }

    private static function amt(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}

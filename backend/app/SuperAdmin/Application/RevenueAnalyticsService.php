<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use Illuminate\Support\Facades\DB;

class RevenueAnalyticsService
{
    /**
     * MRR (Monthly Recurring Revenue) — normalized, predictable recurring value.
     *
     * Calculation:
     *   For each ACTIVE subscription:
     *     - monthly plan  → add monthly_price as-is
     *     - yearly plan   → add (yearly_price / 12)
     *
     * This is the industry-standard SaaS MRR definition.
     * It does NOT fluctuate with payment timing.
     *
     * Returns amounts in cents.
     */
    public function getMrr(): array
    {
        // Join subscriptions → plan_versions → plan_prices
        // Normalise yearly prices to monthly equivalent
        $rows = DB::table('subscriptions as s')
            ->join('subscription_invoices as i', function ($join) {
                // Get the billing_interval from the most recent invoice
                $join->on('i.subscription_id', '=', 's.id')
                     ->where('i.id', '=', DB::raw(
                         "(SELECT id FROM subscription_invoices WHERE subscription_id = s.id ORDER BY created_at DESC LIMIT 1)"
                     ));
            })
            ->join('plan_versions as pv', 'pv.id', '=', 's.plan_version_id')
            ->join('plan_prices as pp', function ($join) {
                $join->on('pp.plan_version_id', '=', 'pv.id')
                     ->on('pp.billing_interval', '=', 'i.billing_interval');
            })
            ->whereIn('s.status', ['active', 'trialing'])
            ->select(
                DB::raw("SUM(CASE WHEN i.billing_interval = 'monthly' THEN pp.unit_amount ELSE pp.unit_amount / 12 END) AS mrr_cents"),
                DB::raw('COUNT(DISTINCT s.id) AS subscriber_count'),
            )
            ->first();

        $mrrCents = (int) ($rows->mrr_cents ?? 0);

        return [
            'mrr_cents'        => $mrrCents,
            'arr_cents'        => $mrrCents * 12,
            'subscriber_count' => (int) ($rows->subscriber_count ?? 0),
        ];
    }

    /**
     * Revenue (Cash) — sum of ALL paid invoices in the last 30 days.
     * This is cash flow, not MRR. Shown separately from MRR.
     */
    public function getRevenueLast30Days(): array
    {
        $row = DB::table('subscription_invoices')
            ->where('status', 'paid')
            ->where('paid_at', '>=', now()->subDays(30))
            ->selectRaw('COALESCE(SUM(total), 0) AS revenue_cents, COUNT(*) AS invoice_count')
            ->first();

        return [
            'revenue_cents'  => (int) ($row->revenue_cents ?? 0),
            'invoice_count'  => (int) ($row->invoice_count  ?? 0),
        ];
    }

    /**
     * ARPU — Average Revenue Per (paying) User.
     * Based on MRR divided by active subscriber count.
     */
    public function getArpu(): array
    {
        $mrr = $this->getMrr();
        $subscriberCount = $mrr['subscriber_count'];

        return [
            'arpu_cents'       => $subscriberCount > 0 ? (int) round($mrr['mrr_cents'] / $subscriberCount) : 0,
            'subscriber_count' => $subscriberCount,
        ];
    }

    /**
     * MRR broken down by plan family name (normalized, from active subscriptions).
     */
    public function getMrrByPlan(): array
    {
        return DB::table('subscriptions as s')
            ->join('subscription_invoices as i', function ($join) {
                $join->on('i.subscription_id', '=', 's.id')
                     ->where('i.id', '=', DB::raw(
                         "(SELECT id FROM subscription_invoices WHERE subscription_id = s.id ORDER BY created_at DESC LIMIT 1)"
                     ));
            })
            ->join('plan_versions as pv', 'pv.id', '=', 's.plan_version_id')
            ->join('plan_families as pf', 'pf.id', '=', 'pv.plan_family_id')
            ->join('plan_prices as pp', function ($join) {
                $join->on('pp.plan_version_id', '=', 'pv.id')
                     ->on('pp.billing_interval', '=', 'i.billing_interval');
            })
            ->whereIn('s.status', ['active', 'trialing'])
            ->select(
                'pf.name as plan_name',
                DB::raw("SUM(CASE WHEN i.billing_interval = 'monthly' THEN pp.unit_amount ELSE pp.unit_amount / 12 END) AS mrr_cents"),
                DB::raw('COUNT(DISTINCT s.id) AS subscriber_count'),
            )
            ->groupBy('pf.id', 'pf.name')
            ->orderByDesc('mrr_cents')
            ->get()
            ->toArray();
    }

    /**
     * Churn: subscriptions canceled this calendar month.
     */
    public function getChurnThisMonth(): array
    {
        $canceled = DB::table('subscriptions')
            ->where('status', 'canceled')
            ->whereMonth('canceled_at', now()->month)
            ->whereYear('canceled_at', now()->year)
            ->count();

        $activeLastMonth = DB::table('subscriptions')
            ->where('status', '!=', 'canceled')
            ->where('created_at', '<', now()->startOfMonth())
            ->count();

        $rate = $activeLastMonth > 0
            ? round(($canceled / $activeLastMonth) * 100, 2)
            : 0.0;

        return [
            'canceled_count'     => $canceled,
            'active_base'        => $activeLastMonth,
            'churn_rate_percent' => $rate,
        ];
    }

    /**
     * Net new MRR this month vs last month (cash-based for trend).
     */
    public function getGrowth(): array
    {
        $thisMonth = (int) DB::table('subscription_invoices')
            ->where('status', 'paid')
            ->whereMonth('paid_at', now()->month)
            ->whereYear('paid_at', now()->year)
            ->sum('total');

        $lastMonth = (int) DB::table('subscription_invoices')
            ->where('status', 'paid')
            ->whereMonth('paid_at', now()->subMonth()->month)
            ->whereYear('paid_at', now()->subMonth()->year)
            ->sum('total');

        $delta = $thisMonth - $lastMonth;
        $pct   = $lastMonth > 0 ? round(($delta / $lastMonth) * 100, 2) : null;

        return [
            'this_month_cents' => $thisMonth,
            'last_month_cents' => $lastMonth,
            'delta_cents'      => $delta,
            'growth_percent'   => $pct,
        ];
    }

    /**
     * Subscription counts by status.
     */
    public function getSubscriptionStats(): array
    {
        $rows = DB::table('subscriptions')
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $active   = (int) ($rows['active']   ?? 0);
        $trialing = (int) ($rows['trialing'] ?? 0);
        $total    = array_sum($rows);

        return [
            'active'          => $active,
            'trialing'        => $trialing,
            'past_due'        => (int) ($rows['past_due'] ?? 0),
            'canceled'        => (int) ($rows['canceled'] ?? 0),
            'paying_ratio'    => $total > 0 ? round(($active / $total) * 100, 1) : 0.0,
        ];
    }
}

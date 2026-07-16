<?php

declare(strict_types=1);

namespace App\SuperAdmin\Application;

use Illuminate\Support\Facades\DB;

class CatalogAnalyticsService
{
    // ── Overview ─────────────────────────────────────────────────────────────

    /**
     * Top-level numbers for the summary cards.
     */
    public function overview(): array
    {
        $totalItems = DB::table('catalog_items')->where('is_active', true)->count();

        $totalPrices = DB::table('catalog_prices')->count();

        $totalRules = DB::table('catalog_price_rules')
            ->where('is_active', true)->count();

        $tenantsWithEntitlements = DB::table('tenant_entitlements')
            ->where('is_entitled', true)
            ->distinct('tenant_id')
            ->count('tenant_id');

        $totalQuotes = DB::table('tenant_quote_snapshots')->count();

        $totalDiscountCents = (int) DB::table('tenant_quote_snapshots')
            ->sum('discount_cents');

        $totalRevenueCents = (int) DB::table('tenant_quote_snapshots')
            ->sum('total_cents');

        $pendingPlanChanges = DB::table('tenant_plan_changes')
            ->where('status', 'pending')->count();

        $planChangesThisMonth = DB::table('tenant_plan_changes')
            ->where('created_at', '>=', now()->startOfMonth())->count();

        $totalSelections = DB::table('tenant_selected_items')->count();

        $addOnSelections = DB::table('tenant_selected_items')
            ->where('selection_type', 'add_on_purchased')->count();

        return [
            'total_active_items' => $totalItems,
            'total_prices' => $totalPrices,
            'total_active_rules' => $totalRules,
            'tenants_with_entitlements' => $tenantsWithEntitlements,
            'total_quote_snapshots' => $totalQuotes,
            'total_discount_cents' => $totalDiscountCents,
            'total_revenue_cents' => $totalRevenueCents,
            'pending_plan_changes' => $pendingPlanChanges,
            'plan_changes_this_month' => $planChangesThisMonth,
            'total_selections' => $totalSelections,
            'add_on_selections' => $addOnSelections,
        ];
    }

    // ── Entitlement heatmap ───────────────────────────────────────────────────

    /**
     * Per-item entitlement stats — how many tenants have each item entitled.
     * Returns items ranked by entitled count descending.
     */
    public function entitlementHeatmap(): array
    {
        $totalTenants = max(1, DB::table('tenant_entitlements')
            ->distinct('tenant_id')->count('tenant_id'));

        $rows = DB::table('tenant_entitlements as te')
            ->join('catalog_items as ci', 'ci.id', '=', 'te.catalog_item_id')
            ->select([
                'ci.id',
                'ci.key as item_key',
                'ci.name as item_name',
                DB::raw('COUNT(CASE WHEN te.is_entitled = true THEN 1 END) as entitled_count'),
                DB::raw('COUNT(CASE WHEN te.is_entitled = false THEN 1 END) as blocked_count'),
                DB::raw("COUNT(CASE WHEN te.source = 'tier_included' THEN 1 END) as tier_included_count"),
                DB::raw("COUNT(CASE WHEN te.source = 'add_on_purchased' THEN 1 END) as add_on_count"),
                DB::raw("COUNT(CASE WHEN te.source = 'override_include' THEN 1 END) as override_include_count"),
                DB::raw("COUNT(CASE WHEN te.source = 'override_exclude' THEN 1 END) as override_exclude_count"),
            ])
            ->groupBy('ci.id', 'ci.key', 'ci.name')
            ->orderByDesc('entitled_count')
            ->get();

        return $rows->map(function ($row) use ($totalTenants) {
            return [
                'item_id' => $row->id,
                'item_key' => $row->item_key,
                'item_name' => $row->item_name,
                'entitled_count' => (int) $row->entitled_count,
                'blocked_count' => (int) $row->blocked_count,
                'entitlement_pct' => round(($row->entitled_count / $totalTenants) * 100, 1),
                'tier_included_count' => (int) $row->tier_included_count,
                'add_on_count' => (int) $row->add_on_count,
                'override_include_count' => (int) $row->override_include_count,
                'override_exclude_count' => (int) $row->override_exclude_count,
            ];
        })->values()->all();
    }

    // ── Add-on adoption ───────────────────────────────────────────────────────

    /**
     * Which catalog items are most frequently purchased as add-ons.
     */
    public function addonAdoption(): array
    {
        $rows = DB::table('tenant_selected_items as ts')
            ->join('catalog_items as ci', 'ci.id', '=', 'ts.catalog_item_id')
            ->where('ts.selection_type', 'add_on_purchased')
            ->select([
                'ci.id',
                'ci.key as item_key',
                'ci.name as item_name',
                DB::raw('COUNT(*) as adoption_count'),
            ])
            ->groupBy('ci.id', 'ci.key', 'ci.name')
            ->orderByDesc('adoption_count')
            ->limit(20)
            ->get();

        $max = $rows->max('adoption_count') ?: 1;

        return $rows->map(fn ($r) => [
            'item_id' => $r->id,
            'item_key' => $r->item_key,
            'item_name' => $r->item_name,
            'adoption_count' => (int) $r->adoption_count,
            'pct_of_max' => round(($r->adoption_count / $max) * 100, 1),
        ])->values()->all();
    }

    // ── Revenue by item ───────────────────────────────────────────────────────

    /**
     * Aggregate revenue per catalog item from quote snapshot line items.
     * Reads the JSONB line_items column — each element has catalog_item_id and line_total_cents.
     *
     * Uses PostgreSQL json_array_elements to expand line items.
     */
    public function revenueByItem(): array
    {
        // PostgreSQL: expand line_items JSON array and aggregate
        $rows = DB::select("
            SELECT
                ci.id                                              AS item_id,
                ci.key                                             AS item_key,
                ci.name                                            AS item_name,
                SUM((li->>'line_total_cents')::int)                AS total_revenue_cents,
                SUM((li->>'discount_cents')::int)                  AS total_discount_cents,
                COUNT(*)                                           AS appearance_count
            FROM tenant_quote_snapshots qs
            CROSS JOIN json_array_elements(qs.line_items) AS li
            JOIN catalog_items ci ON ci.id = (li->>'catalog_item_id')::int
            GROUP BY ci.id, ci.key, ci.name
            ORDER BY total_revenue_cents DESC
            LIMIT 20
        ");

        $max = collect($rows)->max('total_revenue_cents') ?: 1;

        return collect($rows)->map(fn ($r) => [
            'item_id' => (int) $r->item_id,
            'item_key' => $r->item_key,
            'item_name' => $r->item_name,
            'total_revenue_cents' => (int) $r->total_revenue_cents,
            'total_discount_cents' => (int) $r->total_discount_cents,
            'appearance_count' => (int) $r->appearance_count,
            'pct_of_max' => round(($r->total_revenue_cents / $max) * 100, 1),
        ])->values()->all();
    }

    // ── Plan distribution ─────────────────────────────────────────────────────

    /**
     * How many tenants are on each plan family, based on active subscriptions.
     */
    public function planDistribution(): array
    {
        $rows = DB::table('subscriptions as s')
            ->join('plan_versions as pv', 'pv.id', '=', 's.plan_version_id')
            ->join('plan_families as pf', 'pf.id', '=', 'pv.plan_family_id')
            ->whereIn('s.status', ['active', 'trialing'])
            ->select([
                'pf.id',
                'pf.key as family_key',
                'pf.name as family_name',
                DB::raw('COUNT(DISTINCT s.tenant_id) as tenant_count'),
                DB::raw("COUNT(CASE WHEN s.status = 'trialing' THEN 1 END) as trialing_count"),
                DB::raw("COUNT(CASE WHEN s.status = 'active' THEN 1 END) as active_count"),
            ])
            ->groupBy('pf.id', 'pf.key', 'pf.name')
            ->orderByDesc('tenant_count')
            ->get();

        $total = max(1, $rows->sum('tenant_count'));

        return $rows->map(fn ($r) => [
            'family_id' => $r->id,
            'family_key' => $r->family_key,
            'family_name' => $r->family_name,
            'tenant_count' => (int) $r->tenant_count,
            'trialing_count' => (int) $r->trialing_count,
            'active_count' => (int) $r->active_count,
            'pct' => round(($r->tenant_count / $total) * 100, 1),
        ])->values()->all();
    }

    // ── Plan change activity ──────────────────────────────────────────────────

    /**
     * Plan change activity summary — counts by status + recent changes.
     */
    public function planChangeActivity(): array
    {
        $byStatus = DB::table('tenant_plan_changes')
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $thisMonth = DB::table('tenant_plan_changes')
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        $lastMonth = DB::table('tenant_plan_changes')
            ->whereBetween('created_at', [now()->subMonth()->startOfMonth(), now()->startOfMonth()])
            ->count();

        $recentChanges = DB::table('tenant_plan_changes as tc')
            ->leftJoin('plan_families as pf_from', 'pf_from.id', '=', 'tc.from_family_id')
            ->leftJoin('plan_families as pf_to', 'pf_to.id', '=', 'tc.to_family_id')
            ->select([
                'tc.id',
                'tc.tenant_id',
                'tc.status',
                'tc.effective_date',
                'tc.proration_credit_cents',
                'tc.proration_charge_cents',
                'tc.created_at',
                'pf_from.name as from_family_name',
                'pf_to.name as to_family_name',
            ])
            ->orderByDesc('tc.created_at')
            ->limit(10)
            ->get();

        return [
            'by_status' => [
                'pending' => (int) ($byStatus['pending'] ?? 0),
                'applied' => (int) ($byStatus['applied'] ?? 0),
                'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
            ],
            'this_month' => $thisMonth,
            'last_month' => $lastMonth,
            'recent_changes' => $recentChanges,
        ];
    }

    // ── Pricing rule impact ───────────────────────────────────────────────────

    /**
     * How much discount each rule has generated across all quote snapshots.
     * Reads rules_applied[] from line_items JSON.
     */
    public function pricingRuleImpact(): array
    {
        // Expand line_items and unnest the rules_applied array
        $rows = DB::select("
            SELECT
                rule_name,
                COUNT(*)                                           AS application_count,
                SUM((li->>'discount_cents')::int)                  AS total_discount_cents
            FROM tenant_quote_snapshots qs
            CROSS JOIN json_array_elements(qs.line_items) AS li
            CROSS JOIN json_array_elements_text(li->'rules_applied') AS rule_name
            WHERE json_array_length(li->'rules_applied') > 0
            GROUP BY rule_name
            ORDER BY total_discount_cents DESC
            LIMIT 15
        ");

        $max = collect($rows)->max('total_discount_cents') ?: 1;

        return collect($rows)->map(fn ($r) => [
            'rule_name' => $r->rule_name,
            'application_count' => (int) $r->application_count,
            'total_discount_cents' => (int) $r->total_discount_cents,
            'pct_of_max' => round(($r->total_discount_cents / $max) * 100, 1),
        ])->values()->all();
    }
}

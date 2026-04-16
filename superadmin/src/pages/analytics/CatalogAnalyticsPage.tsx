import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router-dom'
import { Icon } from '@iconify/react'
import {
  getCatalogOverview,
  getEntitlementHeatmap,
  getAddonAdoption,
  getRevenueByItem,
  getPlanDistribution,
  getPlanChangeActivity,
  getPricingRuleImpact,
} from '@/lib/admin-api/catalog-analytics'
import type {
  EntitlementHeatmapRow,
  AddonAdoptionRow,
  RevenueByItemRow,
  PlanDistributionRow,
  PlanChangeRecentRow,
  PricingRuleImpactRow,
} from '@/types/admin'

// ── Helpers ───────────────────────────────────────────────────────────────────

function cents(n: number): string {
  if (n >= 100_000_00) return '$' + (n / 100_000_00).toFixed(1) + 'M'
  if (n >= 100_000)   return '$' + (n / 100_000).toFixed(1) + 'k'
  return '$' + (n / 100).toFixed(2)
}

function Bar({ pct, color = 'bg-brand-500' }: { pct: number; color?: string }) {
  return (
    <div className="h-2 w-full rounded-full bg-gray-100 overflow-hidden">
      <div
        className={`h-full rounded-full transition-all ${color}`}
        style={{ width: `${Math.min(100, pct)}%` }}
      />
    </div>
  )
}

function StatCard({
  label,
  value,
  sub,
  icon,
  color = 'text-brand-600',
}: {
  label: string
  value: string | number
  sub?: string
  icon: string
  color?: string
}) {
  return (
    <div className="rounded-xl border border-gray-200 bg-white p-4 flex items-start gap-3">
      <div className={`mt-0.5 shrink-0 rounded-lg p-2 bg-gray-50 ${color}`}>
        <Icon icon={icon} width={20} />
      </div>
      <div className="min-w-0">
        <p className="text-xs text-gray-500 truncate">{label}</p>
        <p className="text-xl font-bold text-gray-900 mt-0.5">{value}</p>
        {sub && <p className="text-xs text-gray-400 mt-0.5">{sub}</p>}
      </div>
    </div>
  )
}

function Spinner() {
  return (
    <div className="flex h-40 items-center justify-center">
      <div className="h-6 w-6 animate-spin rounded-full border-2 border-brand-600 border-t-transparent" />
    </div>
  )
}

function Empty({ label }: { label: string }) {
  return (
    <div className="rounded-xl border border-dashed border-gray-200 py-14 text-center">
      <p className="text-sm text-gray-400">{label}</p>
    </div>
  )
}

// ── Tab: Overview ─────────────────────────────────────────────────────────────

function OverviewTab() {
  const { data, isLoading } = useQuery({
    queryKey: ['analytics', 'catalog', 'overview'],
    queryFn: getCatalogOverview,
  })

  const { data: planDist = [], isLoading: planLoading } = useQuery({
    queryKey: ['analytics', 'catalog', 'plan-distribution'],
    queryFn: getPlanDistribution,
  })

  const { data: planActivity, isLoading: activityLoading } = useQuery({
    queryKey: ['analytics', 'catalog', 'plan-changes'],
    queryFn: getPlanChangeActivity,
  })

  if (isLoading) return <Spinner />

  const d = data!

  const PLAN_COLORS = [
    'bg-brand-500', 'bg-blue-400', 'bg-purple-400',
    'bg-green-400', 'bg-orange-400', 'bg-pink-400',
  ]

  return (
    <div className="space-y-6">
      {/* Stat cards — row 1 */}
      <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
        <StatCard
          label="Active Catalog Items"
          value={d.total_active_items}
          icon="solar:box-bold-duotone"
          color="text-brand-600"
        />
        <StatCard
          label="Tenants with Entitlements"
          value={d.tenants_with_entitlements}
          icon="solar:buildings-bold-duotone"
          color="text-blue-600"
        />
        <StatCard
          label="Total Revenue (Quoted)"
          value={cents(d.total_revenue_cents)}
          sub="from quote snapshots"
          icon="solar:dollar-minimalistic-bold-duotone"
          color="text-green-600"
        />
        <StatCard
          label="Total Discounts Applied"
          value={cents(d.total_discount_cents)}
          sub="across all quotes"
          icon="solar:tag-price-bold-duotone"
          color="text-orange-500"
        />
        <StatCard
          label="Active Price Rules"
          value={d.total_active_rules}
          icon="solar:filter-bold-duotone"
          color="text-purple-600"
        />
        <StatCard
          label="Quote Snapshots"
          value={d.total_quote_snapshots}
          icon="solar:document-text-bold-duotone"
          color="text-gray-600"
        />
        <StatCard
          label="Add-on Purchases"
          value={d.add_on_selections}
          sub={`of ${d.total_selections} total selections`}
          icon="solar:cart-plus-bold-duotone"
          color="text-blue-500"
        />
        <StatCard
          label="Pending Plan Changes"
          value={d.pending_plan_changes}
          sub={`${d.plan_changes_this_month} this month`}
          icon="solar:arrow-right-up-bold-duotone"
          color={d.pending_plan_changes > 0 ? 'text-yellow-500' : 'text-gray-400'}
        />
      </div>

      {/* Plan distribution */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div className="rounded-xl border border-gray-200 bg-white p-5">
          <h3 className="text-sm font-semibold text-gray-800 mb-4">Plan Distribution</h3>
          {planLoading ? <Spinner /> : planDist.length === 0 ? (
            <Empty label="No active subscriptions yet." />
          ) : (
            <div className="space-y-3">
              {(planDist as PlanDistributionRow[]).map((row, i) => (
                <div key={row.family_id}>
                  <div className="flex items-center justify-between mb-1">
                    <div className="flex items-center gap-2">
                      <span className={`inline-block h-2.5 w-2.5 rounded-full ${PLAN_COLORS[i % PLAN_COLORS.length]}`} />
                      <span className="text-sm font-medium text-gray-800">{row.family_name}</span>
                      <span className="text-xs text-gray-400 font-mono">{row.family_key}</span>
                    </div>
                    <div className="text-right">
                      <span className="text-sm font-bold text-gray-900">{row.tenant_count}</span>
                      <span className="text-xs text-gray-400 ml-1">({row.pct}%)</span>
                    </div>
                  </div>
                  <Bar pct={row.pct} color={PLAN_COLORS[i % PLAN_COLORS.length]} />
                  <div className="flex gap-3 mt-1 text-xs text-gray-400">
                    <span>{row.active_count} active</span>
                    {row.trialing_count > 0 && <span>{row.trialing_count} trialing</span>}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Plan change activity */}
        <div className="rounded-xl border border-gray-200 bg-white p-5">
          <h3 className="text-sm font-semibold text-gray-800 mb-4">Plan Change Activity</h3>
          {activityLoading ? <Spinner /> : !planActivity ? (
            <Empty label="No plan change data." />
          ) : (
            <div className="space-y-4">
              {/* Status pills */}
              <div className="grid grid-cols-3 gap-2">
                {[
                  { label: 'Pending',   val: planActivity.by_status.pending,   color: 'bg-yellow-50 text-yellow-800 border-yellow-200' },
                  { label: 'Applied',   val: planActivity.by_status.applied,   color: 'bg-green-50 text-green-800 border-green-200' },
                  { label: 'Cancelled', val: planActivity.by_status.cancelled, color: 'bg-gray-50 text-gray-600 border-gray-200' },
                ].map(s => (
                  <div key={s.label} className={`rounded-lg border p-3 text-center ${s.color}`}>
                    <p className="text-2xl font-bold">{s.val}</p>
                    <p className="text-xs mt-0.5">{s.label}</p>
                  </div>
                ))}
              </div>

              <div className="flex items-center gap-4 text-sm">
                <div>
                  <span className="text-gray-500">This month: </span>
                  <span className="font-semibold text-gray-900">{planActivity.this_month}</span>
                </div>
                <div>
                  <span className="text-gray-500">Last month: </span>
                  <span className="font-semibold text-gray-900">{planActivity.last_month}</span>
                </div>
              </div>

              {/* Recent changes */}
              <div className="space-y-1.5 mt-2">
                <p className="text-xs font-medium text-gray-500 uppercase">Recent</p>
                {planActivity.recent_changes.slice(0, 5).map((c: PlanChangeRecentRow) => (
                  <div key={c.id} className="flex items-center justify-between text-xs">
                    <Link
                      to={`/tenants/${c.tenant_id}/configuration`}
                      className="font-mono text-brand-600 hover:underline"
                    >
                      {c.tenant_id.slice(0, 8)}…
                    </Link>
                    <span className="text-gray-400">
                      {c.from_family_name ?? '—'} → <strong>{c.to_family_name}</strong>
                    </span>
                    <span className={`rounded-full px-2 py-0.5 font-medium ${
                      c.status === 'pending' ? 'bg-yellow-100 text-yellow-700' :
                      c.status === 'applied' ? 'bg-green-100 text-green-700' :
                      'bg-gray-100 text-gray-500'
                    }`}>
                      {c.status}
                    </span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

// ── Tab: Entitlements ─────────────────────────────────────────────────────────

function EntitlementsTab() {
  const [search, setSearch] = useState('')

  const { data: rows = [], isLoading } = useQuery({
    queryKey: ['analytics', 'catalog', 'entitlement-heatmap'],
    queryFn: getEntitlementHeatmap,
  })

  const { data: addons = [], isLoading: addonsLoading } = useQuery({
    queryKey: ['analytics', 'catalog', 'addon-adoption'],
    queryFn: getAddonAdoption,
  })

  const filtered = (rows as EntitlementHeatmapRow[]).filter(r =>
    r.item_key.includes(search) || r.item_name.toLowerCase().includes(search.toLowerCase())
  )

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">

        {/* Heatmap table */}
        <div className="lg:col-span-2 rounded-xl border border-gray-200 bg-white overflow-hidden">
          <div className="flex items-center justify-between px-4 py-3 border-b border-gray-100">
            <h3 className="text-sm font-semibold text-gray-800">Entitlement Heatmap</h3>
            <input
              className="rounded-lg border border-gray-200 px-3 py-1.5 text-xs w-48 outline-none focus:border-brand-400"
              placeholder="Search items…"
              value={search}
              onChange={e => setSearch(e.target.value)}
            />
          </div>
          {isLoading ? <Spinner /> : filtered.length === 0 ? (
            <div className="py-12 text-center text-sm text-gray-400">No entitlement data yet.</div>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead className="bg-gray-50 border-b border-gray-100">
                  <tr>
                    <th className="px-4 py-2.5 text-left font-medium text-gray-500">Item</th>
                    <th className="px-4 py-2.5 text-left font-medium text-gray-500">Coverage</th>
                    <th className="px-3 py-2.5 text-right font-medium text-gray-500">Entitled</th>
                    <th className="px-3 py-2.5 text-right font-medium text-gray-500">Tier</th>
                    <th className="px-3 py-2.5 text-right font-medium text-gray-500">Add-on</th>
                    <th className="px-3 py-2.5 text-right font-medium text-gray-500">Override</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-50">
                  {filtered.map((row: EntitlementHeatmapRow) => (
                    <tr key={row.item_id} className="hover:bg-gray-50/60">
                      <td className="px-4 py-2.5">
                        <p className="font-mono text-gray-700">{row.item_key}</p>
                        <p className="text-gray-400">{row.item_name}</p>
                      </td>
                      <td className="px-4 py-2.5 min-w-[120px]">
                        <div className="flex items-center gap-2">
                          <div className="flex-1">
                            <Bar
                              pct={row.entitlement_pct}
                              color={
                                row.entitlement_pct > 75 ? 'bg-green-400' :
                                row.entitlement_pct > 40 ? 'bg-blue-400' :
                                'bg-gray-300'
                              }
                            />
                          </div>
                          <span className="text-gray-600 font-medium w-10 text-right">
                            {row.entitlement_pct}%
                          </span>
                        </div>
                      </td>
                      <td className="px-3 py-2.5 text-right font-semibold text-green-700">{row.entitled_count}</td>
                      <td className="px-3 py-2.5 text-right text-gray-500">{row.tier_included_count}</td>
                      <td className="px-3 py-2.5 text-right text-blue-600">{row.add_on_count}</td>
                      <td className="px-3 py-2.5 text-right text-amber-600">
                        {row.override_include_count + row.override_exclude_count}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>

        {/* Add-on adoption */}
        <div className="rounded-xl border border-gray-200 bg-white p-5">
          <h3 className="text-sm font-semibold text-gray-800 mb-4">Add-on Adoption</h3>
          {addonsLoading ? <Spinner /> : (addons as AddonAdoptionRow[]).length === 0 ? (
            <Empty label="No add-on purchases yet." />
          ) : (
            <div className="space-y-3">
              {(addons as AddonAdoptionRow[]).slice(0, 10).map((row: AddonAdoptionRow, i) => (
                <div key={row.item_id}>
                  <div className="flex items-center justify-between mb-1">
                    <div className="flex items-center gap-1.5 min-w-0">
                      <span className="text-xs font-bold text-gray-300 w-4 shrink-0">#{i + 1}</span>
                      <span className="text-xs font-medium text-gray-700 truncate">{row.item_name}</span>
                    </div>
                    <span className="text-xs font-bold text-blue-700 ml-2 shrink-0">{row.adoption_count}</span>
                  </div>
                  <Bar pct={row.pct_of_max} color="bg-blue-400" />
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

// ── Tab: Revenue ──────────────────────────────────────────────────────────────

function RevenueTab() {
  const { data: revenueRows = [], isLoading: revLoading } = useQuery({
    queryKey: ['analytics', 'catalog', 'revenue'],
    queryFn: getRevenueByItem,
  })

  const { data: ruleRows = [], isLoading: rulesLoading } = useQuery({
    queryKey: ['analytics', 'catalog', 'pricing-rules'],
    queryFn: getPricingRuleImpact,
  })

  return (
    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
      {/* Revenue by item */}
      <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
        <div className="px-4 py-3 border-b border-gray-100">
          <h3 className="text-sm font-semibold text-gray-800">Revenue by Catalog Item</h3>
          <p className="text-xs text-gray-400 mt-0.5">Aggregated from quote snapshot line items</p>
        </div>
        {revLoading ? <Spinner /> : (revenueRows as RevenueByItemRow[]).length === 0 ? (
          <div className="py-12 text-center text-sm text-gray-400">No quote data yet.</div>
        ) : (
          <div className="p-4 space-y-3">
            {(revenueRows as RevenueByItemRow[]).map((row: RevenueByItemRow, i) => (
              <div key={row.item_id}>
                <div className="flex items-center justify-between mb-1">
                  <div className="flex items-center gap-2 min-w-0">
                    <span className="text-xs font-bold text-gray-300 w-5 shrink-0">#{i + 1}</span>
                    <div className="min-w-0">
                      <p className="text-xs font-medium text-gray-800 truncate">{row.item_name}</p>
                      <p className="text-xs text-gray-400 font-mono">{row.item_key}</p>
                    </div>
                  </div>
                  <div className="text-right ml-2 shrink-0">
                    <p className="text-sm font-bold text-gray-900">{cents(row.total_revenue_cents)}</p>
                    {row.total_discount_cents > 0 && (
                      <p className="text-xs text-green-600">−{cents(row.total_discount_cents)}</p>
                    )}
                  </div>
                </div>
                <Bar pct={row.pct_of_max} color="bg-green-400" />
                <p className="text-xs text-gray-400 mt-0.5">Appears in {row.appearance_count} quotes</p>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Pricing rule impact */}
      <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
        <div className="px-4 py-3 border-b border-gray-100">
          <h3 className="text-sm font-semibold text-gray-800">Pricing Rule Impact</h3>
          <p className="text-xs text-gray-400 mt-0.5">Total discount generated per rule</p>
        </div>
        {rulesLoading ? <Spinner /> : (ruleRows as PricingRuleImpactRow[]).length === 0 ? (
          <div className="py-12 text-center text-sm text-gray-400">No rule applications recorded yet.</div>
        ) : (
          <div className="p-4 space-y-3">
            {(ruleRows as PricingRuleImpactRow[]).map((row: PricingRuleImpactRow, i) => (
              <div key={row.rule_name}>
                <div className="flex items-center justify-between mb-1">
                  <div className="flex items-center gap-2 min-w-0">
                    <span className="text-xs font-bold text-gray-300 w-5 shrink-0">#{i + 1}</span>
                    <p className="text-xs font-medium text-gray-800 truncate">{row.rule_name}</p>
                  </div>
                  <div className="text-right ml-2 shrink-0">
                    <p className="text-sm font-bold text-orange-600">{cents(row.total_discount_cents)}</p>
                    <p className="text-xs text-gray-400">{row.application_count}× applied</p>
                  </div>
                </div>
                <Bar pct={row.pct_of_max} color="bg-orange-400" />
              </div>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

// ── Tab: Plan Activity (full table) ───────────────────────────────────────────

function PlanActivityTab() {
  const { data, isLoading } = useQuery({
    queryKey: ['analytics', 'catalog', 'plan-changes'],
    queryFn: getPlanChangeActivity,
  })

  if (isLoading) return <Spinner />
  if (!data) return <Empty label="No plan change activity." />

  const statusColor: Record<string, string> = {
    pending:   'bg-yellow-100 text-yellow-800',
    applied:   'bg-green-100 text-green-800',
    cancelled: 'bg-gray-100 text-gray-500',
  }

  return (
    <div className="space-y-4">
      {/* Summary strip */}
      <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <StatCard label="Pending"       value={data.by_status.pending}   icon="solar:clock-circle-bold-duotone" color="text-yellow-500" />
        <StatCard label="Applied"       value={data.by_status.applied}   icon="solar:check-circle-bold-duotone" color="text-green-600" />
        <StatCard label="Cancelled"     value={data.by_status.cancelled} icon="solar:close-circle-bold-duotone" color="text-gray-400" />
        <StatCard label="This Month"    value={data.this_month}          icon="solar:calendar-bold-duotone"     color="text-brand-600"
          sub={`${data.last_month} last month`}
        />
      </div>

      {/* Full recent table */}
      {data.recent_changes.length === 0 ? (
        <Empty label="No plan changes recorded yet." />
      ) : (
        <div className="rounded-xl border border-gray-200 bg-white overflow-hidden">
          <div className="px-4 py-3 border-b border-gray-100">
            <h3 className="text-sm font-semibold text-gray-800">Recent Plan Changes</h3>
          </div>
          <table className="min-w-full divide-y divide-gray-100 text-sm">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-4 py-2.5 text-left text-xs font-medium text-gray-500">Tenant</th>
                <th className="px-4 py-2.5 text-left text-xs font-medium text-gray-500">Change</th>
                <th className="px-4 py-2.5 text-left text-xs font-medium text-gray-500">Effective</th>
                <th className="px-4 py-2.5 text-left text-xs font-medium text-gray-500">Status</th>
                <th className="px-4 py-2.5 text-right text-xs font-medium text-gray-500">Credit</th>
                <th className="px-4 py-2.5 text-right text-xs font-medium text-gray-500">Charge</th>
                <th className="px-4 py-2.5 text-left text-xs font-medium text-gray-500">Created</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {data.recent_changes.map((c: PlanChangeRecentRow) => (
                <tr key={c.id} className="hover:bg-gray-50/60">
                  <td className="px-4 py-2.5">
                    <Link
                      to={`/tenants/${c.tenant_id}/configuration`}
                      className="font-mono text-xs text-brand-600 hover:underline"
                    >
                      {c.tenant_id.slice(0, 8)}…
                    </Link>
                  </td>
                  <td className="px-4 py-2.5 text-xs">
                    <span className="text-gray-400">{c.from_family_name ?? '—'}</span>
                    <span className="mx-1 text-gray-300">→</span>
                    <span className="font-medium text-gray-900">{c.to_family_name}</span>
                  </td>
                  <td className="px-4 py-2.5 text-xs text-gray-600">{c.effective_date}</td>
                  <td className="px-4 py-2.5">
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-medium ${statusColor[c.status]}`}>
                      {c.status}
                    </span>
                  </td>
                  <td className="px-4 py-2.5 text-right text-xs text-green-600">
                    {c.proration_credit_cents > 0 ? cents(c.proration_credit_cents) : '—'}
                  </td>
                  <td className="px-4 py-2.5 text-right text-xs text-gray-700">
                    {c.proration_charge_cents > 0 ? cents(c.proration_charge_cents) : '—'}
                  </td>
                  <td className="px-4 py-2.5 text-xs text-gray-400">
                    {new Date(c.created_at).toLocaleDateString()}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

// ── Main Page ─────────────────────────────────────────────────────────────────

const TABS = [
  { id: 'overview',     label: 'Overview',     icon: 'solar:chart-square-bold-duotone' },
  { id: 'entitlements', label: 'Entitlements', icon: 'solar:shield-check-bold-duotone' },
  { id: 'revenue',      label: 'Revenue',      icon: 'solar:dollar-minimalistic-bold-duotone' },
  { id: 'plan-activity',label: 'Plan Activity',icon: 'solar:arrow-right-up-bold-duotone' },
] as const

type TabId = (typeof TABS)[number]['id']

export default function CatalogAnalyticsPage() {
  const [activeTab, setActiveTab] = useState<TabId>('overview')

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Catalog Intelligence</h1>
          <p className="mt-1 text-sm text-gray-500">
            Live insights across entitlements, revenue, add-on adoption, and plan changes.
          </p>
        </div>
        <div className="flex items-center gap-1.5 rounded-lg border border-gray-200 bg-gray-50 px-3 py-1.5">
          <span className="h-2 w-2 rounded-full bg-green-400 animate-pulse" />
          <span className="text-xs text-gray-500">Live data</span>
        </div>
      </div>

      {/* Tab bar */}
      <div className="border-b border-gray-200">
        <nav className="-mb-px flex gap-1">
          {TABS.map(t => (
            <button
              key={t.id}
              onClick={() => setActiveTab(t.id)}
              className={`flex items-center gap-2 whitespace-nowrap border-b-2 px-4 pb-3 text-sm font-medium transition-colors ${
                activeTab === t.id
                  ? 'border-brand-600 text-brand-600'
                  : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
              }`}
            >
              <Icon icon={t.icon} width={15} />
              {t.label}
            </button>
          ))}
        </nav>
      </div>

      {/* Tab content */}
      {activeTab === 'overview'      && <OverviewTab />}
      {activeTab === 'entitlements'  && <EntitlementsTab />}
      {activeTab === 'revenue'       && <RevenueTab />}
      {activeTab === 'plan-activity' && <PlanActivityTab />}
    </div>
  )
}

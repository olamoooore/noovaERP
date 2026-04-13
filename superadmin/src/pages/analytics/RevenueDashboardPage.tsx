import { useQuery } from '@tanstack/react-query'
import { getRevenueAnalytics, getPlanRevenue } from '@/lib/admin-api/analytics'

function formatCents(cents: number): string {
  return '$' + (cents / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function StatCard({
  label,
  value,
  sub,
  highlight = false,
}: {
  label: string
  value: string
  sub?: string
  highlight?: boolean
}) {
  return (
    <div className={`border rounded-xl p-5 ${highlight ? 'bg-brand-50 border-brand-200' : 'bg-white border-gray-200'}`}>
      <p className="text-xs font-medium text-gray-500 uppercase tracking-wide">{label}</p>
      <p className={`text-2xl font-bold mt-1 ${highlight ? 'text-brand-700' : 'text-gray-900'}`}>{value}</p>
      {sub && <p className="text-xs text-gray-400 mt-1">{sub}</p>}
    </div>
  )
}

function Tooltip({ text }: { text: string }) {
  return (
    <span className="ml-1 cursor-help text-gray-400 text-xs" title={text}>ⓘ</span>
  )
}

export default function RevenueDashboardPage() {
  const { data: revenue, isLoading: revLoading } = useQuery({
    queryKey: ['admin', 'analytics', 'revenue'],
    queryFn: getRevenueAnalytics,
  })

  const { data: plans, isLoading: planLoading } = useQuery({
    queryKey: ['admin', 'analytics', 'plans'],
    queryFn: getPlanRevenue,
  })

  const isLoading = revLoading || planLoading

  const growth = revenue?.growth
  const growthSign  = (growth?.delta_cents ?? 0) >= 0 ? '+' : ''
  const growthColor = (growth?.delta_cents ?? 0) >= 0 ? 'text-green-600' : 'text-red-600'

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-xl font-semibold text-gray-900">Revenue Analytics</h1>
        <p className="text-sm text-gray-500 mt-1">
          MRR is normalized from active subscriptions. Revenue is cash collected. These are different metrics.
        </p>
      </div>

      {isLoading ? (
        <div className="flex justify-center py-16">
          <div className="h-6 w-6 animate-spin rounded-full border-2 border-brand-600 border-t-transparent" />
        </div>
      ) : (
        <>
          {/* MRR section — normalized recurring revenue */}
          <div>
            <h2 className="text-xs font-semibold text-gray-500 uppercase tracking-widest mb-3">
              Recurring Revenue
              <Tooltip text="Normalized from active subscriptions. Monthly plans counted as-is, yearly plans divided by 12. This is the true SaaS MRR metric." />
            </h2>
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
              <StatCard
                label="MRR"
                value={formatCents(revenue?.mrr.mrr_cents ?? 0)}
                sub={`${revenue?.mrr.subscriber_count ?? 0} active subscribers`}
                highlight
              />
              <StatCard
                label="ARR"
                value={formatCents(revenue?.mrr.arr_cents ?? 0)}
                sub="MRR × 12"
              />
              <StatCard
                label="ARPU"
                value={formatCents(revenue?.arpu.arpu_cents ?? 0)}
                sub="Per active subscriber"
              />
              <StatCard
                label="Paying Ratio"
                value={`${revenue?.subscription_stats.paying_ratio ?? 0}%`}
                sub={`${revenue?.subscription_stats.active ?? 0} paying of ${
                  (revenue?.subscription_stats.active ?? 0) +
                  (revenue?.subscription_stats.trialing ?? 0) +
                  (revenue?.subscription_stats.past_due ?? 0) +
                  (revenue?.subscription_stats.canceled ?? 0)
                } total`}
              />
            </div>
          </div>

          {/* Cash revenue section */}
          <div>
            <h2 className="text-xs font-semibold text-gray-500 uppercase tracking-widest mb-3">
              Cash Flow
              <Tooltip text="Actual payments received. Fluctuates with payment timing — different from MRR." />
            </h2>
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
              <StatCard
                label="Revenue (30d)"
                value={formatCents(revenue?.revenue_last_30d.revenue_cents ?? 0)}
                sub={`${revenue?.revenue_last_30d.invoice_count ?? 0} paid invoices`}
              />
              <StatCard
                label="Churn Rate"
                value={`${revenue?.churn.churn_rate_percent ?? 0}%`}
                sub={`${revenue?.churn.canceled_count ?? 0} canceled this month`}
              />
              <div className="bg-white border border-gray-200 rounded-xl p-5">
                <p className="text-xs font-medium text-gray-500 uppercase tracking-wide">MoM Growth</p>
                <p className={`text-2xl font-bold mt-1 ${growthColor}`}>
                  {growthSign}{growth?.growth_percent != null ? `${growth.growth_percent}%` : 'N/A'}
                </p>
                <p className="text-xs text-gray-400 mt-1">
                  {growthSign}{formatCents(Math.abs(growth?.delta_cents ?? 0))} vs last month
                </p>
              </div>
              <div />
            </div>
          </div>

          {/* Subscription status breakdown */}
          <div className="bg-white border border-gray-200 rounded-xl p-5">
            <h2 className="text-sm font-semibold text-gray-700 mb-4">Subscription Status</h2>
            <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
              {(['active', 'trialing', 'past_due', 'canceled'] as const).map(status => (
                <div key={status} className="text-center">
                  <p className="text-2xl font-bold text-gray-900">
                    {revenue?.subscription_stats[status] ?? 0}
                  </p>
                  <p className="text-xs text-gray-500 capitalize mt-1">{status.replace('_', ' ')}</p>
                </div>
              ))}
            </div>
          </div>

          {/* MRR by plan — normalized values */}
          {plans && plans.length > 0 && (
            <div className="bg-white border border-gray-200 rounded-xl overflow-hidden">
              <div className="px-5 py-3 border-b border-gray-100">
                <h2 className="text-sm font-semibold text-gray-700">
                  MRR by Plan
                  <span className="text-gray-400 font-normal text-xs ml-2">(normalized from active subscriptions)</span>
                </h2>
              </div>
              <table className="w-full text-sm">
                <thead className="bg-gray-50 text-left text-xs text-gray-500 uppercase tracking-wide">
                  <tr>
                    <th className="px-5 py-3">Plan</th>
                    <th className="px-5 py-3">Subscribers</th>
                    <th className="px-5 py-3 text-right">MRR</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {plans.map(p => (
                    <tr key={p.plan_name} className="hover:bg-gray-50">
                      <td className="px-5 py-3 font-medium text-gray-900">{p.plan_name}</td>
                      <td className="px-5 py-3 text-gray-600">{p.subscriber_count}</td>
                      <td className="px-5 py-3 text-right font-medium text-gray-900">
                        {formatCents(p.mrr_cents)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      )}
    </div>
  )
}

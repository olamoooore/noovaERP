import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { getSubscriptions } from '@/lib/admin-api/subscriptions'
import { Subscription, SubscriptionStatus } from '@/types/admin'

const STATUS_OPTIONS = [
  { label: 'All Statuses', value: '' },
  { label: 'Active',       value: 'active' },
  { label: 'Trialing',     value: 'trialing' },
  { label: 'Past Due',     value: 'past_due' },
  { label: 'Canceled',     value: 'canceled' },
  { label: 'Expired',      value: 'expired' },
]

const STATUS_STYLES: Record<SubscriptionStatus, string> = {
  active:    'bg-green-100 text-green-800',
  trialing:  'bg-blue-100 text-blue-700',
  past_due:  'bg-orange-100 text-orange-800',
  canceled:  'bg-gray-100 text-gray-600',
  expired:   'bg-red-100 text-red-700',
}

const STATUS_LABELS: Record<SubscriptionStatus, string> = {
  active:   'Active',
  trialing: 'Trial',
  past_due: 'Past Due',
  canceled: 'Canceled',
  expired:  'Expired',
}

function SubStatusBadge({ status }: { status: SubscriptionStatus }) {
  return (
    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${STATUS_STYLES[status] ?? 'bg-gray-100 text-gray-700'}`}>
      {STATUS_LABELS[status] ?? status}
    </span>
  )
}

function formatDate(d: string | null | undefined) {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

function formatMoney(cents: number, currency = 'USD') {
  return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(cents / 100)
}

export default function SubscriptionListPage() {
  const navigate = useNavigate()
  const [status, setStatus]   = useState('')
  const [search, setSearch]   = useState('')
  const [inputVal, setInputVal] = useState('')
  const [page, setPage]       = useState(1)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'subscriptions', { status, search, page }],
    queryFn: () => getSubscriptions({ status: status || undefined, search: search || undefined, page }),
  })

  const subscriptions: Subscription[] = data?.data ?? []
  const meta = data?.meta

  function handleSearch(e: React.FormEvent) {
    e.preventDefault()
    setSearch(inputVal)
    setPage(1)
  }

  return (
    <div className="p-6 space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Subscriptions</h1>
        <p className="text-sm text-gray-500 mt-1">
          {meta ? `${meta.total} subscription${meta.total !== 1 ? 's' : ''} total` : 'Loading…'}
        </p>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-3 items-center">
        <form onSubmit={handleSearch} className="flex gap-2">
          <input
            type="text"
            value={inputVal}
            onChange={e => setInputVal(e.target.value)}
            placeholder="Search by tenant name…"
            className="border border-gray-300 rounded-lg px-3 py-2 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-brand-500"
          />
          <button type="submit" className="bg-brand-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-700">
            Search
          </button>
        </form>

        <select
          value={status}
          onChange={e => { setStatus(e.target.value); setPage(1) }}
          className="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
        >
          {STATUS_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
        </select>
      </div>

      {/* Table */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        {isLoading ? (
          <div className="p-12 text-center text-gray-400 text-sm">Loading subscriptions…</div>
        ) : isError ? (
          <div className="p-12 text-center text-red-500 text-sm">Failed to load subscriptions.</div>
        ) : subscriptions.length === 0 ? (
          <div className="p-12 text-center text-gray-400 text-sm">No subscriptions found.</div>
        ) : (
          <table className="w-full text-sm text-left">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="px-4 py-3 font-medium text-gray-600">Tenant</th>
                <th className="px-4 py-3 font-medium text-gray-600">Plan</th>
                <th className="px-4 py-3 font-medium text-gray-600">Interval</th>
                <th className="px-4 py-3 font-medium text-gray-600">Status</th>
                <th className="px-4 py-3 font-medium text-gray-600">Period End</th>
                <th className="px-4 py-3 font-medium text-gray-600">MRR</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {subscriptions.map(sub => {
                const price = sub.plan_version?.prices?.find(p => p.billing_interval === 'monthly')
                  ?? sub.plan_version?.prices?.[0]
                const mrr = price ? price.unit_amount : null

                return (
                  <tr
                    key={sub.id}
                    onClick={() => navigate(`/subscriptions/${sub.id}`)}
                    className="hover:bg-gray-50 cursor-pointer transition-colors"
                  >
                    <td className="px-4 py-3 text-gray-900 font-medium font-mono text-xs">
                      {sub.tenant_id}
                    </td>
                    <td className="px-4 py-3 text-gray-700">
                      {sub.plan_version?.family?.name ?? '—'}
                      <span className="ml-1 text-gray-400 text-xs">{sub.plan_version?.version_label}</span>
                    </td>
                    <td className="px-4 py-3 text-gray-500 capitalize">
                      {sub.plan_version?.prices?.find(p => p)?.billing_interval ?? '—'}
                    </td>
                    <td className="px-4 py-3">
                      <SubStatusBadge status={sub.status} />
                    </td>
                    <td className="px-4 py-3 text-gray-500">{formatDate(sub.current_period_end)}</td>
                    <td className="px-4 py-3 text-gray-700 font-medium">
                      {mrr != null ? formatMoney(mrr) : '—'}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-gray-500">Page {meta.page} of {meta.last_page}</p>
          <div className="flex gap-2">
            <button disabled={meta.page <= 1} onClick={() => setPage(p => p - 1)}
              className="px-3 py-1.5 text-sm border border-gray-300 rounded-lg disabled:opacity-40 hover:bg-gray-50">
              Previous
            </button>
            <button disabled={meta.page >= meta.last_page} onClick={() => setPage(p => p + 1)}
              className="px-3 py-1.5 text-sm border border-gray-300 rounded-lg disabled:opacity-40 hover:bg-gray-50">
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

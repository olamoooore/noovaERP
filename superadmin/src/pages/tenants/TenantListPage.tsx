import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { getTenants } from '@/lib/admin-api/tenants'
import { Tenant, TenantStatus } from '@/types/admin'

const STATUS_OPTIONS: { label: string; value: string }[] = [
  { label: 'All Statuses', value: '' },
  { label: 'Active', value: 'active' },
  { label: 'Suspended', value: 'suspended' },
  { label: 'Trial', value: 'trial' },
]

function StatusBadge({ status }: { status: string }) {
  const styles: Record<string, string> = {
    active:    'bg-green-100 text-green-800',
    suspended: 'bg-red-100 text-red-800',
    trial:     'bg-yellow-100 text-yellow-800',
  }
  return (
    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium capitalize ${styles[status] ?? 'bg-gray-100 text-gray-700'}`}>
      {status}
    </span>
  )
}

function formatDate(dateStr: string | null | undefined) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
  })
}

export default function TenantListPage() {
  const navigate = useNavigate()
  const [search, setSearch]   = useState('')
  const [status, setStatus]   = useState<string>('')
  const [plan, setPlan]       = useState('')
  const [page, setPage]       = useState(1)
  const [inputValue, setInputValue] = useState('')

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'tenants', { search, status, plan, page }],
    queryFn: () => getTenants({
      search: search || undefined,
      status: (status as TenantStatus) || undefined,
      plan: plan || undefined,
      page,
    }),
  })

  function handleSearchSubmit(e: React.FormEvent) {
    e.preventDefault()
    setSearch(inputValue)
    setPage(1)
  }

  function handleStatusChange(val: string) {
    setStatus(val)
    setPage(1)
  }

  const tenants: Tenant[] = data?.data ?? []
  const meta = data?.meta

  return (
    <div className="p-6 space-y-6">
      {/* Header */}
      <div>
        <h1 className="text-2xl font-bold text-gray-900">Tenants</h1>
        <p className="text-sm text-gray-500 mt-1">
          {meta ? `${meta.total} tenant${meta.total !== 1 ? 's' : ''} total` : 'Loading...'}
        </p>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-3 items-center">
        <form onSubmit={handleSearchSubmit} className="flex gap-2">
          <input
            type="text"
            value={inputValue}
            onChange={e => setInputValue(e.target.value)}
            placeholder="Search by name, email or domain…"
            className="border border-gray-300 rounded-lg px-3 py-2 text-sm w-72 focus:outline-none focus:ring-2 focus:ring-brand-500"
          />
          <button
            type="submit"
            className="bg-brand-600 text-white px-4 py-2 rounded-lg text-sm font-medium hover:bg-brand-700"
          >
            Search
          </button>
        </form>

        <select
          value={status}
          onChange={e => handleStatusChange(e.target.value)}
          className="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
        >
          {STATUS_OPTIONS.map(opt => (
            <option key={opt.value} value={opt.value}>{opt.label}</option>
          ))}
        </select>

        <input
          type="text"
          value={plan}
          onChange={e => { setPlan(e.target.value); setPage(1) }}
          placeholder="Filter by plan…"
          className="border border-gray-300 rounded-lg px-3 py-2 text-sm w-40 focus:outline-none focus:ring-2 focus:ring-brand-500"
        />
      </div>

      {/* Table */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        {isLoading ? (
          <div className="p-12 text-center text-gray-400 text-sm">Loading tenants…</div>
        ) : isError ? (
          <div className="p-12 text-center text-red-500 text-sm">Failed to load tenants. Check the backend is running.</div>
        ) : tenants.length === 0 ? (
          <div className="p-12 text-center text-gray-400 text-sm">No tenants found.</div>
        ) : (
          <table className="w-full text-sm text-left">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="px-4 py-3 font-medium text-gray-600">Domain</th>
                <th className="px-4 py-3 font-medium text-gray-600">Name</th>
                <th className="px-4 py-3 font-medium text-gray-600">Plan</th>
                <th className="px-4 py-3 font-medium text-gray-600">Status</th>
                <th className="px-4 py-3 font-medium text-gray-600">Since</th>
                <th className="px-4 py-3 font-medium text-gray-600">Last Health Check</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {tenants.map(tenant => (
                <tr
                  key={tenant.id}
                  onClick={() => navigate(`/tenants/${tenant.id}`)}
                  className="hover:bg-gray-50 cursor-pointer transition-colors"
                >
                  <td className="px-4 py-3 font-mono text-xs text-brand-700">
                    {tenant.domain ?? '—'}
                  </td>
                  <td className="px-4 py-3 text-gray-900 font-medium">{tenant.name ?? '—'}</td>
                  <td className="px-4 py-3 text-gray-600 capitalize">{tenant.plan ?? '—'}</td>
                  <td className="px-4 py-3">
                    <StatusBadge status={tenant.status} />
                  </td>
                  <td className="px-4 py-3 text-gray-500">{formatDate(tenant.created_at)}</td>
                  <td className="px-4 py-3 text-gray-500">{formatDate(tenant.last_health_check)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>

      {/* Pagination */}
      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <p className="text-sm text-gray-500">
            Page {meta.page} of {meta.last_page}
          </p>
          <div className="flex gap-2">
            <button
              disabled={meta.page <= 1}
              onClick={() => setPage(p => p - 1)}
              className="px-3 py-1.5 text-sm border border-gray-300 rounded-lg disabled:opacity-40 hover:bg-gray-50"
            >
              Previous
            </button>
            <button
              disabled={meta.page >= meta.last_page}
              onClick={() => setPage(p => p + 1)}
              className="px-3 py-1.5 text-sm border border-gray-300 rounded-lg disabled:opacity-40 hover:bg-gray-50"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

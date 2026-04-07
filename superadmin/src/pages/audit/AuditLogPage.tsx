import { useState, useCallback } from 'react'
import { useQuery } from '@tanstack/react-query'
import { getAuditLogs } from '@/lib/admin-api/audit'
import type { AdminActionLog } from '@/types/admin'

// ─── Helpers ─────────────────────────────────────────────────────────────────

function formatDateTime(d: string) {
  return new Date(d).toLocaleString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
}

function ActionBadge({ action }: { action: string }) {
  const prefix = action.split('.')[0] ?? action
  const colorMap: Record<string, string> = {
    plan:         'bg-indigo-100 text-indigo-800',
    module:       'bg-purple-100 text-purple-800',
    tenant:       'bg-cyan-100 text-cyan-800',
    subscription: 'bg-blue-100 text-blue-800',
    invoice:      'bg-amber-100 text-amber-800',
    billing:      'bg-amber-100 text-amber-800',
  }
  const cls = colorMap[prefix] ?? 'bg-gray-100 text-gray-700'
  return (
    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${cls}`}>
      {action}
    </span>
  )
}

function StatusDot({ status }: { status: 'success' | 'failure' }) {
  return status === 'success' ? (
    <span className="inline-flex items-center gap-1 text-green-700 text-xs font-medium">
      <span className="h-1.5 w-1.5 rounded-full bg-green-500" />
      success
    </span>
  ) : (
    <span className="inline-flex items-center gap-1 text-red-700 text-xs font-medium">
      <span className="h-1.5 w-1.5 rounded-full bg-red-500" />
      failure
    </span>
  )
}

// ─── JSON Diff Viewer ────────────────────────────────────────────────────────

function JsonBlock({ label, data }: { label: string; data: Record<string, unknown> | null }) {
  if (!data || Object.keys(data).length === 0) {
    return (
      <div className="flex-1 min-w-0">
        <p className="text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">{label}</p>
        <div className="rounded-lg bg-gray-50 border border-gray-200 p-3 text-xs text-gray-400 italic">
          — empty —
        </div>
      </div>
    )
  }
  return (
    <div className="flex-1 min-w-0">
      <p className="text-xs font-semibold text-gray-500 mb-1.5 uppercase tracking-wide">{label}</p>
      <pre className="rounded-lg bg-gray-900 text-green-300 text-xs p-3 overflow-auto max-h-64 leading-relaxed">
        {JSON.stringify(data, null, 2)}
      </pre>
    </div>
  )
}

// ─── Expanded Row ────────────────────────────────────────────────────────────

function ExpandedRow({ log }: { log: AdminActionLog }) {
  const hasSnapshotData = log.before_snapshot || log.after_snapshot || log.reason || log.ip_address
  return (
    <tr>
      <td colSpan={6} className="bg-gray-50 border-b border-gray-200">
        <div className="px-6 py-4 space-y-4">
          {/* Meta row */}
          <div className="flex flex-wrap gap-6 text-sm text-gray-600">
            {log.admin_user && (
              <div>
                <span className="font-medium text-gray-700">Admin:</span>{' '}
                {log.admin_user.name} ({log.admin_user.email})
              </div>
            )}
            {log.ip_address && (
              <div>
                <span className="font-medium text-gray-700">IP:</span> {log.ip_address}
              </div>
            )}
            {log.target_type && (
              <div>
                <span className="font-medium text-gray-700">Target:</span>{' '}
                {log.target_type}
                {log.target_id ? ` #${log.target_id}` : ''}
              </div>
            )}
            {log.reason && (
              <div>
                <span className="font-medium text-gray-700">Reason:</span>{' '}
                {log.reason}
              </div>
            )}
          </div>

          {/* Snapshot diff */}
          {(log.before_snapshot || log.after_snapshot) && (
            <div className="flex gap-4">
              <JsonBlock label="Before" data={log.before_snapshot} />
              <JsonBlock label="After"  data={log.after_snapshot} />
            </div>
          )}

          {!hasSnapshotData && (
            <p className="text-sm text-gray-400 italic">No additional details recorded.</p>
          )}
        </div>
      </td>
    </tr>
  )
}

// ─── Main Page ────────────────────────────────────────────────────────────────

const TARGET_TYPES = [
  '', 'PlanFamily', 'PlanVersion', 'ModuleCatalogEntry',
  'Tenant', 'Subscription', 'SubscriptionInvoice',
]

export default function AuditLogPage() {
  // Committed filter state (triggers query)
  const [search,      setSearch]      = useState('')
  const [action,      setAction]      = useState('')
  const [targetType,  setTargetType]  = useState('')
  const [status,      setStatus]      = useState<'' | 'success' | 'failure'>('')
  const [dateFrom,    setDateFrom]    = useState('')
  const [dateTo,      setDateTo]      = useState('')
  const [page,        setPage]        = useState(1)

  // Draft search (debounce-style: commit on Enter or blur)
  const [searchDraft, setSearchDraft] = useState('')
  const [actionDraft, setActionDraft] = useState('')

  const [expandedId, setExpandedId] = useState<number | null>(null)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'audit-logs', { search, action, targetType, status, dateFrom, dateTo, page }],
    queryFn: () =>
      getAuditLogs({
        search:      search      || undefined,
        action:      action      || undefined,
        target_type: targetType  || undefined,
        status:      (status as 'success' | 'failure') || undefined,
        date_from:   dateFrom    || undefined,
        date_to:     dateTo      || undefined,
        page,
        per_page: 25,
      }),
    placeholderData: prev => prev,
  })

  const logs: AdminActionLog[] = data?.data ?? []
  const meta = data?.meta

  const commitSearch = useCallback(() => {
    setSearch(searchDraft)
    setAction(actionDraft)
    setPage(1)
  }, [searchDraft, actionDraft])

  function resetFilters() {
    setSearch('');      setSearchDraft('')
    setAction('');      setActionDraft('')
    setTargetType('');  setStatus('')
    setDateFrom('');    setDateTo('')
    setPage(1)
  }

  function toggleExpand(id: number) {
    setExpandedId(prev => (prev === id ? null : id))
  }

  const hasActiveFilters = search || action || targetType || status || dateFrom || dateTo

  return (
    <div className="space-y-6">
      {/* Page header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Audit Log</h1>
          <p className="text-sm text-gray-500 mt-0.5">
            Immutable record of every admin action on the platform
          </p>
        </div>
        {meta && (
          <span className="text-sm text-gray-400">
            {meta.total.toLocaleString()} total events
          </span>
        )}
      </div>

      {/* Filters */}
      <div className="bg-white rounded-xl border border-gray-200 p-4 space-y-3">
        {/* Row 1: search + action keyword */}
        <div className="flex flex-wrap gap-3">
          <input
            type="text"
            placeholder="Search action, target type, target ID, IP…"
            value={searchDraft}
            onChange={e => setSearchDraft(e.target.value)}
            onKeyDown={e => e.key === 'Enter' && commitSearch()}
            onBlur={commitSearch}
            className="flex-1 min-w-56 rounded-lg border border-gray-300 px-3 py-2 text-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500"
          />
          <input
            type="text"
            placeholder="Filter by action prefix (e.g. plan)"
            value={actionDraft}
            onChange={e => setActionDraft(e.target.value)}
            onKeyDown={e => e.key === 'Enter' && commitSearch()}
            onBlur={commitSearch}
            className="w-56 rounded-lg border border-gray-300 px-3 py-2 text-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500"
          />
          <button
            onClick={commitSearch}
            className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500"
          >
            Search
          </button>
        </div>

        {/* Row 2: dropdowns + date range */}
        <div className="flex flex-wrap gap-3 items-end">
          <div>
            <label className="block text-xs text-gray-500 mb-1">Target Type</label>
            <select
              value={targetType}
              onChange={e => { setTargetType(e.target.value); setPage(1) }}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
            >
              {TARGET_TYPES.map(t => (
                <option key={t} value={t}>{t || 'All Types'}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-xs text-gray-500 mb-1">Status</label>
            <select
              value={status}
              onChange={e => { setStatus(e.target.value as '' | 'success' | 'failure'); setPage(1) }}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
            >
              <option value="">All Statuses</option>
              <option value="success">Success</option>
              <option value="failure">Failure</option>
            </select>
          </div>

          <div>
            <label className="block text-xs text-gray-500 mb-1">From Date</label>
            <input
              type="date"
              value={dateFrom}
              onChange={e => { setDateFrom(e.target.value); setPage(1) }}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
            />
          </div>

          <div>
            <label className="block text-xs text-gray-500 mb-1">To Date</label>
            <input
              type="date"
              value={dateTo}
              onChange={e => { setDateTo(e.target.value); setPage(1) }}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500"
            />
          </div>

          {hasActiveFilters && (
            <button
              onClick={resetFilters}
              className="ml-auto self-end rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50"
            >
              Clear filters
            </button>
          )}
        </div>
      </div>

      {/* Table */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        {isError && (
          <div className="p-6 text-center text-red-600 text-sm">
            Failed to load audit logs. Please try again.
          </div>
        )}

        {isLoading && !data ? (
          <div className="p-12 flex justify-center">
            <div className="h-6 w-6 animate-spin rounded-full border-2 border-brand-600 border-t-transparent" />
          </div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="px-4 py-3 text-left font-semibold text-gray-700 w-10" />
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Timestamp</th>
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Action</th>
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Target</th>
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Admin</th>
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {logs.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-6 py-12 text-center text-gray-400">
                    {hasActiveFilters ? 'No events match the current filters.' : 'No audit events recorded yet.'}
                  </td>
                </tr>
              ) : (
                logs.map(log => (
                  <>
                    <tr
                      key={log.id}
                      onClick={() => toggleExpand(log.id)}
                      className={`cursor-pointer transition-colors ${
                        expandedId === log.id
                          ? 'bg-indigo-50'
                          : 'hover:bg-gray-50'
                      }`}
                    >
                      {/* Expand chevron */}
                      <td className="pl-4 pr-2 py-3 text-gray-400">
                        <svg
                          className={`h-4 w-4 transition-transform ${expandedId === log.id ? 'rotate-90' : ''}`}
                          fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}
                        >
                          <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
                        </svg>
                      </td>

                      <td className="px-4 py-3 text-gray-500 whitespace-nowrap text-xs">
                        {formatDateTime(log.created_at)}
                      </td>

                      <td className="px-4 py-3">
                        <ActionBadge action={log.action} />
                      </td>

                      <td className="px-4 py-3 text-gray-600 text-xs">
                        {log.target_type ? (
                          <span>
                            <span className="font-medium">{log.target_type}</span>
                            {log.target_id && (
                              <span className="text-gray-400"> #{log.target_id}</span>
                            )}
                          </span>
                        ) : (
                          <span className="text-gray-300">—</span>
                        )}
                      </td>

                      <td className="px-4 py-3 text-gray-600 text-xs">
                        {log.admin_user ? (
                          <span title={log.admin_user.email}>{log.admin_user.name}</span>
                        ) : log.admin_user_id ? (
                          <span className="text-gray-400">#{log.admin_user_id}</span>
                        ) : (
                          <span className="text-gray-300">System</span>
                        )}
                      </td>

                      <td className="px-4 py-3">
                        <StatusDot status={log.status} />
                      </td>
                    </tr>

                    {expandedId === log.id && (
                      <ExpandedRow key={`${log.id}-expanded`} log={log} />
                    )}
                  </>
                ))
              )}
            </tbody>
          </table>
        )}

        {/* Pagination */}
        {meta && meta.last_page > 1 && (
          <div className="flex items-center justify-between border-t border-gray-200 px-6 py-3 text-sm text-gray-600">
            <span>
              Page {meta.page} of {meta.last_page} &nbsp;·&nbsp; {meta.total.toLocaleString()} events
            </span>
            <div className="flex gap-2">
              <button
                onClick={() => setPage(p => Math.max(1, p - 1))}
                disabled={meta.page <= 1}
                className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs disabled:opacity-40 hover:bg-gray-50"
              >
                ← Prev
              </button>
              <button
                onClick={() => setPage(p => Math.min(meta.last_page, p + 1))}
                disabled={meta.page >= meta.last_page}
                className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs disabled:opacity-40 hover:bg-gray-50"
              >
                Next →
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

import { useState, useCallback, useEffect } from 'react'
import { useQuery, useMutation } from '@tanstack/react-query'
import { Link, useSearchParams } from 'react-router-dom'
import { getAuditLogs, getAuditStats, downloadAuditCsv } from '@/lib/admin-api/audit'
import { FieldDiff, computeDiff } from '@/components/audit/FieldDiff'
import { SeverityBadge } from '@/components/audit/SeverityBadge'
import type { AdminActionLog, AuditSeverity } from '@/types/admin'

// ─── Helpers ─────────────────────────────────────────────────────────────────

function formatDateTime(d: string) {
  return new Date(d).toLocaleString('en-US', {
    year: 'numeric', month: 'short', day: 'numeric',
    hour: '2-digit', minute: '2-digit', second: '2-digit',
  })
}

function timeAgo(d: string) {
  const diff = Date.now() - new Date(d).getTime()
  const mins = Math.floor(diff / 60_000)
  if (mins < 1)   return 'just now'
  if (mins < 60)  return `${mins}m ago`
  const hrs = Math.floor(diff / 3_600_000)
  if (hrs < 24)   return `${hrs}h ago`
  return `${Math.floor(diff / 86_400_000)}d ago`
}

// Map target_type → route prefix for deep links
const ENTITY_ROUTES: Record<string, string> = {
  Tenant:              '/tenants',
  Subscription:        '/subscriptions',
  SubscriptionInvoice: '/billing',
}

function EntityLink({ type, id }: { type: string | null; id: string | null }) {
  if (!type || !id) return <span className="text-gray-300">—</span>

  const base = ENTITY_ROUTES[type]
  const label = (
    <span>
      <span className="font-medium text-gray-700">{type}</span>
      <span className="text-gray-400"> #{id}</span>
    </span>
  )

  if (!base) return <span className="text-xs">{label}</span>

  return (
    <Link
      to={`${base}/${id}`}
      onClick={e => e.stopPropagation()}
      className="text-xs hover:text-brand-600 underline-offset-2 hover:underline"
    >
      {label}
    </Link>
  )
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
    health:       'bg-teal-100 text-teal-700',
  }
  return (
    <span className={`inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ${colorMap[prefix] ?? 'bg-gray-100 text-gray-700'}`}>
      {action}
    </span>
  )
}

// ─── Expanded Row ─────────────────────────────────────────────────────────────

function ExpandedRow({ log }: { log: AdminActionLog }) {
  const hasDiffData = log.before_snapshot || log.after_snapshot
  const clientDiffs = hasDiffData ? computeDiff(log.before_snapshot, log.after_snapshot) : []

  return (
    <tr>
      <td colSpan={7} className="bg-indigo-50/40 border-b border-indigo-100">
        <div className="px-6 py-4 space-y-4">
          {/* Meta */}
          <div className="flex flex-wrap gap-x-6 gap-y-1 text-sm">
            {log.human_description && (
              <p className="w-full text-gray-800 font-medium">{log.human_description}</p>
            )}
            {log.admin_user && (
              <span className="text-gray-500">
                <span className="font-medium text-gray-700">Admin:</span>{' '}
                {log.admin_user.name}
                <span className="text-gray-400 ml-1">({log.admin_user.email})</span>
              </span>
            )}
            {log.ip_address && (
              <span className="text-gray-500">
                <span className="font-medium text-gray-700">IP:</span> {log.ip_address}
              </span>
            )}
            {log.reason && (
              <span className="text-gray-500">
                <span className="font-medium text-gray-700">Reason:</span> {log.reason}
              </span>
            )}
            <span className="text-gray-400 text-xs self-end">
              {formatDateTime(log.created_at)}
            </span>
          </div>

          {/* Field diff */}
          {hasDiffData && (
            <FieldDiff
              before={log.before_snapshot}
              after={log.after_snapshot}
              changes={log.diff ?? (clientDiffs.length > 0 ? clientDiffs : undefined)}
            />
          )}

          {!hasDiffData && !log.human_description && !log.reason && !log.ip_address && (
            <p className="text-xs text-gray-400 italic">No additional details recorded.</p>
          )}
        </div>
      </td>
    </tr>
  )
}

// ─── Stat Card ────────────────────────────────────────────────────────────────

function StatCard({ label, value, color }: { label: string; value: number; color?: string }) {
  return (
    <div className="bg-white rounded-xl border border-gray-200 p-4">
      <p className="text-xs text-gray-500 uppercase tracking-wide">{label}</p>
      <p className={`text-2xl font-bold mt-1 ${color ?? 'text-gray-900'}`}>{value.toLocaleString()}</p>
    </div>
  )
}

// ─── Constants ────────────────────────────────────────────────────────────────

const TARGET_TYPES = [
  '', 'PlanFamily', 'PlanVersion', 'ModuleCatalogEntry',
  'Tenant', 'Subscription', 'SubscriptionInvoice',
]

const SEVERITY_OPTIONS = [
  { value: '',         label: 'All Severities' },
  { value: 'info',     label: 'Info' },
  { value: 'warning',  label: 'Warning' },
  { value: 'critical', label: 'Critical' },
]

// ─── Main Page ────────────────────────────────────────────────────────────────

export default function AuditLogPage() {
  const [searchParams] = useSearchParams()

  // Committed filters (drive query)
  const [search,     setSearch]     = useState('')
  const [action,     setAction]     = useState('')
  const [targetType, setTargetType] = useState(searchParams.get('target_type') ?? '')
  const [targetId,   setTargetId]   = useState(searchParams.get('target_id') ?? '')
  const [status,     setStatus]     = useState<'' | 'success' | 'failure'>('')
  const [severity,   setSeverity]   = useState<'' | AuditSeverity>('')
  const [dateFrom,   setDateFrom]   = useState('')
  const [dateTo,     setDateTo]     = useState('')
  const [page,       setPage]       = useState(1)
  const [liveMode,   setLiveMode]   = useState(false)

  // Draft inputs — committed on Enter / blur
  const [searchDraft,   setSearchDraft]   = useState('')
  const [actionDraft,   setActionDraft]   = useState('')
  const [targetIdDraft, setTargetIdDraft] = useState(searchParams.get('target_id') ?? '')

  const [expandedId, setExpandedId] = useState<number | null>(null)

  // Deep-link: pre-fill from URL params on first mount
  useEffect(() => {
    const t = searchParams.get('target_type')
    const id = searchParams.get('target_id')
    if (t)  setTargetType(t)
    if (id) { setTargetId(id); setTargetIdDraft(id) }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const { data, isLoading, isError } = useQuery({
    queryKey: ['admin', 'audit-logs', { search, action, targetType, targetId, status, severity, dateFrom, dateTo, page }],
    queryFn: () => getAuditLogs({
      search:      search      || undefined,
      action:      action      || undefined,
      target_type: targetType  || undefined,
      target_id:   targetId    || undefined,
      status:      (status   as 'success' | 'failure') || undefined,
      severity:    (severity as AuditSeverity)         || undefined,
      date_from:   dateFrom    || undefined,
      date_to:     dateTo      || undefined,
      page,
      per_page: 25,
    }),
    placeholderData: prev => prev,
    refetchInterval: liveMode ? 5_000 : false,
  })

  const { data: stats } = useQuery({
    queryKey: ['admin', 'audit-logs', 'stats'],
    queryFn: getAuditStats,
    staleTime: 60_000,
    refetchInterval: liveMode ? 15_000 : false,
  })

  const exportMutation = useMutation({
    mutationFn: () => downloadAuditCsv({
      search:      search      || undefined,
      action:      action      || undefined,
      target_type: targetType  || undefined,
      target_id:   targetId    || undefined,
      status:      (status   as 'success' | 'failure') || undefined,
      severity:    (severity as AuditSeverity)         || undefined,
      date_from:   dateFrom    || undefined,
      date_to:     dateTo      || undefined,
    }),
  })

  const logs: AdminActionLog[] = data?.data ?? []
  const meta = data?.meta

  const commitFilters = useCallback(() => {
    setSearch(searchDraft)
    setAction(actionDraft)
    setTargetId(targetIdDraft)
    setPage(1)
  }, [searchDraft, actionDraft, targetIdDraft])

  function resetFilters() {
    setSearch('');      setSearchDraft('')
    setAction('');      setActionDraft('')
    setTargetType('');  setTargetId(''); setTargetIdDraft('')
    setStatus('');      setSeverity('')
    setDateFrom('');    setDateTo('')
    setPage(1)
  }

  const hasActiveFilters = search || action || targetType || targetId || status || severity || dateFrom || dateTo

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Audit Log</h1>
          <p className="text-sm text-gray-500 mt-0.5">
            Immutable record of every admin action — {meta?.total.toLocaleString() ?? '…'} total events
          </p>
        </div>
        <div className="flex items-center gap-2">
          <button
            onClick={() => setLiveMode(v => !v)}
            className={`flex items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-medium transition-colors ${
              liveMode
                ? 'border-green-300 bg-green-50 text-green-700'
                : 'border-gray-300 bg-white text-gray-600 hover:bg-gray-50'
            }`}
          >
            <span className={`h-1.5 w-1.5 rounded-full ${liveMode ? 'bg-green-500 animate-pulse' : 'bg-gray-400'}`} />
            {liveMode ? 'LIVE' : 'Live off'}
          </button>
          <button
            onClick={() => exportMutation.mutate()}
            disabled={exportMutation.isPending}
            className="rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-600 hover:bg-gray-50 disabled:opacity-40"
          >
            {exportMutation.isPending ? 'Exporting…' : '↓ Export CSV'}
          </button>
        </div>
      </div>

      {/* Today's stat cards */}
      {stats && (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <StatCard label="Events today"   value={stats.today_total}   />
          <StatCard label="Failures today" value={stats.today_failures} color="text-red-600" />
          <StatCard label="Critical today" value={stats.today_critical} color="text-red-700" />
          <StatCard label="Warnings today" value={stats.today_warnings} color="text-amber-600" />
        </div>
      )}

      {/* Filters */}
      <div className="bg-white rounded-xl border border-gray-200 p-4 space-y-3">
        <div className="flex flex-wrap gap-2">
          <input
            type="text"
            placeholder="Search description, action, target, IP…"
            value={searchDraft}
            onChange={e => setSearchDraft(e.target.value)}
            onKeyDown={e => e.key === 'Enter' && commitFilters()}
            onBlur={commitFilters}
            className="flex-1 min-w-52 rounded-lg border border-gray-300 px-3 py-2 text-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500"
          />
          <input
            type="text"
            placeholder="Action prefix (e.g. plan)"
            value={actionDraft}
            onChange={e => setActionDraft(e.target.value)}
            onKeyDown={e => e.key === 'Enter' && commitFilters()}
            onBlur={commitFilters}
            className="w-44 rounded-lg border border-gray-300 px-3 py-2 text-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500"
          />
          <input
            type="text"
            placeholder="Target ID"
            value={targetIdDraft}
            onChange={e => setTargetIdDraft(e.target.value)}
            onKeyDown={e => e.key === 'Enter' && commitFilters()}
            onBlur={commitFilters}
            className="w-36 rounded-lg border border-gray-300 px-3 py-2 text-sm placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-brand-500"
          />
          <button
            onClick={commitFilters}
            className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
          >
            Search
          </button>
        </div>

        <div className="flex flex-wrap gap-3 items-end">
          <div>
            <label className="block text-xs text-gray-500 mb-1">Target Type</label>
            <select value={targetType} onChange={e => { setTargetType(e.target.value); setPage(1) }}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
              {TARGET_TYPES.map(t => <option key={t} value={t}>{t || 'All Types'}</option>)}
            </select>
          </div>

          <div>
            <label className="block text-xs text-gray-500 mb-1">Severity</label>
            <select value={severity} onChange={e => { setSeverity(e.target.value as '' | AuditSeverity); setPage(1) }}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
              {SEVERITY_OPTIONS.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}
            </select>
          </div>

          <div>
            <label className="block text-xs text-gray-500 mb-1">Status</label>
            <select value={status} onChange={e => { setStatus(e.target.value as '' | 'success' | 'failure'); setPage(1) }}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500">
              <option value="">All</option>
              <option value="success">Success</option>
              <option value="failure">Failure</option>
            </select>
          </div>

          <div>
            <label className="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" value={dateFrom} onChange={e => { setDateFrom(e.target.value); setPage(1) }}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
          </div>

          <div>
            <label className="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" value={dateTo} onChange={e => { setDateTo(e.target.value); setPage(1) }}
              className="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
          </div>

          {hasActiveFilters && (
            <button onClick={resetFilters}
              className="ml-auto self-end rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">
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
                <th className="w-8" />
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Sev.</th>
                <th className="px-4 py-3 text-left font-semibold text-gray-700 whitespace-nowrap">Time</th>
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Description</th>
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Target</th>
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Admin</th>
                <th className="px-4 py-3 text-left font-semibold text-gray-700">Status</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {logs.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-6 py-12 text-center text-gray-400">
                    {hasActiveFilters ? 'No events match the current filters.' : 'No audit events recorded yet.'}
                  </td>
                </tr>
              ) : (
                logs.map(log => (
                  <>
                    <tr
                      key={log.id}
                      onClick={() => setExpandedId(prev => prev === log.id ? null : log.id)}
                      className={`cursor-pointer transition-colors ${
                        expandedId === log.id ? 'bg-indigo-50' : 'hover:bg-gray-50'
                      }`}
                    >
                      <td className="pl-3 pr-1 py-3 text-gray-400 text-center">
                        <svg className={`h-3.5 w-3.5 mx-auto transition-transform ${expandedId === log.id ? 'rotate-90' : ''}`}
                          fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
                          <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
                        </svg>
                      </td>

                      <td className="px-4 py-3">
                        <SeverityBadge severity={(log.severity ?? 'info') as AuditSeverity} showLabel={false} />
                      </td>

                      <td className="px-4 py-3 text-gray-500 whitespace-nowrap">
                        <span className="text-xs" title={formatDateTime(log.created_at)}>
                          {timeAgo(log.created_at)}
                        </span>
                      </td>

                      <td className="px-4 py-3 max-w-xs">
                        <div className="space-y-0.5">
                          <p className="text-xs text-gray-800 truncate" title={log.human_description ?? log.action}>
                            {log.human_description ?? log.action}
                          </p>
                          <ActionBadge action={log.action} />
                        </div>
                      </td>

                      <td className="px-4 py-3">
                        <EntityLink type={log.target_type} id={log.target_id} />
                      </td>

                      <td className="px-4 py-3 text-xs text-gray-600">
                        {log.admin_user
                          ? <span title={log.admin_user.email}>{log.admin_user.name}</span>
                          : <span className="text-gray-300">System</span>
                        }
                      </td>

                      <td className="px-4 py-3">
                        {log.status === 'success' ? (
                          <span className="inline-flex items-center gap-1 text-green-700 text-xs font-medium">
                            <span className="h-1.5 w-1.5 rounded-full bg-green-500" /> ok
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 text-red-600 text-xs font-medium">
                            <span className="h-1.5 w-1.5 rounded-full bg-red-500" /> failed
                          </span>
                        )}
                      </td>
                    </tr>

                    {expandedId === log.id && (
                      <ExpandedRow key={`${log.id}-exp`} log={log} />
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
            <span>Page {meta.page} of {meta.last_page} · {meta.total.toLocaleString()} events</span>
            <div className="flex gap-2">
              <button onClick={() => setPage(p => Math.max(1, p - 1))} disabled={meta.page <= 1}
                className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs disabled:opacity-40 hover:bg-gray-50">← Prev</button>
              <button onClick={() => setPage(p => Math.min(meta.last_page, p + 1))} disabled={meta.page >= meta.last_page}
                className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs disabled:opacity-40 hover:bg-gray-50">Next →</button>
            </div>
          </div>
        )}
      </div>
    </div>
  )
}

import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { getAuditStats } from '@/lib/admin-api/audit'
import { SeverityBadge } from '@/components/audit/SeverityBadge'
import type { AuditSeverity } from '@/types/admin'

function formatTime(d: string) {
  return new Date(d).toLocaleString('en-US', {
    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

function StatCard({ label, value, color, icon }: {
  label: string; value: number | string; color?: string; icon: string
}) {
  return (
    <div className="bg-white rounded-xl border border-gray-200 p-5 flex items-start gap-4">
      <div className="text-2xl flex-shrink-0">{icon}</div>
      <div>
        <p className="text-xs text-gray-500 uppercase tracking-wide">{label}</p>
        <p className={`text-3xl font-bold mt-1 ${color ?? 'text-gray-900'}`}>{value}</p>
      </div>
    </div>
  )
}

function MiniBar({ value, max, label }: { value: number; max: number; label: string }) {
  const pct = max > 0 ? Math.round((value / max) * 100) : 0
  return (
    <div className="flex items-center gap-2">
      <span className="w-32 text-xs text-gray-500 truncate" title={label}>{label}</span>
      <div className="flex-1 h-4 bg-gray-100 rounded-full overflow-hidden">
        <div className="h-full bg-brand-500 rounded-full" style={{ width: `${pct}%` }} />
      </div>
      <span className="w-8 text-right text-xs font-semibold text-gray-700">{value}</span>
    </div>
  )
}

export default function ObservabilityPage() {
  const { data: stats, isLoading, isError, refetch } = useQuery({
    queryKey: ['admin', 'observability', 'stats'],
    queryFn: getAuditStats,
    staleTime: 60_000,
    refetchInterval: 60_000,
  })

  if (isLoading) {
    return (
      <div className="p-12 flex justify-center">
        <div className="h-6 w-6 animate-spin rounded-full border-2 border-brand-600 border-t-transparent" />
      </div>
    )
  }

  if (isError || !stats) {
    return (
      <div className="p-6 text-center text-red-500 text-sm">
        Failed to load observability data.{' '}
        <button onClick={() => refetch()} className="underline">Retry</button>
      </div>
    )
  }

  const failureRate = stats.today_total > 0
    ? ((stats.today_failures / stats.today_total) * 100).toFixed(1)
    : '0.0'

  const maxActionCount  = Math.max(...stats.top_actions.map(a => a.count), 1)
  const maxHourlyCount  = Math.max(...stats.hourly_volume.map(h => h.count), 1)

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold text-gray-900">Observability</h1>
          <p className="text-sm text-gray-500 mt-0.5">Platform health — today's activity</p>
        </div>
        <div className="flex items-center gap-3">
          <span className="text-xs text-gray-400">Auto-refreshes every 60s</span>
          <button onClick={() => refetch()}
            className="rounded-lg border border-gray-300 px-3 py-2 text-xs text-gray-600 hover:bg-gray-50">
            Refresh
          </button>
          <Link to="/audit"
            className="rounded-lg bg-brand-600 px-3 py-2 text-xs font-medium text-white hover:bg-brand-700">
            Full Audit Log →
          </Link>
        </div>
      </div>

      {/* KPI row */}
      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <StatCard label="Events Today"    value={stats.today_total}   icon="📊" />
        <StatCard label="Failures Today"  value={stats.today_failures} icon="❌" color="text-red-600" />
        <StatCard label="Critical Events" value={stats.today_critical} icon="🚨" color="text-red-700" />
        <StatCard label="Failure Rate"    value={`${failureRate}%`}    icon="📉"
          color={parseFloat(failureRate) > 5 ? 'text-red-600' : 'text-green-600'} />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        {/* Top Actions */}
        <div className="bg-white rounded-xl border border-gray-200 p-5">
          <h2 className="text-sm font-semibold text-gray-700 mb-4">Top Actions Today</h2>
          {stats.top_actions.length === 0 ? (
            <p className="text-sm text-gray-400 italic">No events today.</p>
          ) : (
            <div className="space-y-2">
              {stats.top_actions.map(a => (
                <MiniBar key={a.action} label={a.action} value={a.count} max={maxActionCount} />
              ))}
            </div>
          )}
        </div>

        {/* Hourly volume bar chart */}
        <div className="bg-white rounded-xl border border-gray-200 p-5">
          <h2 className="text-sm font-semibold text-gray-700 mb-4">Hourly Volume (today)</h2>
          {stats.hourly_volume.length === 0 ? (
            <p className="text-sm text-gray-400 italic">No events today.</p>
          ) : (
            <div className="flex items-end gap-0.5 h-24">
              {Array.from({ length: 24 }, (_, i) => {
                const entry = stats.hourly_volume.find(h => h.hour === i)
                const count = entry?.count ?? 0
                const pct = maxHourlyCount > 0 ? (count / maxHourlyCount) * 100 : 0
                return (
                  <div key={i} className="flex-1 flex flex-col items-center justify-end gap-0.5"
                    title={`${i}:00 — ${count} events`}>
                    <div
                      className={`w-full rounded-sm ${count > 0 ? 'bg-brand-400' : 'bg-gray-100'}`}
                      style={{ height: `${Math.max(pct, count > 0 ? 4 : 2)}%` }}
                    />
                    {i % 6 === 0 && (
                      <span className="text-[9px] text-gray-400">{i}h</span>
                    )}
                  </div>
                )
              })}
            </div>
          )}
        </div>
      </div>

      {/* Recent Failures */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div className="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
          <h2 className="text-sm font-semibold text-gray-700">Recent Failures</h2>
          <Link to="/audit?status=failure" className="text-xs text-brand-600 hover:underline">
            View all failures →
          </Link>
        </div>
        {stats.recent_failures.length === 0 ? (
          <div className="px-5 py-8 text-center text-sm text-gray-400 italic">
            No recent failures — system is healthy ✓
          </div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="px-4 py-2.5 text-left text-xs font-semibold text-gray-600">Severity</th>
                <th className="px-4 py-2.5 text-left text-xs font-semibold text-gray-600">Time</th>
                <th className="px-4 py-2.5 text-left text-xs font-semibold text-gray-600">Description</th>
                <th className="px-4 py-2.5 text-left text-xs font-semibold text-gray-600">Admin</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {stats.recent_failures.map(log => (
                <tr key={log.id} className="hover:bg-gray-50">
                  <td className="px-4 py-2.5">
                    <SeverityBadge severity={(log.severity ?? 'critical') as AuditSeverity} />
                  </td>
                  <td className="px-4 py-2.5 text-xs text-gray-500 whitespace-nowrap">
                    {formatTime(log.created_at)}
                  </td>
                  <td className="px-4 py-2.5 text-xs text-gray-800 max-w-xs truncate">
                    {log.human_description ?? log.action}
                  </td>
                  <td className="px-4 py-2.5 text-xs text-gray-500">
                    {log.admin_user?.name ?? '—'}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
    </div>
  )
}

import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { getAuditLogs } from '@/lib/admin-api/audit'
import { SeverityBadge } from './SeverityBadge'
import type { AuditSeverity } from '@/types/admin'

interface ActivityFeedProps {
  targetType: string
  targetId: string
  limit?: number
}

function timeAgo(dateStr: string): string {
  const diff = Date.now() - new Date(dateStr).getTime()
  const mins  = Math.floor(diff / 60_000)
  const hours = Math.floor(diff / 3_600_000)
  const days  = Math.floor(diff / 86_400_000)
  if (mins < 1)   return 'just now'
  if (mins < 60)  return `${mins}m ago`
  if (hours < 24) return `${hours}h ago`
  return `${days}d ago`
}

export function ActivityFeed({ targetType, targetId, limit = 10 }: ActivityFeedProps) {
  const { data, isLoading } = useQuery({
    queryKey: ['admin', 'audit-logs', 'feed', targetType, targetId],
    queryFn: () => getAuditLogs({ target_type: targetType, target_id: targetId, per_page: limit }),
    staleTime: 30_000,
  })

  const logs = data?.data ?? []

  if (isLoading) {
    return (
      <div className="py-6 flex justify-center">
        <div className="h-4 w-4 animate-spin rounded-full border-2 border-brand-600 border-t-transparent" />
      </div>
    )
  }

  if (logs.length === 0) {
    return (
      <p className="text-sm text-gray-400 py-4 text-center italic">
        No activity recorded for this entity.
      </p>
    )
  }

  return (
    <div className="space-y-0 divide-y divide-gray-100">
      {logs.map(log => (
        <div key={log.id} className="flex items-start gap-3 py-3">
          <div className="mt-1 flex-shrink-0">
            <SeverityBadge severity={(log.severity ?? 'info') as AuditSeverity} showLabel={false} />
          </div>

          <div className="flex-1 min-w-0">
            <p className="text-sm text-gray-800 leading-snug">
              {log.human_description ?? log.action}
            </p>
            <div className="flex items-center gap-2 mt-0.5 text-xs text-gray-400">
              <span>{timeAgo(log.created_at)}</span>
              {log.admin_user && (
                <>
                  <span>·</span>
                  <span>{log.admin_user.name}</span>
                </>
              )}
              {log.status === 'failure' && (
                <>
                  <span>·</span>
                  <span className="text-red-500 font-medium">failed</span>
                </>
              )}
            </div>
          </div>

          <Link
            to={`/audit?target_type=${targetType}&target_id=${targetId}`}
            className="flex-shrink-0 text-xs text-brand-500 hover:underline"
            title="View in audit log"
          >
            →
          </Link>
        </div>
      ))}

      <div className="pt-3">
        <Link
          to={`/audit?target_type=${targetType}&target_id=${targetId}`}
          className="text-xs text-brand-600 hover:underline"
        >
          View full audit history →
        </Link>
      </div>
    </div>
  )
}

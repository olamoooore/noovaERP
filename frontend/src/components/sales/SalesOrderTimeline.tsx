import { useQuery } from '@tanstack/react-query'
import { Icon } from '@iconify/react'

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import chatterApi from '@/lib/chatterApi'
import { getSalesOrderStatusMeta } from '@/lib/salesOrderStatus'
import { formatDateTime } from '@/lib/utils'

export interface SalesOrderStatusHistoryEntry {
  id: number
  from_status?: string | null
  to_status: string
  actor_id?: number | null
  actor?: { id: number; name?: string | null } | null
  actor_role?: string | null
  source?: string | null
  reason?: string | null
  transitioned_at?: string | null
  created_at?: string | null
}

interface TimelineEntry {
  key: string
  at: string
  kind: 'status' | 'log' | 'message' | 'activity'
  title: string
  detail?: string | null
  actor?: string | null
}

const KIND_ICON: Record<TimelineEntry['kind'], string> = {
  status: 'solar:round-transfer-horizontal-bold-duotone',
  log: 'solar:pen-bold-duotone',
  message: 'solar:chat-round-line-bold-duotone',
  activity: 'solar:calendar-mark-bold-duotone',
}

const timeOf = (value: string) => {
  const ms = new Date(value).getTime()
  return Number.isFinite(ms) ? ms : 0
}

/**
 * #404 (SU6): inline timeline on the order page — every status transition
 * (who, when, why) from the order's status history, merged with the
 * document's chatter entries (field changes, messages, activities), newest
 * first. Chatter `status_changed` logs are skipped because the status history
 * is the authoritative record of the same transitions.
 */
export function SalesOrderTimeline({
  subjectType,
  subjectId,
  statusHistory,
}: {
  subjectType: string
  subjectId: string | number
  statusHistory: SalesOrderStatusHistoryEntry[]
}) {
  // Same key as ChatterPanel so the dialog and the timeline share one cache.
  const chatter = useQuery({
    queryKey: ['chatter', subjectType, subjectId],
    queryFn: () => chatterApi.list(subjectType, subjectId),
  })

  const entries: TimelineEntry[] = []

  for (const row of statusHistory) {
    const at = row.transitioned_at ?? row.created_at
    if (!at) continue
    const to = getSalesOrderStatusMeta(row.to_status).label
    const from = row.from_status ? getSalesOrderStatusMeta(row.from_status).label : null
    entries.push({
      key: `status-${row.id}`,
      at,
      kind: 'status',
      title: from ? `${from} → ${to}` : `Status set to ${to}`,
      detail: row.reason,
      actor: row.actor?.name ?? null,
    })
  }

  const bundle = chatter.data
  for (const log of bundle?.logs ?? []) {
    if (log.event === 'status_changed') continue
    entries.push({ key: `log-${log.id}`, at: log.created_at, kind: 'log', title: log.description, actor: log.actor?.name })
  }
  for (const message of bundle?.messages ?? []) {
    entries.push({ key: `message-${message.id}`, at: message.created_at, kind: 'message', title: message.body, actor: message.actor?.name })
  }
  for (const activity of bundle?.activities ?? []) {
    entries.push({
      key: `activity-${activity.id}`,
      at: activity.created_at,
      kind: 'activity',
      title: activity.summary,
      detail: activity.note,
      actor: activity.actor?.name,
    })
  }

  entries.sort((a, b) => timeOf(b.at) - timeOf(a.at))

  return (
    <Card data-testid="sales-order-timeline">
      <CardHeader>
        <CardTitle className="text-sm">History</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {chatter.isError && (
          <p className="text-xs text-destructive">
            The activity feed could not be loaded — showing status changes only.
          </p>
        )}
        {entries.length === 0 ? (
          <p className="text-sm text-muted-foreground">{chatter.isLoading ? 'Loading history…' : 'No history yet.'}</p>
        ) : (
          <ol className="space-y-3">
            {entries.map((entry) => (
              <li key={entry.key} data-testid="timeline-entry" className="flex gap-3">
                <Icon icon={KIND_ICON[entry.kind]} className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                <div className="min-w-0 text-sm">
                  <div className={entry.kind === 'status' ? 'font-medium' : undefined}>{entry.title}</div>
                  {entry.detail && (
                    <div className="text-muted-foreground">
                      {entry.kind === 'status' && <span>Reason: </span>}
                      <span>{entry.detail}</span>
                    </div>
                  )}
                  <div className="text-xs text-muted-foreground">
                    {[entry.actor || 'System', formatDateTime(entry.at)].join(' · ')}
                  </div>
                </div>
              </li>
            ))}
          </ol>
        )}
      </CardContent>
    </Card>
  )
}

export default SalesOrderTimeline
